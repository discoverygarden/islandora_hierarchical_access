<?php

namespace Drupal\Tests\islandora_hierarchical_access\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\test_support\Traits\Support\InteractsWithAuthentication;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Test various access control patterns.
 *
 * @group islandora_hierarchical_access
 */
class AccessControlTest extends AbstractKernelTestBase {
  const KEY = 'islandora_hierarchical_access_access_control_test_key';

  use UserCreationTrait;
  use InteractsWithAuthentication;

  /**
   * Node setup per test.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected NodeInterface $node;

  /**
   * Media setup per test, relating the $node to the $file.
   *
   * @var \Drupal\media\MediaInterface
   */
  protected MediaInterface $media;

  /**
   * File setup per test.
   *
   * @var \Drupal\file\FileInterface
   */
  protected FileInterface $file;

  /**
   * User setup per test.
   *
   * @var \Drupal\Core\Session\AccountInterface|\Drupal\user\UserInterface
   */
  protected AccountInterface $user;

  /**
   * {@inheritDoc}
   */
  public function setUp() {
    parent::setUp();
    $this->enableModules([
      'islandora_hierarchical_access_test_access',
    ]);

    $this->node = $this->createNode();
    $this->file = $this->createFile();
    $this->media = $this->createMedia($this->file, $this->node);
    $this->user = $this->setUpCurrentUser([], ['access content'], FALSE);
    $this->op = 'view';
  }

  /**
   * Helper; pass data via "Settings" to our test module.
   *
   * @param array $data
   *   An associative array containing:
   *   - entities: An associative array of entity type names to entity IDs of
   *     the given type to which access _should be denied_.
   */
  protected function passToModule(array $data = []) : void {
    $this->setSetting(
      static::KEY,
      array_merge_recursive($data, ['entities' => []])
    );
  }

  /**
   * Test base access.
   *
   * Given everything is accessible, things should be accessible.
   */
  public function testBaseAccess() {
    $this->passToModule();
    $this->assertTrue($this->node->access($this->op));
    $this->assertTrue($this->file->access($this->op));
    $this->assertTrue($this->media->access($this->op));
  }

  /**
   * Test base denial.
   *
   * Media and file that are related to a single node that is denied should both
   * similarly be denied.
   */
  public function testBaseNodeDeny() {
    $this->passToModule([
      'entities' => [
        'node' => [$this->node->id()],
      ],
    ]);

    $this->assertFalse($this->node->access($this->op));
    $this->assertFalse($this->file->access($this->op));
    $this->assertFalse($this->media->access($this->op));
  }

  /**
   * The node should still be visible when the media is denied.
   *
   * However, the file should be denied (if no other media/node grants access).
   */
  public function testBaseMediaDeny() {
    $this->passToModule([
      'entities' => [
        'media' => [$this->media->id()],
      ],
    ]);

    $this->assertTrue($this->node->access($this->op));
    $this->assertFalse($this->file->access($this->op));
    $this->assertFalse($this->media->access($this->op));
  }

  /**
   * Denying the file should deny the file.
   *
   * The media and node should remain visible.
   */
  public function testBaseFileDeny() {
    $this->passToModule([
      'entities' => [
        'file' => [$this->file->id()],
      ],
    ]);

    $this->assertTrue($this->node->access($this->op));
    $this->assertFalse($this->file->access($this->op));
    $this->assertTrue($this->media->access($this->op));
  }

  /**
   * Test multiple nodes allowed.
   */
  public function testMultipleNodeAllAllowed() {
    $this->passToModule();

    $other_node = $this->createNode();
    $other_media = $this->createMedia($this->file, $other_node);

    $this->assertTrue($this->file->access($this->op));
    $this->assertTrue($other_node->access($this->op));
    $this->assertTrue($other_media->access($this->op));
    $this->assertTrue($this->node->access($this->op));
    $this->assertTrue($this->media->access($this->op));

  }

  /**
   * Test one node allowing with one node denying.
   *
   * File should be allowed, and the media should be dependent on the node.
   */
  public function testMultipleNodeAllowed() {
    $this->passToModule([
      'entities' => [
        'node' => [$this->node->id()],
      ],
    ]);

    $other_node = $this->createNode();
    $other_media = $this->createMedia($this->file, $other_node);

    $this->assertTrue($this->file->access($this->op));
    $this->assertTrue($other_node->access($this->op));
    $this->assertTrue($other_media->access($this->op));
    $this->assertFalse($this->node->access($this->op));
    $this->assertFalse($this->media->access($this->op));

  }

  /**
   * Test two nodes denying continues to deny.
   */
  public function testMultipleNodeDenied() {
    $other_node = $this->createNode();
    $other_media = $this->createMedia($this->file, $other_node);

    $this->passToModule([
      'entities' => [
        'node' => [
          $this->node->id(),
          $other_node->id(),
        ],
      ],
    ]);

    $this->assertFalse($this->file->access($this->op));
    $this->assertFalse($other_node->access($this->op));
    $this->assertFalse($other_media->access($this->op));
    $this->assertFalse($this->node->access($this->op));
    $this->assertFalse($this->media->access($this->op));
  }

  /**
   * Test multiple media, both being allowed.
   *
   * Everything should be allowed.
   */
  public function testMultipleMediaAllowed() {
    $this->passToModule();

    $other_media = $this->createMedia($this->file, $this->node);

    $this->assertTrue($this->file->access($this->op));
    $this->assertTrue($other_media->access($this->op));
    $this->assertTrue($this->node->access($this->op));
    $this->assertTrue($this->media->access($this->op));
  }

  /**
   * Test one node allowing but its media denying and vice-versa, with one file.
   *
   * No path to allow the file, should be denied.
   */
  public function testMultipleStageDeny() {
    $other_media = $this->createMedia($this->file, $this->node);
    $this->passToModule([
      'entities' => [
        'node' => [$this->node->id()],
        'media' => [$other_media->id()],
      ],
    ]);

    $this->assertFalse($this->file->access($this->op));
    $this->assertFalse($other_media->access($this->op));
    $this->assertFalse($this->node->access($this->op));
    $this->assertFalse($this->media->access($this->op));
  }

  /**
   * Test one node allowing with two media, one denied.
   *
   * Path from to allowed node, should be allowed.
   */
  public function testBranch() {
    $other_media = $this->createMedia($this->file, $this->node);
    $this->passToModule([
      'entities' => [
        'media' => [$this->media->id()],
      ],
    ]);

    $this->assertTrue($this->file->access($this->op));
    $this->assertTrue($other_media->access($this->op));
    $this->assertTrue($this->node->access($this->op));
    $this->assertFalse($this->media->access($this->op));
  }

}
