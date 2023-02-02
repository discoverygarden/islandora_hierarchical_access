<?php

namespace Drupal\islandora_hierarchical_access\Access;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\islandora_hierarchical_access\LUTGeneratorInterface;

/**
 * Query tagging to propagate our access control model.
 */
class QueryTagger {

  use QueryConjunctionTrait;

  /**
   * The database connection service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Memoization for generated base media query.
   *
   * @var \Drupal\Core\Database\Query\SelectInterface|null
   */
  protected ?SelectInterface $baseMediaQuery = NULL;

  /**
   * Memoization for generated tagged media query.
   *
   * @var \Drupal\Core\Database\Query\SelectInterface|null
   */
  protected ?SelectInterface $taggedMediaQuery = NULL;

  /**
   * Memoization for the base node query.
   *
   * @var \Drupal\Core\Database\Query\SelectInterface|null
   */
  protected ?SelectInterface $baseNodeQuery = NULL;

  /**
   * Memoization for the tagged node query.
   *
   * @var \Drupal\Core\Database\Query\SelectInterface|null
   */
  protected ?SelectInterface $taggedNodeQuery = NULL;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   Database connection instance.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   Module handler interface.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager interface.
   */
  public function __construct(
    Connection $database,
    ModuleHandlerInterface $module_handler,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->database = $database;
    $this->moduleHandler = $module_handler;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Tag file_access queries.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query to be tagged.
   */
  public function tagFile(SelectInterface $query) : void {
    if ("{$this->getBaseMediaQuery()}" === "{$this->getTaggedMediaQuery()}") {
      // No relevant tagging for which to account.
      return;
    }

    static::conjunctionQuery($query);

    $file_tables = $this->entityTypeManager->getStorage('file')
      ->getTableMapping()
      ->getTableNames();

    $existential_query = $query->andConditionGroup();
    $new_or = $query->orConditionGroup();

    foreach ($query->getTables() as $info) {
      if ($info['table'] instanceof SelectInterface) {
        continue;
      }
      elseif (in_array($info['table'], $file_tables)) {
        $alias = $info['alias'];

        // If this file is _not_ an eligible (media-related) file, then we
        // should not mess with its access; otherwise...
        $existential_query->condition("$alias.fid", $this->getBaseMediaQuery(),
          'NOT IN');

        // ... so if it is still in the set of allowed things with tagging,
        // then we should be fine to allow access.
        $new_or->condition("$alias.fid", $this->getTaggedMediaQuery(), 'IN');
      }
    }
    $new_or->condition($existential_query);

    $query->condition($new_or);
  }

  /**
   * Get the base media query.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The base media query.
   */
  protected function getBaseMediaQuery(): SelectInterface {
    if ($this->baseMediaQuery === NULL) {
      $this->baseMediaQuery = $this->getMediaQuery(FALSE);
    }

    return $this->baseMediaQuery;
  }

  /**
   * Build out the media query.
   *
   * @param bool $tagged
   *   TRUE if the base table should be tagged for access control; otherwise,
   *   FALSE.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The media query.
   */
  protected function getMediaQuery($tagged = FALSE): SelectInterface {
    $query = $this->database->select('media', 'm')
      ->addTag('media_access')
      ->addMetaData('base_table', 'media');

    if ($tagged) {
      // Apply tagging to _just_ the base table, that we will then join against
      // all of the fields.
      $this->moduleHandler->alter('query_media_access', $query);
    }

    $lut_alias = $query->join(LUTGeneratorInterface::TABLE_NAME, 'lut',
      '%alias.mid = m.mid');
    $query->fields($lut_alias, ['fid']);

    return $query;
  }

  /**
   * Get the tagged media query.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The tagged media query.
   */
  protected function getTaggedMediaQuery(): SelectInterface {
    if ($this->taggedMediaQuery === NULL) {
      $this->taggedMediaQuery = $this->getMediaQuery(TRUE);
    }

    return $this->taggedMediaQuery;
  }

  /**
   * Tag media_access queries.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query to be tagged.
   */
  public function tagMedia(SelectInterface $query) : void {
    if ("{$this->getBaseNodeQuery()}" === "{$this->getTaggedNodeQuery()}") {
      // No relevant tagging for which to account.
      return;
    }

    $this->conjunctionQuery($query);

    $media_tables = $this->entityTypeManager->getStorage('media')
      ->getTableMapping()
      ->getTableNames();

    $new_or = $query->orConditionGroup();

    foreach ($query->getTables() as $info) {
      if ($info['table'] instanceof SelectInterface) {
        continue;
      }
      elseif (in_array($info['table'], $media_tables)) {
        $key = (strpos($info['table'], 'media__') === 0) ? 'entity_id' : 'mid';
        $alias = $info['alias'];

        // If this media is _not_ an eligible (node-related) media, then we
        // should not mess with its access; otherwise...
        $new_or->condition("$alias.$key", $this->getBaseNodeQuery(), 'NOT IN');

        // ... if it is still in the set of allowed things with tagging, then
        // we should be fine to allow access.
        $new_or->condition("$alias.$key", $this->getTaggedNodeQuery(), 'IN');
      }
    }

    $query->condition($new_or);
  }

  /**
   * Get the base node query.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The base node query.
   */
  protected function getBaseNodeQuery(): SelectInterface {
    if ($this->baseNodeQuery === NULL) {
      $this->baseNodeQuery = $this->getNodeQuery();
    }

    return $this->baseNodeQuery;
  }

  /**
   * Build out the node query.
   *
   * @param bool $tagged
   *   TRUE if the base table should be tagged for access control; otherwise,
   *   FALSE.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The node query.
   */
  protected function getNodeQuery($tagged = FALSE): SelectInterface {
    $query = $this->database->select('node', 'n')
      ->addTag('node_access')
      ->addMetaData('base_table', 'node');

    if ($tagged) {
      // Apply tagging to _just_ the base table, that we will then join against
      // all of the fields.
      $this->moduleHandler->alter('query_node_access', $query);
    }

    $lut_alias = $query->join(LUTGeneratorInterface::TABLE_NAME, 'lut',
      '%alias.nid = n.nid');
    $query->fields($lut_alias, ['mid']);

    return $query;
  }

  /**
   * Get the tagged node query.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The tagged node query.
   */
  protected function getTaggedNodeQuery(): SelectInterface {
    if ($this->taggedNodeQuery === NULL) {
      $this->taggedNodeQuery = $this->getNodeQuery(TRUE);
    }

    return $this->taggedNodeQuery;
  }

}
