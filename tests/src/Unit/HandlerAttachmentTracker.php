<?php

namespace Drupal\Tests\islandora_hierarchical_access\Unit;

/**
 * Handler attachment tracking helper.
 */
class HandlerAttachmentTracker {

  /**
   * The originally passed values.
   *
   * @var array[]
   */
  protected array $values;

  /**
   * The originally passed values, rekeyed according to the first parameter.
   *
   * @var array[]
   */
  protected array $hashed;

  /**
   * Values that have been seen and matched.
   *
   * @var array[]
   */
  protected array $tracked = [];

  /**
   * Constructor.
   */
  public function __construct(array $values) {
    $this->values = $values;
    $this->hashed = array_column($this->values, NULL, 0);
  }

  /**
   * Helper; facilitate the assertion that all params have been consumed.
   *
   * @return bool
   *   TRUE if all values passed in the constructor appear to have been
   *   checked in ::matches(); otherwise, FALSE.
   */
  public function isFullyConsumed() : bool {
    return !array_diff_key($this->hashed, $this->tracked);
  }

  /**
   * Track if the given value matches a set of values expected.
   *
   * @param array $other
   *   A set of parameters passed to the `::set()` call.
   */
  public function matches(array $other) {
    $key = $other[0];
    if ($this->hashed[$key] === $other) {
      $this->tracked[$key] = $other;
    }
  }

}
