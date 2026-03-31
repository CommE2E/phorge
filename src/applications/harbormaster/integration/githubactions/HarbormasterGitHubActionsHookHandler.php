<?php

final class HarbormasterGitHubActionsHookHandler
  extends HarbormasterHookHandler {

  /**
   * @phutil-external-symbol class PhabricatorStartup
   */
  public function getName() {
    return 'githubactions';
  }

  public static function getMessageTypeForConclusion($conclusion) {
    switch ($conclusion) {
      case 'success':
        return HarbormasterMessageType::MESSAGE_PASS;
      case 'failure':
      case 'cancelled':
      case 'timed_out':
      case 'action_required':
      case 'neutral':
      case 'skipped':
      case 'stale':
        return HarbormasterMessageType::MESSAGE_FAIL;
    }

    throw new Exception(
      pht(
        'GitHub Actions webhook reported unsupported workflow conclusion '.
        '"%s".',
        $conclusion));
  }

  public static function newWebhookSignature($raw_body, $secret) {
    return 'sha256='.PhabricatorHash::digestHMACSHA256($raw_body, $secret);
  }

  public static function shouldIgnoreTarget(HarbormasterBuildTarget $target) {
    if ($target->isComplete()) {
      return true;
    }

    $build = $target->getBuild();
    if ($target->getBuildGeneration() !== $build->getBuildGeneration()) {
      return true;
    }

    return false;
  }

  public function handleRequest(AphrontRequest $request) {
    $raw_body = PhabricatorStartup::getRawInput();
    $body = phutil_json_decode($raw_body);

    $event = $request->getHTTPHeader('X-GitHub-Event');
    if ($event !== 'workflow_run') {
      return $this->newHookResponse(pht('OK: Ignored event.'));
    }

    $action = idx($body, 'action');
    if ($action !== 'completed') {
      return $this->newHookResponse(pht('OK: Ignored action.'));
    }

    $workflow_run = idx($body, 'workflow_run');
    if (!is_array($workflow_run)) {
      throw new Exception(
        pht(
          'Expected "%s" property to contain a dictionary.',
          'workflow_run'));
    }

    $run_id = idx($workflow_run, 'id');
    if ($run_id === null || !strlen((string)$run_id)) {
      throw new Exception(
        pht(
          'GitHub Actions webhook payload is missing a workflow run ID.'));
    }

    $viewer = PhabricatorUser::getOmnipotentUser();
    $target = id(new HarbormasterBuildTargetQuery())
      ->setViewer($viewer)
      ->withExternalSystem(
        HarbormasterGitHubActionsBuildStepImplementation::EXTERNAL_SYSTEM)
      ->withExternalIDs(array((string)$run_id))
      ->needBuildSteps(true)
      ->executeOne();
    if (!$target) {
      return $this->newHookResponse(pht('OK: Unknown workflow run.'));
    }

    $step = $target->getBuildStep();
    $impl = $step->getStepImplementation();
    if (!($impl instanceof HarbormasterGitHubActionsBuildStepImplementation)) {
      throw new Exception(
        pht(
          'Harbormaster build target "%s" is not a GitHub Actions build '.
          'step. Only GitHub Actions steps may be updated via this hook.',
          $target->getPHID()));
    }

    $request_signature = $request->getHTTPHeader('X-Hub-Signature-256');
    $expected_signature = self::newWebhookSignature(
      $raw_body,
      $impl->getSetting('webhook.secret'));
    if (!phutil_hashes_are_identical($request_signature, $expected_signature)) {
      throw new Exception(
        pht(
          'GitHub Actions request to target "%s" had an invalid webhook '.
          'signature. Configure the GitHub webhook secret to match the '.
          'Harbormaster build step.',
          $target->getPHID()));
    }

    if (self::shouldIgnoreTarget($target)) {
      if ($target->isComplete()) {
        return $this->newHookResponse(pht('OK: Target already completed.'));
      }

      return $this->newHookResponse(pht('OK: Ignored obsolete workflow run.'));
    }

    $conclusion = idx($workflow_run, 'conclusion');
    if (!is_string($conclusion) || !strlen($conclusion)) {
      throw new Exception(
        pht(
          'GitHub Actions webhook payload is missing a workflow run '.
          'conclusion for completed run "%s".',
          $run_id));
    }

    $html_uri = idx($workflow_run, 'html_url');

    $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();

    if ($html_uri) {
      HarbormasterGitHubActionsBuildStepImplementation::upsertURIArtifact(
        $viewer,
        $target,
        $html_uri);
    }

    $api_method = 'harbormaster.sendmessage';
    $api_params = array(
      'buildTargetPHID' => $target->getPHID(),
      'type' => self::getMessageTypeForConclusion($conclusion),
    );

    id(new ConduitCall($api_method, $api_params))
      ->setUser($viewer)
      ->execute();

    unset($unguarded);

    return $this->newHookResponse(pht('OK: Processed event.'));
  }

  private function newHookResponse($message) {
    $response = new AphrontWebpageResponse();
    $response->setContent($message);
    return $response;
  }

}
