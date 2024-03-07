<?php

namespace Drupal\islandora_hierarchical_access;

use Drupal\Core\Database\Query\SelectInterface;

trait TaggedTargetsTrait {

  protected static function getTaggingTargets(SelectInterface $query, array &$tagged_table_aliases, array $tables, string $type) : array {
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
