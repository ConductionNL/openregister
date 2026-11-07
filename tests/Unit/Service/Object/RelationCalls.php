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
use OCA\OpenRegister\Service\Object\SaveObject\CascadeRun;
use OCA\OpenRegister\Service\Object\SaveObject\InverseRelationWrite;
use OCA\OpenRegister\Service\Object\SaveObject\RelationScanner;
use ReflectionMethod;

/**
 * Stands in for the private SaveObject entries the relation tests call.
 */
final class RelationCalls {

	public const METHODS = ['isReference', 'updateObjectRelations', 'updateInverseRelations', 'handleInverseRelationsWriteBack'];

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

	/**
	 * Write the inverse relations of a saved object: entity, register, schema.
	 *
	 * @param SaveObject $handler The handler.
	 * @param array      $args    The arguments, in that order.
	 *
	 * @return void
	 */
	public static function updateInverseRelations(SaveObject $handler, array $args): void {
		[$entity, $register, $schema] = array_values($args);
		$inverse = (new ReflectionMethod(SaveObject::class, 'inverseRelations'))->invoke($handler);
		$inverse->update(saved: $entity, write: new InverseRelationWrite(register: $register, schema: $schema));
	}//end updateInverseRelations()

	/**
	 * Write a saved object back into its targets: entity, schema, data.
	 *
	 * @param SaveObject $handler The handler.
	 * @param array      $args    The arguments, in that order.
	 *
	 * @return array The data.
	 */
	public static function handleInverseRelationsWriteBack(SaveObject $handler, array $args): array {
		[$entity, $schema, $data] = array_values($args);
		$run = new CascadeRun();
		$run->parent = $entity;
		$run->data = $data;
		$writeBack = (new ReflectionMethod(SaveObject::class, 'inverseWriteBack'))->invoke($handler);
		return $writeBack->write(run: $run, schema: $schema);
	}//end handleInverseRelationsWriteBack()
}//end class
