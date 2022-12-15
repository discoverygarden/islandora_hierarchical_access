<?php

namespace Drupal\access_control_model;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldConfigInterface;

class LUTGenerator implements LUTGeneratorInterface {

  protected Connection $database;
  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
  }

  protected function getFileFields() : FieldConfigInterface {
    $fields = [];

    $types = $this->entityTypeManager->getStorage('media_type')->loadMultiple();
    foreach ($types as $type) {
      $field = $type->getSource()->getSourceFieldDefinition($type);
      $item_def = $field->getItemDefinition();
      if ($item_def->getSetting('handler') == 'default:file') {
        $fields[] = $field;
      }
    }

    return $fields;
  }

  protected ?array $uniqueFileFields = NULL;
  protected function uniqueFileFields() : array {
    if ($this->uniqueFileFields === NULL) {
      $this->uniqueFileFields = [];
      foreach ($this->getFileFields() as $field) {
        $name = $field->get('field_name');
        if (!in_array($name, $this->uniqueFileFields)) {
          $this->uniqueFileFields[] = $name;
        }
      }
    }

    return $this->uniqueFileFields;
  }

  /**
   * {@inheritDoc}
   */
  public function regenerate() : void {
    $tx = $this->database->startTransaction();
    try {


      $this->database->truncate(static::TABLE_NAME)->execute();
      $this->generate();
    }
    catch (\Exception $e) {
      $tx->rollBack();
      throw $e;
    }
  }

  /**
   * {@inheritDoc}
   */
  public function generate(int $mid = NULL) : void {
    $query = $this->database->select('node', 'n');
    $fmo_alias = $query->join('field_media_of', 'fmo', '%alias.field_media_of_target_id = n.nid');
    $media_alias = $query->join('media', 'm', "%alias.mid = {$fmo_alias}.entity_id");

    if ($mid) {
      $query->condition("{$media_alias}.mid", $mid);
    }

    $aliases = [];
    foreach ($this->uniqueFileFields() as $field) {
      $field_alias = $query->join("media__{$field}", 'mf', "%alias.entity_id = {$media_alias}.mid");
      $aliases[] = "{$field_alias}.{$field}_target_id";
    }
    $file_alias = $query->join('file_managed', 'fm', implode(' OR ', array_map(function ($field_alias) {
      return "%alias.fid = $field_alias";
    }, $aliases)));
    $query->fields('n', ['nid'])
      ->fields($media_alias, ['mid'])
      ->fields($file_alias, ['fid']);

    $this->database->insert(static::TABLE_NAME)->from($query)->execute();
  }
}
