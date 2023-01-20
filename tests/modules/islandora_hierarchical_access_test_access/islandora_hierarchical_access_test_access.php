<?php

/**
 * @file
 * Test module for probing access control.
 */

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\Tests\islandora_hierarchical_access\Kernel\AccessControlTest;

/**
 * Implements hook_entity_access().
 */
function islandora_hierarchical_access_test_access_entity_access(EntityInterface $entity, string $op, AccountInterface $account) {
  $settings = Settings::get(AccessControlTest::KEY) + [
    'entities' => [],
    'ops' => [],
    'accounts' => [],
  ];
  return AccessResult::forbiddenIf(
    in_array($entity, $settings['entities']) ||
    in_array($op, $settings['ops']) ||
    in_array($account, $settings['accounts']), "Denied in testing module.");

}
