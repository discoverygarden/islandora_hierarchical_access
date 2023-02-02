<?php

namespace Drupal\islandora_hierarchical_access;

use Drupal\Core\Database\Connection;

/**
 * Lookup table helper functions.
 */
class LUTHelper {

  /**
   * The database service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Constructor.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * Returns the corresponding referenced fields for a media, node or file.
   */
  public function lookUpFields($referencedFieldName, $condition): array {
    $query = $this->database->select(LUTGeneratorInterface::TABLE_NAME, 'lut');
    $query->fields('lut', [$referencedFieldName]);
    $query->condition($condition['field'], $condition['value']);
    try {
      return $query->execute()->fetchCol();
    }
    catch (\Exception $e) {
      throw $e;
    }
  }

}
