<?php

namespace Drupal\islandora_hierarchical_access;

use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Interface around handling CUD events for entities.
 */
interface EntityCUDHandlerInterface extends EntityHandlerInterface {

  /**
   * Handle entity insertion/creation.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to handle.
   */
  public function create(EntityInterface $entity) : void;

  /**
   * Handle entity updates.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to handle.
   */
  public function update(EntityInterface $entity) : void;

  /**
   * Handle entity deletion.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to handle.
   */
  public function delete(EntityInterface $entity) : void;

}
