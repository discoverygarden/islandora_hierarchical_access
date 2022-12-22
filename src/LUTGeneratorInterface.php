<?php

namespace Drupal\islandora_hierarchical_access;

interface LUTGeneratorInterface {

  public const TABLE_NAME = 'islandora_hierarchical_access_lut';

  /**
   * Fully regenerate the lookup table.
   */
  public function regenerate(): void;

  /**
   * Generate LUT.
   *
   * @param int|null $mid
   *   Media ID from which to base the LUT generation. If not provided, the LUT
   *   will be completely regenerated. If provided, only those rows resulting
   *   from the given media ID will be added to the table.
   */
  public function generate(int $mid = NULL): void;

}
