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
    if (!in_array($type, ['media', 'file'])) {
      throw new \InvalidArgumentException("Unrecognized type '$type'.");
    }
    if ($query->hasTag('islandora_hierarchical_access_subquery')) {
      // Avoid further altering when it should already be accounted for
      // internally.
      return;
    }

    static::conjunctionQuery($query);

    $tagged_table_aliases = $query->getMetaData('islandora_hierarchical_access_tagged_table_aliases') ?? [];

    $target_aliases = $this->getTaggingTargets($query, $tagged_table_aliases, $type);

    if (empty($target_aliases)) {
      return;
    }

    $query->addMetaData('islandora_hierarchical_access_tagged_table_aliases', $tagged_table_aliases);
    $existence = $query->getMetaData('islandora_hierarchical_access_tagged_existence_query');

    $lut_null_alias = 'lut_null';
    $lut_exist_alias = 'lut_exist';
    if (!$existence) {
      $null_query = $this->database->select(LUTGeneratorInterface::TABLE_NAME, $lut_null_alias);
      $null_query->addExpression(1, 'lut_null_existance');
      $null_query->condition($null_condition = $null_query->orConditionGroup());
      $query->addMetaData('islandora_hierarchical_access_tagged_null_alias', $lut_null_alias);

      // Test that where we _are_ making assertions, things are left in the LUT.
      $existence = $this->database->select(LUTGeneratorInterface::TABLE_NAME, $lut_exist_alias);
      $existence->addExpression('1', 'lut_existence');
      $query->addMetaData('islandora_hierarchical_access_tagged_existence_alias', $lut_exist_alias);

      $query->addMetaData('islandora_hierarchical_access_tagged_existence_query', $existence);

      $existence->condition($existence_condition = $existence->andConditionGroup());

      $query
        ->addMetaData('islandora_hierarchical_access_tagged_null_condition', $null_condition)
        ->addMetaData('islandora_hierarchical_access_tagged_existence_condition', $existence_condition);

      $query->condition(
        $query->orConditionGroup()
          ->notExists($null_query)
          ->exists($existence)
      );
    }
    else {
      $null_condition = $query->getMetaData('islandora_hierarchical_access_tagged_null_condition');
      $existence_condition = $query->getMetaData('islandora_hierarchical_access_tagged_existence_condition');
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
      '!targets' => implode(', ', $target_aliases),
    ];

    // Not in LUT.
    $null_condition->where(strtr('!field IN (!targets)', $replacements + [
      '!field' => "{$lut_null_alias}.{$lut_column}",
    ]));
    // In LUT.
    $existence_condition->where(strtr('!field IN (!targets)', $replacements + [
      '!field' => "{$lut_exist_alias}.{$lut_column}",
    ]));

    $this->eventDispatcher->dispatch(new Event($type, $query));

    // We need to allow arbitrary altered queries for parent entities to affect
    // results; otherwise, there could be entities that are not visible via
    // other non-IHA mechanisms that leak things.
    // Where possible, we should prefer to use the event-based alteration to
    // make adjustments instead of altering the entity-specific queries
    // directly. Theoretically, we could implement queries to be IHA-aware, to
    // make use of the event-handler when it is available but otherwise add its
    // constraints directly to the entity query?
    $parents = [
      'file' => 'media',
      'media' => 'node',
    ];
    while ($parent = ($parents[$parent ?? $type] ?? NULL)) {
      $parent_lut_column = $get_lut_column($parent);
      $parent_alias = "base_{$parent}";
      $parent_key = substr($parent, 0, 1) . 'id';
      $entity_select = $this->database->select($parent, $parent_alias);
      $entity_select->addExpression(1, "{$parent}_existence");
      $entity_select->addMetaData('islandora_hierarchical_access_subquery_type', $parent);
      $entity_select->addMetaData('islandora_hierarchical_access_subquery_alias', $parent_alias);
      $entity_select->addTag('islandora_hierarchical_access_subquery')
        ->addTag("{$parent}_access")
        ->addMetaData('base_table', $parent)
        ->where("{$lut_exist_alias}.{$parent_lut_column} = {$parent_alias}.{$parent_key}");

      $before = "{$entity_select}";
      $this->moduleHandler->alter("query_{$parent}_access", $entity_select);
      if ($before !== "{$entity_select}") {
        // If the query was altered, let us apply its effects;
        // otherwise, the base query makes no assertion for which we have not
        // already accounted above.
        $existence_condition->exists($entity_select);
      }
      $this->eventDispatcher->dispatch(new Event($parent, $query));
    }
  }

}
