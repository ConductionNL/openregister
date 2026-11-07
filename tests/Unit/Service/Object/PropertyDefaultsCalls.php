<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reaches the schema defaults of a SaveObject handler in the shape the tests state them.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\DefaultValueContext;
use OCA\OpenRegister\Service\Object\SaveObject\PropertyDefaults;
use ReflectionMethod;

/**
 * Stands in for the private SaveObject entries the default value tests call.
 */
final class PropertyDefaultsCalls {

	public const METHODS = ['setDefaultValues', 'shouldApplyDefault', 'resolveDefaultTemplateValue'];

	/**
	 * Apply the defaults of one save: entity, schema, data.
	 *
	 * @param SaveObject $handler The handler.
	 * @param array      $args    The arguments, in that order.
	 *
	 * @return array The data.
	 */
	public static function setDefaultValues(SaveObject $handler, array $args): array {
		[$entity, $schema, $data] = array_values($args);
		return self::defaults(handler: $handler)->forSave(
			schema: $schema,
			context: DefaultValueContext::ofSave(objectEntity: $entity, data: $data)
		);
	}//end setDefaultValues()

	/**
	 * Whether a default applies: behaviour, data, key.
	 *
	 * @param SaveObject $handler The handler.
	 * @param array      $args    The arguments, in that order.
	 *
	 * @return bool The verdict.
	 */
	public static function shouldApplyDefault(SaveObject $handler, array $args): bool {
		[$behavior, $data, $key] = array_values($args);
		return (new ReflectionMethod(PropertyDefaults::class, 'applies'))->invoke(
			null,
			['title' => $key, 'defaultBehavior' => $behavior],
			$data
		);
	}//end shouldApplyDefault()

	/**
	 * Resolve one default: value, context, schema properties.
	 *
	 * @param SaveObject $handler The handler.
	 * @param array      $args    The arguments, in that order.
	 *
	 * @return mixed The value.
	 */
	public static function resolveDefaultTemplateValue(SaveObject $handler, array $args): mixed {
		[$default, $context, $properties] = array_values($args);
		return (new ReflectionMethod(PropertyDefaults::class, 'renderOrNull'))->invoke(
			self::defaults(handler: $handler),
			new DefaultValueContext(data: $context, twigContext: $context, schemaProperties: $properties),
			$default
		);
	}//end resolveDefaultTemplateValue()

	/**
	 * The defaults collaborator of a handler.
	 *
	 * @param SaveObject $handler The handler.
	 *
	 * @return PropertyDefaults The collaborator.
	 */
	private static function defaults(SaveObject $handler): PropertyDefaults {
		return (new ReflectionMethod(SaveObject::class, 'propertyDefaults'))->invoke($handler);
	}//end defaults()
}//end class
