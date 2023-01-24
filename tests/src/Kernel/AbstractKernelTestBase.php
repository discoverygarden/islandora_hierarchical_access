<?php

namespace Drupal\Tests\islandora_hierarchical_access\Kernel;

use Drupal\Core\Database\StatementInterface;
use Drupal\file\FileInterface;
use Drupal\islandora\IslandoraUtils;
use Drupal\islandora_hierarchical_access\LUTGeneratorInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;
use Drupal\node\NodeInterface;
use Drupal\node\NodeTypeInterface;
use Drupal\Tests\field\Traits\EntityReferenceTestTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\test_support\Traits\Support\InteractsWithEntities;

/**
 * Abstract kernel test base for LUT and access control testing.
 */
abstract class AbstractKernelTestBase extends KernelTestBase {
  use EntityReferenceTestTrait;
  use ContentTypeCreationTrait;
  use MediaTypeCreationTrait;
  use InteractsWithEntities;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'file',
    'image',
    'node',
    'media',
    'system',
    'text',
    'user',
  ];

  /**
   * Node type for node creation.
   *
   * @var \Drupal\node\NodeTypeInterface|\Drupal\node\Entity\NodeType
   */
  protected NodeTypeInterface $contentType;

  /**
   * Media type for media creation.
   *
   * @var \Drupal\media\MediaTypeInterface
   */
  protected MediaTypeInterface $mediaType;

  /**
   * {@inheritDoc}
   */
  public function setUp() : void {
    parent::setUp();

    $this->installConfig([
      'node',
      'user',
    ]);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('file');
    $this->installEntitySchema('node');
    $this->installEntitySchema('media');
    $this->installEntitySchema('user');

    $this->contentType = $this->createContentType();
    $this->mediaType = $this->createMediaType('file');
    $this->createEntityReferenceField('media',
      $this->mediaType->id(), IslandoraUtils::MEDIA_OF_FIELD,
      "Media Of", $this->contentType->getEntityType()->getBundleOf());

    // Enable our module and install its schema.
    $this->enableModules(['islandora_hierarchical_access']);
    $this->installSchema('islandora_hierarchical_access', ['islandora_hierarchical_access_lut']);
  }

  /**
   * Helper; perform query against LUT, and get results.
   *
   * @param array $conditions
   *   An optional set of conditions for the query, as an associative array
   *   of parameters to SelectInterface::condition(), where the key is the first
   *   parameter and the value is an array of additional parameter (or a single
   *   other parameter).
   *
   * @return \Drupal\Core\Database\StatementInterface
   *   The query results.
   */
  protected function lutResults(array $conditions = []) : StatementInterface {
    /** @var \Drupal\Core\Database\Connection $database */
    $database = $this->container->get('database');
    $query = $database->select(LUTGeneratorInterface::TABLE_NAME, 'lut')
      ->fields('lut');
    foreach ($conditions as $key => $value) {
      $query->condition($key, ...((array) $value));
    }
    return $query->execute();
  }

  /**
   * Assert that the given traversable is empty.
   *
   * @param \Traversable $iterable
   *   The traversable to test.
   * @param string $message
   *   An assertion message.
   */
  protected function assertEmptyTraversable(\Traversable $iterable, string $message = '') : void {
    // XXX: ::assertEmpty() nor ::assertCount() seem to work with \Traversables
    // in general, or at least not PDO result/StatementInterface objects?
    $this->assertEquals(0, iterator_count($iterable), $message);
  }

  /**
   * Helper; create a node.
   *
   * @return \Drupal\node\NodeInterface
   *   A created (and saved) node entity.
   */
  protected function createNode() : NodeInterface {
    /** @var \Drupal\node\NodeInterface $entity */
    $entity = $this->createEntity('node', [
      'type' => $this->contentType->getEntityTypeId(),
      'title' => $this->randomString(),
    ]);
    return $entity;
  }

  /**
   * Helper; create a file entity.
   *
   * @return \Drupal\file\FileInterface
   *   A created (and saved) file entity.
   */
  protected function createFile() : FileInterface {
    /** @var \Drupal\file\FileInterface $entity */
    $entity = $this->createEntity('file', [
      'uri' => 'info:data/' . $this->randomMachineName(),
    ]);
    return $entity;
  }

  /**
   * Helper; create an Islandora-esque media entity.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity to which the media should refer.
   * @param \Drupal\node\NodeInterface $node
   *   A node to which the media should belong using Islandora's "media of"
   *   field.
   *
   * @return \Drupal\media\MediaInterface
   *   A created (and saved) media entity.
   */
  protected function createMedia(FileInterface $file, NodeInterface $node) : MediaInterface {
    /** @var \Drupal\media\MediaInterface $entity */
    $entity = $this->createEntity('media', [
      'bundle' => $this->mediaType->id(),
      IslandoraUtils::MEDIA_OF_FIELD => $node,
      $this->getMediaFieldName() => $file,
    ]);
    return $entity;
  }

  /**
   * Helper; get the name of the source field of our created media type.
   *
   * @return string
   *   The name of the field.
   */
  protected function getMediaFieldName() : string {
    return $this->mediaType->getSource()->getSourceFieldDefinition($this->mediaType)->getName();
  }

  /**
   * Helper; query against LUT based on given populated entities.
   *
   * @param \Drupal\node\NodeInterface $node
   *   A node for which to query.
   * @param \Drupal\file\FileInterface $file
   *   A file entity for which to query.
   * @param \Drupal\media\MediaInterface $media
   *   A media entity for which to query.
   *
   * @return array
   *   An array of associative arrays containing the columns of the LUT.
   */
  protected function getPopulation(NodeInterface $node, FileInterface $file, MediaInterface $media) : array {
    return $this->lutResults([
      'nid' => $node->id(),
      'mid' => $media->id(),
      'fid' => $file->id(),
    ])->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Assert that the LUT does not contain rows for the given entities.
   *
   * @param \Drupal\node\NodeInterface $node
   *   A node for which to query.
   * @param \Drupal\file\FileInterface $file
   *   A file entity for which to query.
   * @param \Drupal\media\MediaInterface $media
   *   A media entity for which to query.
   */
  protected function assertNotAsPopulated(NodeInterface $node, FileInterface $file, MediaInterface $media) : void {
    $this->assertCount(0, $this->getPopulation($node, $file, $media), 'LUT has the anticipated number of values.');
  }

  /**
   * Assert that the LUT contains rows for the given entities.
   *
   * @param \Drupal\node\NodeInterface $node
   *   A node for which to query.
   * @param \Drupal\file\FileInterface $file
   *   A file entity for which to query.
   * @param \Drupal\media\MediaInterface $media
   *   A media entity for which to query.
   */
  protected function assertAsPopulated(NodeInterface $node, FileInterface $file, MediaInterface $media) : void {
    $lut_values = $this->getPopulation($node, $file, $media);
    $this->assertCount(1, $lut_values, 'LUT has the anticipated number of values.');
    $this->assertEquals($node->id(), $lut_values[0]['nid'], 'Has the nid.');
    $this->assertEquals($media->id(), $lut_values[0]['mid'], 'Has the mid.');
    $this->assertEquals($file->id(), $lut_values[0]['fid'], 'Has the fid.');
  }

}
