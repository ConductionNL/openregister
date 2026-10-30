<?php

/**
 * Asserts a hand-built factory supplies every optional object dependency.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

/**
 * A factory registered with registerService() overrides autowiring, so a
 * nullable constructor argument it leaves out is never supplied at all and
 * the service silently runs its "not wired" branch. That is how SchemaMapper
 * shipped without its declared-action validator (openregister#5143) and
 * WebhookMapper without its interception cache.
 *
 * The check reads the built service: for every nullable, object-typed
 * constructor parameter, the property of the same name must hold a value.
 * A parameter that is left out on purpose goes on the caller's allow-list
 * with the reason.
 */
trait FactoryWiringAssertions {

	/**
	 * Fail when the built service holds null for a nullable object dependency.
	 *
	 * @param object               $service    The service the factory built.
	 * @param array<string,string> $allowList  "Class::$param" => why the factory leaves it out.
	 * @param array<string,string> $properties "Class::$param" => the property that holds it, when its name differs.
	 *
	 * @return void
	 */
	private function assertFactorySuppliedEveryOptionalDependency(object $service, array $allowList=[], array $properties=[]): void {
		$class       = new ReflectionClass(objectOrClass: $service);
		$constructor = $class->getConstructor();
		if ($constructor === null) {
			return;
		}

		foreach ($constructor->getParameters() as $parameter) {
			if ($this->isOptionalObjectDependency(parameter: $parameter) === false) {
				continue;
			}

			$key = $class->getName().'::$'.$parameter->getName();
			if (array_key_exists($key, $allowList) === true) {
				continue;
			}

			$name = ($properties[$key] ?? $parameter->getName());
			$this->assertTrue(
				condition: $class->hasProperty($name),
				message: $key.' is optional but has no property $'.$name.' to check; map it to the property that holds it, or allow-list it with a reason.'
			);

			$property = $class->getProperty($name);
			$this->assertTrue(
				condition: $property->isInitialized($service) === true && $property->getValue($service) !== null,
				message: $key.' is never supplied by its factory: a registerService() factory overrides autowiring, so an omitted argument stays null.'
			);
		}//end foreach
	}//end assertFactorySuppliedEveryOptionalDependency()

	/**
	 * Whether a constructor parameter is a nullable dependency on an object.
	 *
	 * @param ReflectionParameter $parameter The parameter.
	 *
	 * @return bool True for `?Foo $x`, `Foo|null $x` and `?Closure $x`.
	 */
	private function isOptionalObjectDependency(ReflectionParameter $parameter): bool {
		$type = $parameter->getType();
		if ($type === null || $type->allowsNull() === false) {
			return false;
		}

		$types = [$type];
		if ($type instanceof ReflectionUnionType) {
			$types = $type->getTypes();
		}

		foreach ($types as $member) {
			if ($member instanceof ReflectionNamedType && $member->isBuiltin() === false) {
				return true;
			}
		}

		return false;
	}//end isOptionalObjectDependency()
}//end trait
