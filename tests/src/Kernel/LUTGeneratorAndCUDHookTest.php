<?php

namespace Drupal\Tests\islandora_hierarchical_access\Kernel;

class LUTGeneratorAndCUDHookTest extends AbstractKernelTestBase {

  /**
   * @return array{\Drupal\node\NodeInterface, \Drupal\file\FileInterface, \Drupal\media\MediaInterface}
   *   An array containing:
   *   - a node
   *   - a file; and,
   *   - a media, relating the node and file.
   */
  public function testBasePopulation() : array {
    $this->assertEmptyTraversable($this->lutResults(), 'LUT is initially empty.');
    $node = $this->createNode();
    $this->assertEmptyTraversable($this->lutResults(), 'LUT is still empty empty; just with node.');
    $file = $this->createFile();
    $this->assertEmptyTraversable($this->lutResults(), 'LUT is still empty empty; with node and unassociated file.');
    $media = $this->createMedia($file, $node);
    $this->assertAsPopulated($node, $file, $media);

    return [$node, $file, $media];
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
