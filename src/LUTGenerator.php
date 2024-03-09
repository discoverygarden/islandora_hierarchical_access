<?php

namespace Drupal\islandora_hierarchical_access;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\islandora\IslandoraUtils;
use Drupal\media\MediaInterface;

/**
 * Lookup table generator service implementation.
 */
class LUTGenerator implements LUTGeneratorInterface {

  /**
   * Memoize the list of fields to be considered.
   *
   * @var string[]
   */
  protected array $uniqueFileFields;

  /**
   * Constructor.
   */
  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
  ) {
    // No-op, other than stashing services.
  }

  /**
   * {@inheritDoc}
   */
  public function regenerate(): void {
    $tx = $this->database->startTransaction('lut_regeneration');
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
  public function generate(EntityInterface $entity = NULL): void {
    if (count($this->uniqueFileFields()) === 0) {
      // Did not find any fields over which to generate the LUT so abort the
      // attempt.
      return;
    }

    $query = $this->database->select('node', 'n');
    $fmo = IslandoraUtils::MEDIA_OF_FIELD;
    $fmo_alias = $query->join('media__' . $fmo, 'fmo', "%alias.{$fmo}_target_id = n.nid");
    $media_alias = $query->join('media', 'm',
      "%alias.mid = {$fmo_alias}.entity_id");

    if ($entity) {
      assert($entity instanceof MediaInterface);
      $query->condition("{$media_alias}.mid", $entity->id());
    }

    $aliases = [];
    foreach ($this->uniqueFileFields() as $field) {
      $field_alias = $query->leftJoin("media__{$field}", 'mf',
        "%alias.entity_id = {$media_alias}.mid");
      $aliases[] = "{$field_alias}.{$field}_target_id";
    }
    $file_alias = $query->leftJoin('file_managed', 'fm', strtr('!field IN (!targets)', [
      '!field' => '%alias.fid',
      '!targets' => implode(', ', $aliases),
    ]));

    $query->fields('n', ['nid'])
      ->fields($media_alias, ['mid']);

    // XXX: Rework NULLs from the left join to files to our LUT's default of "0"
    // for things like "remote media" that are not backed by managed file
    // entities.
    $query->addExpression("COALESCE({$file_alias}.fid, 0)", 'fid');

    $this->database->insert(static::TABLE_NAME)->from($query)->execute();
  }

  /**
   * Build out a unique array of the fields to be considered.
   *
   * @return string[]
   *   The fields to be considered.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function uniqueFileFields(): array {
    if (!isset($this->uniqueFileFields)) {
      $this->uniqueFileFields = [];
      foreach ($this->getFileFields() as $field) {
        $name = $field->getName();
        if (!in_array($name, $this->uniqueFileFields)) {
          $this->uniqueFileFields[] = $name;
        }
      }
    }

    return $this->uniqueFileFields;
  }

  /**
   * Generate the file fields to be considered.
   *
   * @phpcs:ignore Drupal.Commenting.FunctionComment.InvalidReturn,Drupal.Commenting.DocComment.SpacingBeforeTags
   * @return iterable<\Drupal\Core\Field\FieldDefinitionInterface>
   *   An iterable of the fields to be considered.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getFileFields() : iterable {
    /** @var \Drupal\media\MediaTypeInterface[] $types */
    $types = $this->entityTypeManager->getStorage('media_type')->loadMultiple();

    foreach ($types as $type) {
      $fields = $this->entityFieldManager->getFieldDefinitions('media', $type->id());
      if (!isset($fields[IslandoraUtils::MEDIA_OF_FIELD])) {
        continue;
      }
      $field = $type->getSource()->getSourceFieldDefinition($type);
      $item_def = $field->getItemDefinition();
      if ($item_def->getSetting('handler') == 'default:file') {
        yield $field;
      }
    }
  }

}
