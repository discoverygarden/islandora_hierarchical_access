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

    if (!$existence) {
      // Test that where we _are_ making assertions, things are left in the LUT.
      $existence = $this->database->select(match ($type) {
        'file' => 'file_managed',
        default => $type,
      }, "base_{$type}");
      $existence->addExpression('1', 'lut_existence');
      $key = substr($type, 0, 1) . 'id';
      $lut_alias = $existence->leftJoin(LUTGeneratorInterface::TABLE_NAME, 'lut', "%alias.{$key} = base_{$type}.{$key}");
      $existence->addMetaData('islandora_hierarchical_access_tagged_lut_alias', $lut_alias);
      $query->addMetaData('islandora_hierarchical_access_tagged_existence_query', $existence);

      $base_condition = $existence->andConditionGroup();
      $null_condition = $existence->andConditionGroup();
      $existence_condition = $existence->andConditionGroup();

      $existence
        ->addMetaData('islandora_hierarchical_access_tagged_base_condition', $base_condition)
        ->addMetaData('islandora_hierarchical_access_tagged_null_condition', $null_condition)
        ->addMetaData('islandora_hierarchical_access_tagged_existence_condition', $existence_condition);

      $existence->condition($base_condition)
        ->condition($existence->orConditionGroup()
          // Test for non-existence in the LUT.
          ->condition($null_condition)
          // Or valid entries remaining from the LUT.
          ->condition($existence_condition)
        );

      $query->exists($existence);
    }
    else {
      $lut_alias = $query->getMetaData('islandora_hierarchical_access_tagged_lut_alias');
      $base_condition = $query->getMetaData('islandora_hierarchical_access_tagged_base_condition');
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
      '!base_field' => "base_{$type}.{$lut_column}",
      '!field' => "{$lut_alias}.{$lut_column}",
      '!targets' => implode(', ', $target_aliases),
    ];

    $base_condition->where(strtr('!base_field IN (!targets)', $replacements));
    $null_condition->where(strtr('!field IS NULL', $replacements));
    $existence_condition->where(strtr('!field IN (!targets)', $replacements));

    $this->eventDispatcher->dispatch(new Event($type, $query));
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
      $entity_select->addTag('islandora_hierarchical_access_subquery')
        ->addTag("{$parent}_access")
        ->addMetaData('base_table', $parent)
        ->where("lut.{$parent_lut_column} = {$parent_alias}.{$parent_key}");

      $this->moduleHandler->alter("query_{$parent}_access", $entity_select);

      $existence_condition->exists($entity_select);
      $this->eventDispatcher->dispatch(new Event($parent, $query));
    }
  }

}
