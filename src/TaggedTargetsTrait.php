<?php

namespace Drupal\islandora_hierarchical_access;

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Helper trait; acquire new target aliases from queries.
 */
trait TaggedTargetsTrait {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Helper; identify (new) target table aliases to target.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query in which to operate.
   * @param array $tagged_table_aliases
   *   A reference to an array of existing aliases.
   * @param string $type
   *   The type of entity with which we are dealing.
   *
   * @return string[]
   *   New aliases to which to attach filtering.
   */
  protected function getTaggingTargets(SelectInterface $query, array &$tagged_table_aliases, string $type) : array {
    /** @var \Drupal\Core\Entity\Sql\SqlEntityStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage($type);
    $tables = $storage->getTableMapping()->getTableNames();

    $target_aliases = [];

    foreach ($query->getTables() as $info) {
      if ($info['table'] instanceof SelectInterface) {
        continue;
      }
      elseif (in_array($info['table'], $tables)) {
        $key = (str_starts_with($info['table'], "{$type}__")) ? 'entity_id' : (substr($type, 0, 1) . "id");
        $alias = $info['alias'];
        if (!in_array($alias, $tagged_table_aliases)) {
          $tagged_table_aliases[] = $alias;
          $target_aliases[] = "{$alias}.{$key}";
        }
      }
    }

    return $target_aliases;
  }

}
