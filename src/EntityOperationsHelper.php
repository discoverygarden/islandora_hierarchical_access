<?php

namespace Drupal\islandora_hierarchical_access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Exception;

/**
 * Entity operations helper.
 */
class EntityOperationsHelper {

  /**
   * @var \Drupal\islandora_hierarchical_access\LUTGenerator
   */
  private LUTGenerator $lutgenerator;

  /**
   * @var \Drupal\Core\Database\Connection
   */
  private Connection $database;

  /**
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private EntityStorageInterface $nodeStorage;

  /**
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private EntityStorageInterface $mediaStorage;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * @param \Drupal\Core\Database\Connection $database
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
    LUTGenerator $LUTGenerator
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
    $this->nodeStorage = $entityTypeManager->getStorage('node');
    $this->mediaStorage = $entityTypeManager->getStorage('media');
    $this->lutgenerator = $LUTGenerator;
  }

  /**
   * @param $fieldName
   * @param $entity
   *
   * @return void
   */
  public function mediaEntityUpdate($fieldName, $entity): void {
    $this->entityDelete($fieldName, $entity->id());
    $this->mediaEntityInsert($entity);
  }

  /**
   * @param $fieldName
   * @param $fieldValue
   *
   * @return void
   */
  public function entityDelete($fieldName = 'mid', $fieldValue): void {
    $this->database->delete(LUTGeneratorInterface::TABLE_NAME)
      ->condition($fieldName, $fieldValue)
      ->execute();
  }

  /**
   * @param $entity
   *
   * @return void
   */
  public function mediaEntityInsert($entity): void {
    $this->lutgenerator->generate($entity->id());
  }

  /**
   * @param $entity
   * @param $account
   * @param $operation
   *
   * @return \Drupal\Core\Access\AccessResult|\Drupal\Core\Access\AccessResultAllowed|\Drupal\Core\Access\AccessResultForbidden|\Drupal\Core\Access\AccessResultNeutral
   */
  public function entityAccess($entity, $account, $operation): AccessResult {
    $result = AccessResult::neutral()
      ->addCacheableDependency($entity)
      ->addCacheableDependency($account);

    // Set the relevant cache tags and storage based on entity type.
    if ($entity instanceof NodeInterface) {
      $cacheTags = ['node_list'];
      $storage = $this->nodeStorage;
    }
    else {
      $cacheTags = ['media_list'];
      $storage = $this->mediaStorage;
    }

    $entity_ids = $this->getEntityIdsFromLUTTable($entity);

    if (empty($entity_ids)) {
      // Failed to find any node: We have no opinion.
      return $result->addCacheTags($cacheTags);
    }

    foreach ($entity_ids as $id) {
      $loadedEntity = $storage->load($id);
      if ($loadedEntity) {
        // Inconclusive; failed to load.
        continue;
      }

      $entity_access = $loadedEntity->access($operation, $account, TRUE);
      if ($entity_access->isAllowed()) {
        // Found a node which is viewable: Let it through.
        return $result->orIf($entity_access)
          ->addCacheableDependency($loadedEntity);
      }
    }

    // Exhaustively search the nodes: Deny.
    return $result->orIf(AccessResult::forbidden())->addCacheTags($cacheTags);
  }

  /**
   * @param $entity
   *
   * @return array
   */
  private function getEntityIdsFromLUTTable($entity): array {
    $fields = [
      'get' => 'mid',
      'condition' => 'nid',
    ];

    // Change the condition and table fields to fetch based on entity type.
    if ($entity instanceof NodeInterface) {
      $fields['get'] = 'nid';
      $fields['condition'] = 'mid';
    }

    try {
      return $this->database->select(LUTGeneratorInterface::TABLE_NAME, 'lut')
        ->fields('lut', [$fields['get']])
        ->distinct()
        ->condition($fields['condition'], $entity->id())
        ->execute()
        ->fetchCol();
    }
    catch (Exception $e) {
      return [];
    }
  }

}
