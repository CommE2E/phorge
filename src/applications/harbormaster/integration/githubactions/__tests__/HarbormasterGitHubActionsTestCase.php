<?php

final class HarbormasterGitHubActionsTestCase
  extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  public function testRepositoryURIParsing() {
    $map = array(
      array(
        'https://github.com/example/repository.git',
        array(
          'owner' => 'example',
          'name' => 'repository',
          'fullName' => 'example/repository',
        ),
      ),
      array(
        'ssh://git@github.com/example/repository.git',
        array(
          'owner' => 'example',
          'name' => 'repository',
          'fullName' => 'example/repository',
        ),
      ),
      array(
        'git@github.com:example/repository.git',
        array(
          'owner' => 'example',
          'name' => 'repository',
          'fullName' => 'example/repository',
        ),
      ),
      array(
        'https://example.com/example/repository.git',
        null,
      ),
      array(
        'https://github.com/example',
        null,
      ),
    );

    foreach ($map as $spec) {
      list($uri, $expect) = $spec;

      $this->assertEqual(
        $expect,
        HarbormasterGitHubActionsRepositoryURI::newRepositoryDetailsFromURI(
          $uri),
        $uri);
    }
  }

  public function testDispatchHelpers() {
    $target = id(new HarbormasterBuildTarget())
      ->setPHID(
        PhabricatorPHID::generateNewPHID(
          HarbormasterBuildTargetPHIDType::TYPECONST));

    $object = id(new HarbormasterGitHubActionsTestBuildable())
      ->setRepositoryURI('git@github.com:example/repository.git')
      ->setBaseRef('refs/tags/phabricator/base/19657')
      ->setDiffRef('refs/tags/phabricator/diff/19657')
      ->setDiffID(19657)
      ->setRevisionID(8123);

    $this->assertEqual(
      'https://api.github.com/repos/example/repository/actions/'.
      'workflows/.github%2Fworkflows%2Fci.yml/dispatches',
      HarbormasterGitHubActionsBuildStepImplementation::newDispatchURI(
        'example',
        'repository',
        '.github/workflows/ci.yml'));

    $this->assertEqual(
      array(
        'ref' => 'main',
        'inputs' => array(
          'harbormaster_build_target_phid' => $target->getPHID(),
          'phabricator_diff_id' => '19657',
          'phabricator_revision_id' => '8123',
          'staging_base_ref' => 'refs/tags/phabricator/base/19657',
          'staging_diff_ref' => 'refs/tags/phabricator/diff/19657',
        ),
      ),
      HarbormasterGitHubActionsBuildStepImplementation::newDispatchPayload(
        'main',
        $object,
        $target));

    $this->assertEqual(
      '12345',
      HarbormasterGitHubActionsBuildStepImplementation::getRunIDFromDispatchResponse(
        array(
          'workflow_run_id' => 12345,
          'workflow_run_html_url' =>
            'https://github.com/example/repository/actions/runs/12345',
        )));

    $this->assertEqual(
      'https://github.com/example/repository/actions/runs/67890',
      HarbormasterGitHubActionsBuildStepImplementation::getRunURIFromDispatchResponse(
        array(
          'id' => 67890,
          'html_url' =>
            'https://github.com/example/repository/actions/runs/67890',
        )));
  }

  public function testWebhookHelpers() {
    $raw_body = '{"action":"completed"}';
    $secret = 'topsecret';

    $this->assertEqual(
      'sha256='.hash_hmac('sha256', $raw_body, $secret),
      HarbormasterGitHubActionsHookHandler::newWebhookSignature(
        $raw_body,
        $secret));

    $this->assertEqual(
      HarbormasterMessageType::MESSAGE_PASS,
      HarbormasterGitHubActionsHookHandler::getMessageTypeForConclusion(
        'success'));

    $this->assertEqual(
      HarbormasterMessageType::MESSAGE_FAIL,
      HarbormasterGitHubActionsHookHandler::getMessageTypeForConclusion(
        'failure'));

    $build = HarbormasterBuild::initializeNewBuild(
      PhabricatorUser::getOmnipotentUser())
      ->setBuildGeneration(7);

    $target = id(new HarbormasterBuildTarget())
      ->setTargetStatus(HarbormasterBuildTarget::STATUS_WAITING)
      ->setBuildGeneration(7)
      ->attachBuild($build);

    $this->assertFalse(
      HarbormasterGitHubActionsHookHandler::shouldIgnoreTarget($target));

    $target->setTargetStatus(HarbormasterBuildTarget::STATUS_PASSED);
    $this->assertTrue(
      HarbormasterGitHubActionsHookHandler::shouldIgnoreTarget($target));

    $target->setTargetStatus(HarbormasterBuildTarget::STATUS_WAITING);
    $target->setBuildGeneration(6);
    $this->assertTrue(
      HarbormasterGitHubActionsHookHandler::shouldIgnoreTarget($target));
  }

  public function testExternalRunQuery() {
    $viewer = PhabricatorUser::getOmnipotentUser();
    $build = $this->newTestBuild();

    $target = $this->newTestTarget($build)
      ->setExternalSystem(
        HarbormasterGitHubActionsBuildStepImplementation::EXTERNAL_SYSTEM)
      ->setExternalID('12345')
      ->save();

    $this->newTestTarget($build)
      ->setExternalSystem('buildkite')
      ->setExternalID('12345')
      ->save();

    $this->newTestTarget($build)
      ->setExternalSystem(
        HarbormasterGitHubActionsBuildStepImplementation::EXTERNAL_SYSTEM)
      ->setExternalID('99999')
      ->save();

    $actual = id(new HarbormasterBuildTargetQuery())
      ->setViewer($viewer)
      ->withExternalSystem(
        HarbormasterGitHubActionsBuildStepImplementation::EXTERNAL_SYSTEM)
      ->withExternalIDs(array('12345'))
      ->execute();

    $this->assertEqual(
      array($target->getPHID()),
      mpull($actual, 'getPHID'));
  }

  private function newTestBuild() {
    return HarbormasterBuild::initializeNewBuild(
      PhabricatorUser::getOmnipotentUser())
      ->setBuildablePHID(
        PhabricatorPHID::generateNewPHID(
          HarbormasterBuildablePHIDType::TYPECONST))
      ->setBuildPlanPHID(
        PhabricatorPHID::generateNewPHID(
          HarbormasterBuildPlanPHIDType::TYPECONST))
      ->save();
  }

  private function newTestTarget(HarbormasterBuild $build) {
    return id(new HarbormasterBuildTarget())
      ->setName(pht('Target'))
      ->setBuildPHID($build->getPHID())
      ->setBuildStepPHID(
        PhabricatorPHID::generateNewPHID(
          HarbormasterBuildStepPHIDType::TYPECONST))
      ->setClassName('UnitTestBuildStep')
      ->setDetails(array())
      ->setVariables(array())
      ->setTargetStatus(HarbormasterBuildTarget::STATUS_WAITING)
      ->setBuildGeneration($build->getBuildGeneration());
  }

}

final class HarbormasterGitHubActionsTestBuildable
  extends Phobject
  implements HarbormasterGitHubActionsBuildableInterface {

  private $repositoryURI;
  private $baseRef;
  private $diffRef;
  private $diffID;
  private $revisionID;

  public function setRepositoryURI($repository_uri) {
    $this->repositoryURI = $repository_uri;
    return $this;
  }

  public function setBaseRef($base_ref) {
    $this->baseRef = $base_ref;
    return $this;
  }

  public function setDiffRef($diff_ref) {
    $this->diffRef = $diff_ref;
    return $this;
  }

  public function setDiffID($diff_id) {
    $this->diffID = $diff_id;
    return $this;
  }

  public function setRevisionID($revision_id) {
    $this->revisionID = $revision_id;
    return $this;
  }

  public function getGitHubActionsRepositoryURI() {
    return $this->repositoryURI;
  }

  public function getGitHubActionsBaseRef() {
    return $this->baseRef;
  }

  public function getGitHubActionsDiffRef() {
    return $this->diffRef;
  }

  public function getGitHubActionsDiffID() {
    return $this->diffID;
  }

  public function getGitHubActionsRevisionID() {
    return $this->revisionID;
  }

}
