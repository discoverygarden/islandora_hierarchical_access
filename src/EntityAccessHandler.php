<?php

namespace Drupal\islandora_hierarchical_access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Inherited entity access handler.
 */
class EntityAccessHandler implements EntityAccessHandlerInterface, AttachableEntityHandlerInterface {
  const NAME = 'islandora_hierarchical_access_access';
  const PROPERTY_NAME__OPS = self::NAME . '_operations';
  const PROPERTY_NAME__COLUMN = self::NAME . '_column';
  const PROPERTY_NAME__TARGET_COLUMN = self::NAME . '_target_column';
  const PROPERTY_NAME__TARGET_TYPE = self::NAME . '_target_type';
  const PROPERTY_NAME__TARGET_OP_MAP = self::NAME . '_op_map';

  /**
   * {@inheritDoc}
   */
  public static function attach(EntityTypeInterface $entity_type) : void {
    if (!$entity_type->hasHandlerClass(static::NAME)) {
      if ($entity_type->entityClassImplements(FileInterface::class)) {
        $entity_type->setHandlerClass(static::NAME, static::class)
          ->set(static::PROPERTY_NAME__COLUMN, 'fid')
          ->set(static::PROPERTY_NAME__TARGET_COLUMN, 'mid')
          ->set(static::PROPERTY_NAME__TARGET_TYPE, 'media');
      }
      if ($entity_type->entityClassImplements(MediaInterface::class)) {
        $entity_type->setHandlerClass(static::NAME, static::class)
          ->set(static::PROPERTY_NAME__COLUMN, 'mid')
          ->set(static::PROPERTY_NAME__TARGET_COLUMN, 'nid')
          ->set(static::PROPERTY_NAME__TARGET_TYPE, 'node')
          ->set(static::PROPERTY_NAME__TARGET_OP_MAP, [
            'download' => 'view',
          ]);
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
   * Target operations under which to test.
   *
   * @var string[]
   */
  protected array $ops;

  /**
   * The target type from which to inherit access constraints.
   *
   * @var \Drupal\Core\Entity\EntityTypeInterface
   */
  protected EntityTypeInterface $targetType;

  /**
   * The target type's storage service.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $storage;

  /**
   * The column for matching the current entity in the LUT.
   *
   * @var string
   */
  protected string $column;

  /**
   * The column of the target type to return from the LUT.
   *
   * @var string
   */
  protected string $targetColumn;

  /**
   * A mapping of operations which should be mapped as other operations.
   *
   * @var array
   */
  protected array $opMap;

  /**
   * Constructor.
   */
  public function __construct(
    Connection $database,
    $ops,
    EntityStorageInterface $storage,
    $column,
    $target_column,
    array $op_map
  ) {
    $this->database = $database;
    $this->ops = $ops;
    $this->storage = $storage;
    $this->targetType = $storage->getEntityType();
    $this->column = $column;
    $this->targetColumn = $target_column;
    $this->opMap = $op_map;
  }

  /**
   * {@inheritDoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) : self {
    /** @var \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager */
    $entity_type_manager = $container->get('entity_type.manager');
    return new static(
      $container->get('database'),
      $entity_type->get(static::PROPERTY_NAME__OPS) ?? ['view', 'download'],
      $entity_type_manager->getStorage($entity_type->get(static::PROPERTY_NAME__TARGET_TYPE)),
      $entity_type->get(static::PROPERTY_NAME__COLUMN),
      $entity_type->get(static::PROPERTY_NAME__TARGET_COLUMN),
      $entity_type->get(static::PROPERTY_NAME__TARGET_OP_MAP) ?? []
    );
  }

  /**
   * {@inheritDoc}
   */
  public function check(EntityInterface $entity, string $operation, AccountInterface $account) : AccessResultInterface {
    if (!in_array($operation, $this->ops, TRUE)) {
      return AccessResult::neutral("Irrelevant operation.");
    }

    return $this->doCheck($entity, $operation, $account);
  }

  /**
   * Helper; get some cache tags for when we find nothing.
   *
   * @return string[]
   *   The cache tags to set when there are no targets found.
   */
  protected function getEmptyCacheTags() : array {
    return $this->targetType->getListCacheTags();
  }

  /**
   * Actually do the check.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being checked.
   * @param string $operation
   *   The operation on the entity being checked.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account wanting to perform the operation.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The result of the access check as an object.
   */
  protected function doCheck(EntityInterface $entity, string $operation, AccountInterface $account) : AccessResultInterface {
    /** @var \Drupal\Core\Access\AccessResultNeutral $result */
    $result = AccessResult::neutral()
      ->addCacheableDependency($entity)
      ->addCacheableDependency($account);

    $entity_ids = $this->lookupEntityIds($entity);

    if (empty($entity_ids)) {
      // Failed to find any node: We have no opinion.
      /** @var \Drupal\Core\Access\AccessResultNeutral $to_return */
      $to_return = $result->setReason("No candidate target entities found.");
      $to_return->addCacheTags($this->getEmptyCacheTags());
      return $to_return;
    }

    $mapped_op = $this->opMap[$operation] ?? $operation;

    $reasons = [];
    foreach ($entity_ids as $id) {
      $loadedEntity = $this->storage->load($id);
      if (!$loadedEntity) {
        // Inconclusive; failed to load.
        $reasons[] = strtr("Rejecting {type} {id} because it failed to load.", [
          '{type}' => $this->targetType->id(),
          '{id}' => $id,
        ]);
        continue;
      }

      /** @var \Drupal\Core\Access\AccessResultInterface|\Drupal\Core\Access\AccessResultReasonInterface $entity_access */
      $entity_access = $loadedEntity->access($mapped_op, $account, TRUE);
      if ($entity_access->isAllowed()) {
        // Found a node which is viewable: Let it through.
        /** @var \Drupal\Core\Access\AccessResult $to_return */
        $to_return = $result->orIf($entity_access);
        $to_return->addCacheableDependency($loadedEntity);
        return $to_return;
      }
      elseif ($entity_access instanceof AccessResultReasonInterface) {
        $reasons[] = strtr("Rejecting {type} {id} because provided reasoning: {reason}", [
          '{type}' => $this->targetType->id(),
          '{id}' => $id,
          '{reason}' => $entity_access->getReason(),
        ]);
      }
      else {
        $reasons[] = strtr("Rejecting {type} {id}.", [
          '{type}' => $this->targetType->id(),
          '{id}' => $id,
        ]);
      }
    }

    // Exhaustively search the nodes: Deny.
    /** @var \Drupal\Core\Access\AccessResult $to_return */
    $to_return = $result->orIf(AccessResult::forbidden(strtr("Failed to find an entity allowing access: {reasoning}", [
      '{reasoning}' => implode(' ', $reasons),
    ])));
    $to_return->addCacheTags($this->getEmptyCacheTags());
    return $to_return;
  }

  /**
   * Helper; perform lookup using LUT.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to lookup.
   *
   * @return int[]|string[]
   *   An array of IDs of the looked-up target entities.
   */
  protected function lookupEntityIds(EntityInterface $entity) : array {
    return $this->database->select(LUTGeneratorInterface::TABLE_NAME, 'lut')
      ->fields('lut', [$this->targetColumn])
      ->distinct()
      ->condition($this->column, $entity->id())
      ->execute()
      ->fetchCol();
  }

}
