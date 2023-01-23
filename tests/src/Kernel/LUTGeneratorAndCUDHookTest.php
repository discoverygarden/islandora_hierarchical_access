<?php

namespace Drupal\Tests\islandora_hierarchical_access\Kernel;

/**
 * Test LUT generation and CUD hook triggering.
 */
class LUTGeneratorAndCUDHookTest extends AbstractKernelTestBase {

  /**
   * Helper; create and assert structure given a few entities.
   *
   * @phpcs:ignore Drupal.Commenting.FunctionComment.ReturnTypeSpaces, Drupal.Commenting.DocComment.SpacingBeforeTags
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

  /**
   * Test the deleting the node results in the relevant rows being removed.
   */
  public function testDeleteNode() {
    $node = $this->testBasePopulation()[0];
    $node->delete();
    $this->assertEmptyTraversable($this->lutResults());
  }

  /**
   * Test the deleting the file results in the relevant rows being removed.
   */
  public function testDeleteFile() {
    $file = $this->testBasePopulation()[1];
    $file->delete();
    $this->assertEmptyTraversable($this->lutResults());
  }

  /**
   * Test the deleting the media results in the relevant rows being removed.
   */
  public function testDeleteMedia() : void {
    $media = $this->testBasePopulation()[2];
    $media->delete();
    $this->assertEmptyTraversable($this->lutResults());
  }

  /**
   * Test that updating media reacts appropriately.
   *
   * Also, unassociated files do not influence the LUT.
   */
  public function testUpdateMedia() : void {
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
