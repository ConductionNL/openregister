<?php

/**
 * OpenRegister ImplementationForm
 *
 * Tells which of two published signatures an extension point's implementer declares.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Kernel
 * @package  OCA\OpenRegister\Kernel\Compat
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Compat;

use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Whether an implementer's method takes a parameter object as its first argument.
 *
 * Several extension points (lifecycle actions, flow nodes, bulk actions,
 * integration providers) moved from positional arguments to one parameter
 * object. Apps implement either form, so the base interfaces no longer
 * declare that method and the engine asks this class which form an
 * implementer has before it calls it.
 *
 * @spec exclude Compatibility plumbing for extension-point signatures, not a capability of its own
 */
final class ImplementationForm {

	/**
	 * Answers already computed, keyed by class, method and parameter class.
	 *
	 * @var array<string, boolean>
	 */
	private static array $answers = [];

	/**
	 * Whether the first parameter of `$implementer->$method()` accepts an instance of `$parameterClass`.
	 *
	 * An untyped or `mixed` first parameter accepts it. A method without
	 * parameters, or whose first parameter is typed only with scalars, arrays
	 * or unrelated classes, does not: it is the positional form.
	 *
	 * @param object       $implementer    The implementer.
	 * @param string       $method         The method name; it must exist.
	 * @param class-string $parameterClass The parameter object's class.
	 *
	 * @return bool True for the parameter-object form.
	 */
	public function takesObject(object $implementer, string $method, string $parameterClass): bool {
		$key = $implementer::class.'::'.$method.'('.$parameterClass.')';
		if (isset(self::$answers[$key]) === false) {
			self::$answers[$key] = $this->firstParameterAccepts(
				reflection: new ReflectionMethod($implementer, $method),
				parameterClass: $parameterClass
			);
		}

		return self::$answers[$key];
	}//end takesObject()

	/**
	 * Whether a method's first parameter accepts an instance of a class.
	 *
	 * @param ReflectionMethod $reflection     The method.
	 * @param class-string     $parameterClass The parameter object's class.
	 *
	 * @return bool True when it does.
	 */
	private function firstParameterAccepts(ReflectionMethod $reflection, string $parameterClass): bool {
		$parameters = $reflection->getParameters();
		if ($parameters === []) {
			return false;
		}

		$type = $parameters[0]->getType();
		if ($type === null) {
			return true;
		}

		$named = [$type];
		if ($type instanceof ReflectionUnionType) {
			$named = $type->getTypes();
		}

		foreach ($named as $candidate) {
			if ($candidate instanceof ReflectionNamedType === false) {
				continue;
			}

			$name = $candidate->getName();
			if ($name === 'mixed' || $name === 'object' || is_a($parameterClass, $name, true) === true) {
				return true;
			}
		}

		return false;
	}//end firstParameterAccepts()
}//end class
