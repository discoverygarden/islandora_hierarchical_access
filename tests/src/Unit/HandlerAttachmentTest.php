<?php

namespace Drupal\Tests\islandora_hierarchical_access\Unit;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\file\FileInterface;
use Drupal\islandora_hierarchical_access\EntityAccessHandler;
use Drupal\islandora_hierarchical_access\EntityCUDHandler;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit test attachment of our handlers to entity types.
 *
 * @group islandora_hierarchical_access
 */
class HandlerAttachmentTest extends UnitTestCase {

  /**
   * Helper; build out a mock to verify things are called as expected.
   *
   * XXX: Can't use the union in the in-code hint 'til PHP 8.
   * @return \Drupal\Core\Entity\EntityTypeInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked entity type object.
   */
  protected function getEntityTypeMock(string $class, string $interface) : EntityTypeInterface {
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
    $type_mock = $this->getEntityTypeMock($class, $interface);

    $tracker = new HandlerAttachmentTracker($set_values);

    $type_mock->expects($this->any())
      ->method('set')
      ->willReturnCallback(function (...$params) use ($type_mock, $tracker) {
        $tracker->matches($params);
        return $type_mock;
      });

    [$class, 'attach']($type_mock);

    $this->assertTrue($tracker->isFullyConsumed());
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
  public function attachmentProvider() : array {
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
          [
            EntityAccessHandler::PROPERTY_NAME__TARGET_OP_MAP,
            [
              'download' => 'view',
            ],
          ],
        ],
      ],
    ];
  }

}
