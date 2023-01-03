<?php

namespace Drupal\islandora_hierarchical_access;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Perform LUT maintenance on entity CUD operations.
 */
class EntityCUDHandler implements EntityHandlerInterface {
  const NAME = 'islandora_hierarchical_access_entity_cud';
  const PROPERTY_NAME__COLUMN = self::NAME . '_column';
  const PROPERTY_NAME__OPERATIONS = self::NAME . '_operations';

  /**
   * Operation bitfield definitions.
   */
  const OPERATIONS_NONE = 0x0;
  const OPERATIONS_CREATE = 0x1;
  const OPERATIONS_UPDATE = 0x2;
  const OPERATIONS_DELETE = 0x4;

  /**
   * Attach LUT maintenance entity handlers.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type to which to attach the handler.
   */
  public static function attach(EntityTypeInterface $entity_type) : void {
    if (!$entity_type->hasHandlerClass(static::NAME)) {
      if ($entity_type->entityClassImplements(FileInterface::class)) {
        $entity_type->setHandlerClass(static::NAME, static::class)
          ->set(static::PROPERTY_NAME__COLUMN, 'fid');
      }
      if ($entity_type->entityClassImplements(MediaInterface::class)) {
        $entity_type->setHandlerClass(static::NAME, static::class)
          ->set(static::PROPERTY_NAME__COLUMN, 'mid')
          ->set(static::PROPERTY_NAME__OPERATIONS, static::OPERATIONS_CREATE | static::OPERATIONS_UPDATE | static::OPERATIONS_DELETE);
      }
      if ($entity_type->entityClassImplements(NodeInterface::class)) {
        $entity_type->setHandlerClass(static::NAME, static::class)
          ->set(static::PROPERTY_NAME__COLUMN, 'nid');
      }
    }
  }

  /**
   * Drupal's database service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * LUT generator service.
   *
   * @var \Drupal\islandora_hierarchical_access\LUTGeneratorInterface
   */
  protected LUTGeneratorInterface $generator;

  /**
   * The LUT column associated with the given entity type to target.
   *
   * @var string
   */
  protected string $column;

  /**
   * Bitfield identifying which operations are valid for the given entity type.
   *
   * @var int
   */
  protected int $operations;

  /**
   * Constructor.
   */
  public function __construct(
    Connection $database,
    LUTGeneratorInterface $generator,
    $column,
    $operations
  ) {
    $this->database = $database;
    $this->generator = $generator;
    $this->column = $column;
    $this->operations = $operations;
  }

  /**
   * {@inheritDoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) : self {
    return new static(
      $container->get('database'),
      $container->get('islandora_hierarchical_access.lut_generator'),
      $entity_type->get(static::PROPERTY_NAME__COLUMN),
      $entity_type->get(static::PROPERTY_NAME__OPERATIONS) ?? static::OPERATIONS_DELETE
    );
  }

  /**
   * Handle entity insertion/creation.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to handle.
   */
  public function create(EntityInterface $entity) : void {
    if (!($this->operations & static::OPERATIONS_CREATE)) {
      return;
    }

    $this->generator->generate($entity->id());
  }

  /**
   * Handle entity updates.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to handle.
   */
  public function update(EntityInterface $entity) : void {
    if (!($this->operations & static::OPERATIONS_UPDATE)) {
      return;
    }

    $this->delete($entity);
    $this->create($entity);
  }

  /**
   * Handle entity deletion.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to handle.
   */
  public function delete(EntityInterface $entity) : void {
    if (!($this->operations & static::OPERATIONS_DELETE)) {
      return;
    }

    $this->database->delete(LUTGeneratorInterface::TABLE_NAME)
      ->condition($this->column, $entity->id())
      ->execute();
  }

}
