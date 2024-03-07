<?php

namespace Drupal\islandora_hierarchical_access\Access;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\islandora_hierarchical_access\Event\Event;
use Drupal\islandora_hierarchical_access\LUTGeneratorInterface;
use Drupal\islandora_hierarchical_access\TaggedTargetsTrait;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Query tagging to propagate our access control model.
 */
class QueryTagger implements ContainerInjectionInterface {

  use QueryConjunctionTrait;
  use TaggedTargetsTrait;

  /**
   * Constructor.
   */
  public function __construct(
    protected Connection $database,
    protected ModuleHandlerInterface $moduleHandler,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EventDispatcherInterface $eventDispatcher,
  ) {
    // No-op, other than stashing the passed services.
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) : self {
    return new static(
      $container->get('database'),
      $container->get('module_handler'),
      $container->get('entity_type.manager'),
      $container->get('event_dispatcher'),
    );
  }

  /**
   * Apply hierarchical access logic to the query.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query to be altered.
   */
  public function tagQuery(SelectInterface $query) : void {
    $type = $query->getMetaData('islandora_hierarchical_access_tag_type');
    if (!in_array($type, [/*'node',*/ 'media', 'file'])) {
      throw new \InvalidArgumentException("Unrecognized type '$type'.");
    }
    if ($query->hasTag('islandora_hierarchical_access_subquery')) {
      // Avoid further altering when it should already be accounted for
      // internally.
      return;
    }

    static::conjunctionQuery($query);

    /** @var \Drupal\Core\Entity\Sql\SqlEntityStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage($type);
    $tables = $storage->getTableMapping()->getTableNames();

    $tagged_table_aliases = $query->getMetaData('islandora_hierarchical_access_tagged_table_aliases') ?? [];

    $target_aliases = static::getTaggingTargets($query, $tagged_table_aliases, $tables, $type);

    if (empty($target_aliases)) {
      return;
    }

    $query->addMetaData('islandora_hierarchical_access_tagged_table_aliases', $tagged_table_aliases);
    $null_query_or = $query->getMetaData('islandora_hierarchical_access_tagged_null_query_or');
    $existence = $query->getMetaData('islandora_hierarchical_access_tagged_existence_query');

    if (!$null_query_or) {
      // Test for existence in the LUT; otherwise, nothing to assert.
      $null_query = $this->database->select(LUTGeneratorInterface::TABLE_NAME, 'lut');
      $null_query->addExpression('1', 'ne');
      $null_query_or = $null_query->orConditionGroup();
      $query->addMetaData('islandora_hierarchical_access_tagged_null_query_or', $null_query_or);

      // Test that where we _are_ making assertions, things are left in the LUT.
      $existence = $this->database->select(LUTGeneratorInterface::TABLE_NAME, 'lut');
      $existence->addExpression('1', 'le');
      $query->addMetaData('islandora_hierarchical_access_tagged_existence_query', $existence);

      $query->condition($query->orConditionGroup()
        ->notExists($null_query)
        ->exists($existence)
      );
    }
    else {
      $null_query_or = $query->getMetaData('islandora_hierarchical_access_tagged_null_query_or');
    }

    $get_lut_column = function (string $type) : string {
      return match ($type) {
        'file' => 'fid',
        'media' => 'mid',
        'node' => 'nid',
      };
    };

    $lut_column = $get_lut_column($type);
    $replacements = [
      '!field' => "lut.{$lut_column}",
      '!targets' => implode(', ', $target_aliases),
    ];
    if ($type !== 'node') {
      $null_query_or->where(strtr('!field IN (!targets)', $replacements));
      // File is nullable, for media not bearing files.
      $existence->where(strtr('!field IS NULL OR !field IN (!targets)', $replacements));
    }
    else {
      $filter = strtr('!field IN (!targets)', $replacements);
      $null_query_or->where($filter);
      $existence->where($filter);
    }

    $this->eventDispatcher->dispatch(new Event($type, $query));

    $parents = [
      'file' => 'media',
      'media' => 'node',
    ];
    while ($parent = ($parents[$parent ?? $type] ?? NULL)) {
      $parent_lut_column = $get_lut_column($parent);
      $alias = "base_{$parent}";
      $entity_select = $this->database->select($parent, $alias)
        ->fields($alias, [substr($parent, 0, 1) . 'id'])
        ->addTag('islandora_hierarchical_access_subquery')
        ->addTag("{$parent}_access")
        ->addMetaData('base_table', $parent);

      $this->moduleHandler->alter("query_{$parent}_access", $entity_select);

      $existence->condition("lut.{$parent_lut_column}", $entity_select, 'IN');
      $this->eventDispatcher->dispatch(new Event($parent, $query));
    }
  }

}
