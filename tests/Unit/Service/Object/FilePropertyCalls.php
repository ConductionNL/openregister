<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reaches the file properties of a SaveObject handler in the shape the tests state them.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectFileProperties;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectFileWrite;
use ReflectionMethod;

/**
 * Stands in for the private SaveObject entries the file property tests call.
 */
final class FilePropertyCalls {

	public const METHODS = ['processFilePropertiesWithRollback', 'clearImageMetadataIfFileProperty'];

	/**
	 * Store the file properties of a created object: entity, data by reference, register, schema.
	 *
	 * @param SaveObject $handler The handler.
	 * @param array      $args    The arguments, in that order.
	 *
	 * @return ObjectEntity The object.
	 */
	public static function processFilePropertiesWithRollback(SaveObject $handler, array $args): ObjectEntity {
		$args = array_values($args);
		$write = new ObjectFileWrite();
		$write->entity = $args[0];
		$write->data = $args[1];
		$write->register = $args[2];
		$write->schema = $args[3];

		try {
			return self::of($handler)->storeForCreate(write: $write);
		} finally {
			$args[1] = $write->data;
		}
	}//end processFilePropertiesWithRollback()

	/**
	 * Clear the image metadata: entity, schema.
	 *
	 * @param SaveObject $handler The handler.
	 * @param array      $args    The arguments, in that order.
	 *
	 * @return void
	 */
	public static function clearImageMetadataIfFileProperty(SaveObject $handler, array $args): void {
		[$entity, $schema] = array_values($args);
		self::of($handler)->clearImage(object: $entity, schema: $schema);
	}//end clearImageMetadataIfFileProperty()

	/**
	 * The handler's file properties.
	 *
	 * @param SaveObject $handler The handler.
	 *
	 * @return ObjectFileProperties
	 */
	private static function of(SaveObject $handler): ObjectFileProperties {
		return (new ReflectionMethod(SaveObject::class, 'objectFiles'))->invoke($handler);
	}//end of()
}//end class
