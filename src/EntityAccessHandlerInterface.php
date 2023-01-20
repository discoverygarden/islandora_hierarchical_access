<?php

namespace Drupal\islandora_hierarchical_access;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Interface for performing access checks.
 */
interface EntityAccessHandlerInterface extends EntityHandlerInterface {

  /**
   * Perform the access check.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being checked for access.
   * @param string $operation
   *   The operation being checked for access.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account wanting to perform the operation.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result object.
   */
  public function check(EntityInterface $entity, string $operation, AccountInterface $account) : AccessResultInterface;

}
