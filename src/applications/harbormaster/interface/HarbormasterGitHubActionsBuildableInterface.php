<?php

/**
 * Support for GitHub Actions revision builds.
 */
interface HarbormasterGitHubActionsBuildableInterface {

  public function getGitHubActionsRepositoryURI();
  public function getGitHubActionsBaseRef();
  public function getGitHubActionsDiffRef();
  public function getGitHubActionsDiffID();
  public function getGitHubActionsRevisionID();

}
