<?php

namespace Drupal\Tests\islandora_hierarchical_access\Kernel;

use Drupal\Tests\user\Traits\UserCreationTrait;

class AccessControlTest extends AbstractKernelTestBase {
  const KEY = 'islandora_hierarchical_access_access_control_test_key';

  use UserCreationTrait;

  public function testBaseAccess() {
    $node = $this->createNode();
    $file = $this->createFile();
    $media = $this->createMedia($file, $node);
    $user = $this->createUser();

    $this->assertTrue($node->access('view', $user));
    $this->assertTrue($file->access('view', $user));
    $this->assertTrue($media->access('view', $user));
  }
}
