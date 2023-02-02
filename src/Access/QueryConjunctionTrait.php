<?php

namespace Drupal\islandora_hierarchical_access\Access;

use Drupal\Core\Database\Query\SelectInterface;

/**
 * Helper to ensure queries perform base op is con- instead of dis-joint.
 */
trait QueryConjunctionTrait {

  /**
   * Ensure the given query represents an "AND" to which we can attach filters.
   *
   * Queries can select either "OR" or "AND" as their base operator when they
   * are created; however, constraining results is much easier with "AND"... so
   * let's rework the query object to make it so.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query to be tagged.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The query which has been dealt with... should be the same query, just
   *   returning for (potential) convenience.
   */
  protected static function conjunctionQuery(SelectInterface $query): SelectInterface {
    $original_conditions =& $query->conditions();
    if ($original_conditions['#conjunction'] === 'AND') {
      // Nothing to do...
      return $query;
    }

    $new_or = $query->orConditionGroup();

    $original_conditions_copy = $original_conditions;
    unset($original_conditions_copy['#conjunction']);
    foreach ($original_conditions_copy as $orig_cond) {
      $new_or->condition($orig_cond['field'], $orig_cond['value'] ?? NULL,
        $orig_cond['operator'] ?? '=');
    }

    $new_and = $query->andConditionGroup()
      ->condition($new_or);

    $original_conditions = $new_and->conditions();

    return $query;
  }
}
