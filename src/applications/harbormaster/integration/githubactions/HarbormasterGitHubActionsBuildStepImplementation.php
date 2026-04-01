<?php

final class HarbormasterGitHubActionsBuildStepImplementation
  extends HarbormasterBuildStepImplementation {

  const API_VERSION = '2026-03-10';
  const EXTERNAL_SYSTEM = 'github.actions';
  const URI_ARTIFACT_KEY = 'github-actions.uri';
  const DISPATCH_COLLISION_YIELD = 2;
  const DISPATCH_SPACING_DELAY = 1;

  public function getName() {
    return pht('Build with GitHub Actions');
  }

  public function getGenericDescription() {
    return pht('Trigger a revision build in GitHub Actions.');
  }

  public function getBuildStepGroupKey() {
    return HarbormasterExternalBuildStepGroup::GROUPKEY;
  }

  public function getDescription() {
    return pht('Run a revision build in GitHub Actions.');
  }

  public function getEditInstructions() {
    $hook_uri = '/harbormaster/hook/githubactions/';
    $hook_uri = PhabricatorEnv::getProductionURI($hook_uri);

    return pht(<<<EOTEXT
To build **revisions** with GitHub Actions, they must:

  - belong to a tracked repository;
  - the repository must have a Staging Area configured;
  - the Staging Area must be hosted on GitHub; and
  - you must configure both the workflow dispatch inputs and the webhook
    described below.

This step does **not** support commit builds.

Workflow Configuration
======================

The configured workflow must support `workflow_dispatch` and accept these
inputs:

```lang=yml
on:
  workflow_dispatch:
    inputs:
      harbormaster_build_target_phid:
        required: true
        type: string
      phabricator_diff_id:
        required: true
        type: string
      phabricator_revision_id:
        required: true
        type: string
      staging_base_ref:
        required: true
        type: string
      staging_diff_ref:
        required: true
        type: string

jobs:
  revision-build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          ref: \${{ inputs.staging_diff_ref }}
          fetch-depth: 0
```

Webhook Configuration
=====================

In your GitHub repository settings, add a webhook with these settings:

  - **Payload URL**: %s
  - **Content type**: `application/json`
  - **Secret**: The "Webhook Secret" field below.
  - **Events**: Select only the **Workflow runs** event.

Environment
===========

These variables are passed as workflow inputs:

| Input | Description |
|-------|-------------|
| `harbormaster_build_target_phid` | PHID of the Build Target. |
| `phabricator_diff_id` | Differential diff ID being built. |
| `phabricator_revision_id` | Differential revision ID being built. |
| `staging_base_ref` | Staged base tag for the diff. |
| `staging_diff_ref` | Staged diff tag to check out and build. |
EOTEXT
    ,
    $hook_uri);
  }

  public static function newDispatchURI($owner, $repository, $workflow) {
    return urisprintf(
      'https://api.github.com/repos/%s/%s/actions/workflows/%p/dispatches',
      $owner,
      $repository,
      $workflow);
  }

  public static function newDispatchPayload(
    $workflow_ref,
    HarbormasterGitHubActionsBuildableInterface $object,
    HarbormasterBuildTarget $build_target) {

    return array(
      'ref' => $workflow_ref,
      'inputs' => array(
        'harbormaster_build_target_phid' => $build_target->getPHID(),
        'phabricator_diff_id' => (string)$object->getGitHubActionsDiffID(),
        'phabricator_revision_id' =>
          (string)$object->getGitHubActionsRevisionID(),
        'staging_base_ref' => $object->getGitHubActionsBaseRef(),
        'staging_diff_ref' => $object->getGitHubActionsDiffRef(),
      ),
    );
  }

  public static function getRunIDFromDispatchResponse(array $response) {
    $run_id = idx($response, 'workflow_run_id');
    if ($run_id === null) {
      $run_id = idx($response, 'id');
    }

    if ($run_id === null || !strlen((string)$run_id)) {
      throw new Exception(
        pht(
          'GitHub Actions did not return a workflow run ID in the dispatch '.
          'response.'));
    }

    return (string)$run_id;
  }

  public static function getRunURIFromDispatchResponse(array $response) {
    $uri = idx($response, 'workflow_run_html_url');
    if (!$uri) {
      $uri = idx($response, 'html_url');
    }
    if (!$uri) {
      $uri = idx($response, 'workflow_run_url');
    }
    if (!$uri) {
      $uri = idx($response, 'url');
    }

    if (!$uri) {
      throw new Exception(
        pht(
          'GitHub Actions did not return a workflow run URL in the dispatch '.
          'response.'));
    }

    return $uri;
  }

  public static function newGitHubURIArtifactData($uri) {
    return array(
      'uri' => $uri,
      'name' => pht('View in GitHub Actions'),
      'ui.external' => true,
    );
  }

  public static function shouldRetryDispatchStatus($status_code) {
    return ($status_code >= 500 && $status_code < 600);
  }

  public static function newDispatchLock(array $details) {
    return PhabricatorGlobalLock::newLock(
      'harbormaster.githubactions.dispatch',
      array(
        'owner' => idx($details, 'owner'),
        'repo' => idx($details, 'name'),
      ));
  }

  public static function getDispatchRetryDelay(
    array $headers,
    $default_delay) {

    $retry_after = BaseHTTPFuture::getHeader($headers, 'Retry-After');
    if ($retry_after !== null && is_numeric($retry_after)) {
      return max((int)$retry_after, (int)$default_delay);
    }

    return (int)$default_delay;
  }

  public static function upsertURIArtifact(
    PhabricatorUser $viewer,
    HarbormasterBuildTarget $build_target,
    $uri) {

    $artifact_data = self::newGitHubURIArtifactData($uri);

    try {
      $artifact = $build_target->loadArtifact(self::URI_ARTIFACT_KEY);
      $artifact->setArtifactData($artifact_data)->save();
    } catch (Exception $ex) {
      $build_target->createArtifact(
        $viewer,
        self::URI_ARTIFACT_KEY,
        HarbormasterURIArtifact::ARTIFACTCONST,
        $artifact_data);
    }
  }

  public function execute(
    HarbormasterBuild $build,
    HarbormasterBuildTarget $build_target) {
    $viewer = PhabricatorUser::getOmnipotentUser();

    if (PhabricatorEnv::getEnvConfig('phabricator.silent')) {
      $this->logSilencedCall($build, $build_target, pht('GitHub Actions'));
      throw new HarbormasterBuildFailureException();
    }

    $buildable = $build->getBuildable();
    $object = $buildable->getBuildableObject();
    $object_phid = $object->getPHID();
    if (!($object instanceof HarbormasterGitHubActionsBuildableInterface)) {
      throw new Exception(
        pht(
          'Object ("%s") does not implement interface "%s". Only revision '.
          'objects which implement this interface can be built with GitHub '.
          'Actions.',
          $object_phid,
          'HarbormasterGitHubActionsBuildableInterface'));
    }

    $repository_uri = $object->getGitHubActionsRepositoryURI();
    $details =
      HarbormasterGitHubActionsRepositoryURI::newRepositoryDetailsFromURI(
        $repository_uri);
    if (!$details) {
      throw new Exception(
        pht(
          'Object ("%s") claims "%s" is a GitHub repository URI, but it '.
          'could not be parsed as a GitHub repository.',
          $object_phid,
          $repository_uri));
    }

    $credential_phid = $this->getSetting('token');
    $api_token = id(new PassphraseCredentialQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($credential_phid))
      ->needSecrets(true)
      ->executeOne();
    if (!$api_token) {
      throw new Exception(
        pht(
          'Unable to load API token ("%s")!',
          $credential_phid));
    }

    $uri = self::newDispatchURI(
      $details['owner'],
      $details['name'],
      $this->getSetting('workflow'));

    $payload = self::newDispatchPayload(
      $this->getSetting('workflow.ref'),
      $object,
      $build_target);
    $json_data = phutil_json_encode($payload);

    $token = $api_token->getSecret()->openEnvelope();
    $dispatch_lock = self::newDispatchLock($details);

    try {
      $dispatch_lock->lock();
    } catch (PhutilLockException $ex) {
      throw new PhabricatorWorkerYieldException(
        self::DISPATCH_COLLISION_YIELD);
    }

    $future = null;
    $status = null;
    $body = null;
    $headers = array();
    $attempt_limit = 3;
    $did_attempt_dispatch = false;

    try {
      for ($attempt = 1; $attempt <= $attempt_limit; $attempt++) {
        $future = id(new HTTPSFuture($uri, $json_data))
          ->setMethod('POST')
          ->addHeader('Content-Type', 'application/json')
          ->addHeader('Accept', 'application/vnd.github+json')
          ->addHeader('Authorization', "Bearer {$token}")
          ->addHeader('User-Agent', 'Phorge Harbormaster')
          ->addHeader('X-GitHub-Api-Version', self::API_VERSION)
          ->setTimeout(60);

        $this->resolveFutures(
          $build,
          $build_target,
          array($future));

        $this->logHTTPResponse(
          $build,
          $build_target,
          $future,
          pht('GitHub Actions (Attempt %d)', $attempt));

        list($status, $body, $headers) = $future->resolve();
        $did_attempt_dispatch = true;
        if (!$status->isError()) {
          break;
        }

        if (!self::shouldRetryDispatchStatus($status->getStatusCode())) {
          throw new HarbormasterBuildFailureException();
        }

        if ($attempt < $attempt_limit) {
          sleep(self::getDispatchRetryDelay($headers, $attempt));
        }
      }
    } finally {
      if ($did_attempt_dispatch) {
        sleep(self::DISPATCH_SPACING_DELAY);
      }

      $dispatch_lock->unlock();
    }

    if ($status->isError()) {
      throw new HarbormasterBuildFailureException();
    }

    $response = phutil_json_decode($body);
    $run_id = self::getRunIDFromDispatchResponse($response);
    $run_uri = self::getRunURIFromDispatchResponse($response);

    $build_target
      ->setExternalSystem(self::EXTERNAL_SYSTEM)
      ->setExternalID($run_id)
      ->save();

    self::upsertURIArtifact($viewer, $build_target, $run_uri);
  }

  public function getFieldSpecifications() {
    return array(
      'token' => array(
        'name' => pht('API Token'),
        'type' => 'credential',
        'credential.type'
          => PassphraseTokenCredentialType::CREDENTIAL_TYPE,
        'credential.provides'
          => PassphraseTokenCredentialType::PROVIDES_TYPE,
        'required' => true,
      ),
      'workflow' => array(
        'name' => pht('Workflow Identifier'),
        'type' => 'text',
        'required' => true,
      ),
      'workflow.ref' => array(
        'name' => pht('Workflow Definition Ref'),
        'type' => 'text',
        'required' => true,
      ),
      'webhook.secret' => array(
        'name' => pht('Webhook Secret'),
        'type' => 'text',
        'required' => true,
      ),
    );
  }

  public function supportsWaitForMessage() {
    return false;
  }

  public function shouldWaitForMessage(HarbormasterBuildTarget $target) {
    return true;
  }

}
