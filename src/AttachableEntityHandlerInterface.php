<?php

namespace Drupal\islandora_hierarchical_access;

use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityTypeInterface;

/**
 * Interface for entity handler that can attach themselves to entity types.
 */
interface AttachableEntityHandlerInterface extends EntityHandlerInterface {

  /**
   * Attach entity access handler to the targets.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   An entity type to which to attempt to attach.
   */
  public static function attach(EntityTypeInterface $entity_type);

}
