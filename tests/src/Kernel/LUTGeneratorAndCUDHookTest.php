<?php

namespace Drupal\Tests\islandora_hierarchical_access\Kernel;

use Drupal\Core\Database\StatementInterface;
use Drupal\islandora\IslandoraUtils;
use Drupal\islandora_hierarchical_access\LUTGeneratorInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\MediaTypeInterface;
use Drupal\node\NodeTypeInterface;
use Drupal\Tests\field\Traits\EntityReferenceTestTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\test_support\Traits\Support\InteractsWithEntities;

class LUTGeneratorAndCUDHookTest extends KernelTestBase {
  use EntityReferenceTestTrait;
  use ContentTypeCreationTrait;
  use MediaTypeCreationTrait;
  use InteractsWithEntities;

  public static $modules = [
    'field',
    'file',
    'image',
    'node',
    'media',
    'system',
    'text',
    'user',
  ];

  protected NodeTypeInterface $contentType;
  protected MediaTypeInterface $mediaType;

  public function setUp() {
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
      "Media Of", 'node');//$this->contentType->getEntityTypeId());

    // Enable our module and install its schema.
    $this->enableModules(['islandora_hierarchical_access']);
    $this->installSchema('islandora_hierarchical_access', ['islandora_hierarchical_access_lut']);
  }

  protected function lutResults(array $conditions = []) : StatementInterface {
    /** @var \Drupal\Core\Database\Connection $database */
    $database = $this->container->get('database');
    $query = $database->select(LUTGeneratorInterface::TABLE_NAME, 'lut')
      ->fields('lut');
    foreach ($conditions as $key => $value) {
      $query->condition($key, ...((array)$value));
    }
    return $query->execute();
  }

  protected function assertEmptyTraversable(\Traversable $iterable, string $message = '') : void {
    // XXX: ::assertEmpty() nor ::assertCount() seem to work with \Traversables
    // in general, or at least not PDO result/StatementInterface objects?
    $this->assertEquals(0, iterator_count($iterable), $message);
  }

  /**
   * @return array{\Drupal\node\NodeInterface, \Drupal\file\FileInterface, \Drupal\media\MediaInterface}
   *   An array containing:
   *   - a node
   *   - a file; and,
   *   - a media, relating the node and file.
   */
  public function testBasePopulation() : array {
    $this->assertEmptyTraversable($this->lutResults(), 'LUT is initially empty.');
    $node = $this->createEntity('node', [
      'type' => $this->contentType->getEntityTypeId(),
      'title' => $this->randomString(),
    ]);
    $this->assertEmptyTraversable($this->lutResults(), 'LUT is still empty empty; just with node.');
    $file = $this->createEntity('file', [
      'uri' => 'info:data/' . $this->randomMachineName(),
    ]);
    $this->assertEmptyTraversable($this->lutResults(), 'LUT is still empty empty; with node and unassociated file.');
    $media = $this->createEntity('media', [
      'bundle' => $this->mediaType->id(),
      IslandoraUtils::MEDIA_OF_FIELD => $node,
      $this->getMediaFieldName() => $file,
    ]);
    $this->assertAsPopulated($node, $file, $media);

    return [$node, $file, $media];
  }

  protected function getMediaFieldName() : string {
    return $this->mediaType->getSource()->getSourceFieldDefinition($this->mediaType)->getName();
  }

  protected function getPopulation($node, $file, $media) {
    return $this->lutResults([
      'nid' => $node->id(),
      'mid' => $media->id(),
      'fid' => $file->id(),
    ])->fetchAll(\PDO::FETCH_ASSOC);
  }

  protected function assertNotAsPopulated($node, $file, $media) {
    $this->assertCount(0, $this->getPopulation($node, $file, $media),'LUT has the anticipated nunber of values.');
  }

  protected function assertAsPopulated($node, $file, $media) {
    $lut_values = $this->getPopulation($node, $file, $media);
    $this->assertCount(1, $lut_values,'LUT has the anticipated nunber of values.');
    $this->assertEquals($node->id(), $lut_values[0]['nid'], 'Has the nid.');
    $this->assertEquals($media->id(), $lut_values[0]['mid'], 'Has the mid.');
    $this->assertEquals($file->id(), $lut_values[0]['fid'], 'Has the fid.');
  }

  public function testDeleteNode() {
    [$node, , ] = $this->testBasePopulation();
    $node->delete();
    $this->assertEmptyTraversable($this->lutResults());
  }

  public function testDeleteFile() {
    [, $file, ] = $this->testBasePopulation();
    $file->delete();
    $this->assertEmptyTraversable($this->lutResults());
  }

  public function testDeleteMedia() {
    [, , $media] = $this->testBasePopulation();
    $media->delete();
    $this->assertEmptyTraversable($this->lutResults());
  }

  public function testUpdateMedia() {
    [$node, $file, $media] = $this->testBasePopulation();

    $media->label = $this->randomString();
    $media->save();
    $this->assertAsPopulated($node, $file, $media);

    $new_file = $this->createEntity('file', [
      'uri' => 'info:data/' . $this->randomMachineName(),
    ]);
    $media->{$this->getMediaFieldName()} = $new_file;

    $this->assertNotAsPopulated($node, $new_file, $media);
    $this->assertAsPopulated($node, $file, $media);
    $media->save();
    $this->assertAsPopulated($node, $new_file, $media);
    $this->assertNotAsPopulated($node, $file, $media);

    $media->delete();
    $this->assertEmptyTraversable($this->lutResults());
  }
}
