<?php

final class HarbormasterGitHubActionsRepositoryURI
  extends Phobject {

  public static function newRepositoryDetailsFromURI($uri) {
    $uri = new PhutilURI($uri);
    $domain = phutil_utf8_strtolower($uri->getDomain());

    switch ($domain) {
      case 'github.com':
      case 'www.github.com':
        break;
      default:
        return null;
    }

    $path = trim($uri->getPath(), '/');
    if (!strlen($path)) {
      return null;
    }

    $parts = explode('/', $path);
    if (count($parts) < 2) {
      return null;
    }

    $owner = $parts[0];
    $name = preg_replace('(\.git$)', '', $parts[1]);
    if (!strlen($owner) || !strlen($name)) {
      return null;
    }

    return array(
      'owner' => $owner,
      'name' => $name,
      'fullName' => "{$owner}/{$name}",
    );
  }

}
