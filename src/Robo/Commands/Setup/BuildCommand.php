<?php

namespace Acquia\Blt\Robo\Commands\Setup;

use Acquia\Blt\Robo\BltTasks;
use Acquia\Blt\Robo\Exceptions\BltException;
use Robo\Contract\VerbosityThresholdInterface;
use Symfony\Component\Finder\Finder;

/**
 * Defines commands in the "setup:build" namespace.
 */
class BuildCommand extends BltTasks {

  /**
   * Install dependencies, builds docroot, installs Drupal.
   *
   * @command setup
   *
   * @aliases setup:all
   */
  public function setup() {
    $this->say("Setting up local environment for site '{$this->getConfigValue('site')}'...");
    $status_code = $this->invokeCommands([
      'setup:build',
      'setup:hash-salt',
      'setup:drupal:install',
      'install-alias',
    ]);
    return $status_code;
  }

  /**
   * Installs Drupal and sets correct file/directory permissions.
   *
   * @command setup:drupal:install
   *
   * @interactGenerateSettingsFiles
   *
   * @validateMySqlAvailable
   * @validateDocrootIsPresent
   *
   * @todo Add a @validateSettingsFilesArePresent
   */
  public function drupalInstall() {
    $commands = ['internal:drupal:install'];
    $strategy = $this->getConfigValue('cm.strategy');
    if ($strategy == 'config-split') {
      $commands[] = 'setup:config-import';
    }

    $status_code = $this->invokeCommands($commands);
    if ($status_code) {
      return $status_code;
    }
    $this->setSitePermissions();

    return $status_code;
  }

  /**
   * Set correct permissions for files and folders in docroot/sites/*.
   */
  protected function setSitePermissions() {
    $taskFilesystemStack = $this->taskFilesystemStack();
    $multisite_dir = $this->getConfigValue('docroot') . '/sites/' . $this->getConfigValue('site');
    $finder = new Finder();
    $dirs = $finder
      ->in($multisite_dir)
      ->directories()
      ->depth('< 1')
      ->exclude('files');
    foreach ($dirs->getIterator() as $dir) {
      $taskFilesystemStack->chmod($dir->getRealPath(), 0755);
    }
    $files = $finder
      ->in($multisite_dir)
      ->files()
      ->depth('< 1')
      ->exclude('files');
    foreach ($files->getIterator() as $dir) {
      $taskFilesystemStack->chmod($dir->getRealPath(), 0644);
    }

    $taskFilesystemStack->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE);
    $result = $taskFilesystemStack->run();

    if (!$result->wasSuccessful()) {
      throw new BltException("Unable to set permissions for site directories.");
    }
  }

  /**
   * Generates all required files for a full build.
   *
   * @command setup:build
   */
  public function build() {
    $status_code = $this->invokeCommands([
      'setup:behat',
      // setup:composer:install must run prior to setup:settings to ensure that
      // scaffold files are present.
      'setup:composer:install',
      'setup:git-hooks',
      'setup:settings',
      // 'frontend'.
    ]);
    if ($status_code) {
      return $status_code;
    }

    if ($this->getConfig()->has('simplesamlphp') && $this->getConfigValue('simplesamlphp')) {
      $this->invokeCommand('simplesamlphp:build:config');
    }

    $exit_code = $this->invokeHook("post-setup-build");

    return $exit_code;
  }

  /**
   * Installs Composer dependencies.
   *
   * @command setup:composer:install
   */
  public function composerInstall() {
    $result = $this->taskExec("export COMPOSER_EXIT_ON_PATCH_FAILURE=1; composer install --ansi --no-interaction")
      ->dir($this->getConfigValue('repo.root'))
      ->detectInteractive()
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
      ->run();

    return $result;
  }

}
