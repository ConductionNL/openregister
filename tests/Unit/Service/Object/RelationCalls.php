<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reaches the relation scanning of a SaveObject handler in the shape the tests state it.
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
use OCA\OpenRegister\Service\Object\SaveObject\RelationScanner;

/**
 * Stands in for the private SaveObject entries the relation tests call.
 */
final class RelationCalls {

	public const METHODS = ['isReference', 'updateObjectRelations'];

	/**
	 * Whether a value looks like a reference: value.
	 *
	 * @param SaveObject $handler The handler.
	 * @param array      $args    The arguments.
	 *
	 * @return bool The verdict.
	 */
	public static function isReference(SaveObject $handler, array $args): bool {
		return RelationScanner::isReference(value: array_values($args)[0]);
	}//end isReference()

	/**
	 * Set an object's relations from its data: entity, data, schema.
	 *
	 * @param SaveObject $handler The handler.
	 * @param array      $args    The arguments, in that order.
	 *
	 * @return ObjectEntity The entity.
	 */
	public static function updateObjectRelations(SaveObject $handler, array $args): ObjectEntity {
		[$entity, $data] = array_values($args);
		$entity->setRelations($handler->scanForRelations(data: $data, prefix: '', schema: (array_values($args)[2] ?? null)));

		return $entity;
	}//end updateObjectRelations()
}//end class
