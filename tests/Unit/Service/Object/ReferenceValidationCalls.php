<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reaches the reference validation of a SaveObject handler in the shape the tests state it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceCheck;
use OCA\OpenRegister\Service\Object\SaveObject\ReferencePropertyRun;
use ReflectionMethod;

/**
 * Stands in for the two private SaveObject entries the reference tests call.
 */
final class ReferenceValidationCalls {

	/**
	 * Run the references of one write: schema, data, register, old data.
	 *
	 * @param SaveObject $handler The handler.
	 * @param array      $args    The arguments, in that order.
	 *
	 * @return void
	 */
	public static function validateReferences(SaveObject $handler, array $args): void {
		[$schema, $data, $register] = array_values($args);
		$run = ReferencePropertyRun::forSave(data: $data, register: $register);
		$run->oldData = (array_values($args)[3] ?? null);
		(new ReflectionMethod(SaveObject::class, 'validateReferences'))->invoke($handler, $schema, $run);
	}//end validateReferences()

	/**
	 * Check one reference: property, uuid, schema reference, register.
	 *
	 * @param SaveObject $handler The handler.
	 * @param array      $args    The arguments, in that order.
	 *
	 * @return void
	 */
	public static function validateReferenceExists(SaveObject $handler, array $args): void {
		$check = new ReferenceCheck();
		[$check->propertyName, $check->uuid, $check->schemaRef, $check->register] = array_values($args);
		(new ReflectionMethod(SaveObject::class, 'referenceExistence'))->invoke($handler)->assertExists(check: $check);
	}//end validateReferenceExists()
}//end class
