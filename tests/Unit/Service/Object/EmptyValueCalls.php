<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reaches the empty-value rules of a SaveObject handler in the shape the tests state them.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\EmptyValues;
use ReflectionMethod;

/**
 * Stands in for the private SaveObject entries the empty-value tests call.
 */
final class EmptyValueCalls {

	public const METHODS = ['sanitizeEmptyStringsForObjectProperties', 'isEffectivelyEmptyObject', 'isValueNotEmpty'];

	/**
	 * Object data with empty values handled: data, schema.
	 *
	 * @param SaveObject $handler The handler.
	 * @param array      $args    The arguments, in that order.
	 *
	 * @return array The data.
	 */
	public static function sanitizeEmptyStringsForObjectProperties(SaveObject $handler, array $args): array {
		[$data, $schema] = array_values($args);
		return self::of($handler)->sanitize(data: $data, schema: $schema);
	}//end sanitizeEmptyStringsForObjectProperties()

	/**
	 * Whether an object is effectively empty: object.
	 *
	 * @param SaveObject $handler The handler.
	 * @param array      $args    The arguments.
	 *
	 * @return bool The verdict.
	 */
	public static function isEffectivelyEmptyObject(SaveObject $handler, array $args): bool {
		return self::of($handler)->isEmptyObject(object: array_values($args)[0]);
	}//end isEffectivelyEmptyObject()

	/**
	 * Whether a value is not empty: value.
	 *
	 * @param SaveObject $handler The handler.
	 * @param array      $args    The arguments.
	 *
	 * @return bool The verdict.
	 */
	public static function isValueNotEmpty(SaveObject $handler, array $args): bool {
		return self::of($handler)->hasContent(value: array_values($args)[0]);
	}//end isValueNotEmpty()

	/**
	 * The handler's empty-value rules.
	 *
	 * @param SaveObject $handler The handler.
	 *
	 * @return EmptyValues
	 */
	private static function of(SaveObject $handler): EmptyValues {
		return (new ReflectionMethod(SaveObject::class, 'emptyValues'))->invoke($handler);
	}//end of()
}//end class
