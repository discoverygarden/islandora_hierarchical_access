<?php

namespace Drupal\islandora_hierarchical_access\Event;

use Drupal\Component\EventDispatcher\Event as UpstreamEvent;
use Drupal\Core\Database\Query\SelectInterface;

/**
 * Islandora hierarchical access event.
 */
class Event extends UpstreamEvent {

  /**
   * Constructor.
   */
  public function __construct(
    protected string $type,
    protected SelectInterface $query,
  ) {
    // Essentially, no-op, other than stashing the passed properties.
    assert(in_array($type, ['file', 'media', 'node']));
  }

  /**
   * The type of entity represented by the query.
   *
   * @return string
   *   The type of entity.
   */
  public function getType() : string {
    return $this->type;
  }

  /**
   * Accessor for the query.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The query being altered/to alter.
   */
  public function getQuery() : SelectInterface {
    return $this->query;
  }

}
