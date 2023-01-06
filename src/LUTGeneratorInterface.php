<?php

namespace Drupal\islandora_hierarchical_access;

use Drupal\Core\Entity\EntityInterface;

/**
 * Lookup table generator interface.
 */
interface LUTGeneratorInterface {

  public const TABLE_NAME = 'islandora_hierarchical_access_lut';

  /**
   * Fully regenerate the lookup table.
   */
  public function regenerate(): void;

  /**
   * Generate LUT.
   *
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   The entity from which to base the LUT generation. If not provided, the
   *   LUT will be completely regenerated. If provided, only those rows
   *   resulting from the given entity will be added to the table.
   */
  public function generate(EntityInterface $entity = NULL): void;

}
