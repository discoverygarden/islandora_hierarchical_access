<?php

namespace Drupal\Tests\islandora_hierarchical_access\Unit;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\file\FileInterface;
use Drupal\islandora_hierarchical_access\EntityAccessHandler;
use Drupal\islandora_hierarchical_access\EntityCUDHandler;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Unit test attachment of our handlers to entity types.
 */
class HandlerAttachmentTest extends UnitTestCase {

  /**
   * Helper; build out a mock to verify things are called as expected.
   */
  protected function getEntityTypeMock(string $class, string $interface, $set_values = []) : EntityTypeInterface {
    $builder = $this->getMockBuilder(EntityTypeInterface::class);
    $mock = $builder->getMock();

    $mock->expects($this->any())
      ->method('entityClassImplements')
      ->will($this->returnCallback(function ($arg) use ($interface) {
        return is_a($arg, $interface, TRUE);
      }));

    $mock->expects($this->once())
      ->method('hasHandlerClass')
      ->with($class::NAME)
      ->will($this->returnValue(FALSE));
    $mock->expects($this->once())
      ->method('setHandlerClass')
      ->with($class::NAME, $class)
      ->willReturnSelf();

    $mock->expects($this->atLeastOnce())
      ->method('set')
      // XXX: We do not strictly care about the order of the different set
      // calls; however, there does not appear to be a nice way to specify the
      // set of things to hit in an arbitrary order.
      ->withConsecutive(...$set_values)
      ->willReturnSelf();

    return $mock;
  }

  /**
   * Test attachment of our handler to the various target types.
   *
   * @param string $class
   *   The name of the handler class to test.
   * @param string $interface
   *   The type of entity against which to test our attachment.
   * @param array $set_values
   *   An associative array of properties to set on the type. Conceptually,
   *   might be thought of as "parameters" to the handler.
   *
   * @dataProvider attachmentProvider
   */
  public function testAttachments(string $class, string $interface, array $set_values = []) {
    $type_mock = $this->getEntityTypeMock($class, $interface, $set_values);
    [$class, 'attach']($type_mock);
  }

  /**
   * Data provider method.
   *
   * @return array[]
   *   An array of arrays, each containing:
   *   - the class of which to test the attachment
   *   - the type of entity against which to test the attachment
   *   - the values to be set on the type, which might conceptually considered
   *     parameters to the handler.
   */
  public function attachmentProvider() {
    return [
      [
        EntityCUDHandler::class,
        FileInterface::class,
        [
          [EntityCUDHandler::PROPERTY_NAME__COLUMN, 'fid'],
        ],
      ],
      [
        EntityCUDHandler::class,
        MediaInterface::class,
        [
          [EntityCUDHandler::PROPERTY_NAME__COLUMN, 'mid'],
          [
            EntityCUDHandler::PROPERTY_NAME__OPERATIONS,
            EntityCUDHandler::OPERATIONS_CREATE | EntityCUDHandler::OPERATIONS_UPDATE | EntityCUDHandler::OPERATIONS_DELETE,
          ],
        ],
      ],
      [
        EntityCUDHandler::class,
        NodeInterface::class,
        [
          [EntityCUDHandler::PROPERTY_NAME__COLUMN, 'nid'],
        ],
      ],
      [
        EntityAccessHandler::class,
        FileInterface::class,
        [
          [EntityAccessHandler::PROPERTY_NAME__COLUMN, 'fid'],
          [EntityAccessHandler::PROPERTY_NAME__TARGET_COLUMN, 'mid'],
          [EntityAccessHandler::PROPERTY_NAME__TARGET_TYPE, 'media'],
        ],
      ],
      [
        EntityAccessHandler::class,
        MediaInterface::class,
        [
          [EntityAccessHandler::PROPERTY_NAME__COLUMN, 'mid'],
          [EntityAccessHandler::PROPERTY_NAME__TARGET_COLUMN, 'nid'],
          [EntityAccessHandler::PROPERTY_NAME__TARGET_TYPE, 'node'],
        ],
      ],
    ];
  }

}
