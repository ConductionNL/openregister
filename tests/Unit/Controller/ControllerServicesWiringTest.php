<?php

/**
 * Wiring test for controllers that take a services bundle.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Every service in a controller's services bundle reaches the controller.
 *
 * A controller keeps a bundled service in its own property, reads it through the
 * bundle it keeps in $services, or hands it to an object it builds and keeps.
 */
class ControllerServicesWiringTest extends TestCase {
	/**
	 * Controllers whose constructor takes a Support\*ControllerServices bundle.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function controllerProvider(): array {
		$cases = [];
		foreach (glob(__DIR__ . '/../../../lib/{,*/}Controller/{,*/}*Controller.php', GLOB_BRACE) as $file) {
			$relative = substr($file, strpos($file, 'lib/') + strlen('lib/'), -4);
			$class    = 'OCA\\OpenRegister\\' . str_replace('/', '\\', $relative);
			foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $param) {
				$type = $param->getType();
				if ($type instanceof ReflectionNamedType
					&& self::isBundleName($type->getName()) === true
					&& str_ends_with($type->getName(), 'ControllerServices') === true
				) {
					$cases[$relative] = [$class];
				}
			}
		}

		return $cases;
	}//end controllerProvider()

	/**
	 * Each leaf service of the bundle is held by the controller or reachable through it.
	 *
	 * @param class-string $class The controller.
	 *
	 * @return void
	 *
	 * @dataProvider controllerProvider
	 */
	public function testEveryBundledServiceReachesTheController(string $class): void {
		$leaves = [];
		$args   = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $param) {
			$type = $param->getType();
			if ($type instanceof ReflectionNamedType === false || $type->isBuiltin() === true) {
				$args[] = 'openregister';
				continue;
			}

			$args[] = $this->standIn(type: $type->getName(), leaves: $leaves);
		}

		$controller = new $class(...$args);
		$held       = [];
		foreach ((new ReflectionClass($class))->getProperties() as $property) {
			if ($property->isInitialized($controller) === false) {
				continue;
			}

			$value = $property->getValue($controller);
			if (is_object($value) === true) {
				$held[] = $value;
			}
		}

		foreach ($leaves as $name => $leaf) {
			$this->assertTrue(condition: $this->reaches(held: $held, leaf: $leaf), message: $class . ' loses ' . $name);
		}
	}//end testEveryBundledServiceReachesTheController()

	/**
	 * A double for a constructor argument; a final class is built from doubles of its own arguments.
	 *
	 * @param class-string         $type   The argument type.
	 * @param array<string,object> $leaves Collects every service double a bundle holds, by bundle and field.
	 *
	 * @return object
	 */
	private function standIn(string $type, array &$leaves): object {
		$reflection = new ReflectionClass($type);
		if ($reflection->isFinal() === false) {
			return $this->createMock(originalClassName: $type);
		}

		$args = [];
		foreach ($reflection->getConstructor()?->getParameters() ?? [] as $param) {
			$paramType = $param->getType();
			if ($paramType instanceof ReflectionNamedType === false || $paramType->isBuiltin() === true) {
				$args[] = null;
				continue;
			}

			$value  = $this->standIn(type: $paramType->getName(), leaves: $leaves);
			$args[] = $value;
			if ($this->isBundle(type: $type) === true && $this->isBundle(type: $paramType->getName()) === false) {
				$leaves[$reflection->getShortName() . '::$' . $param->getName()] = $value;
			}
		}

		return $reflection->newInstanceArgs($args);
	}//end standIn()

	/**
	 * Whether the type is a services bundle.
	 *
	 * @param string $type The class name.
	 *
	 * @return bool
	 */
	private function isBundle(string $type): bool {
		return self::isBundleName($type);
	}//end isBundle()

	/**
	 * Whether the class name lies in a Controller\Support namespace, of lib/Controller or of a component.
	 *
	 * @param string $type The class name.
	 *
	 * @return bool
	 */
	private static function isBundleName(string $type): bool {
		return preg_match('/^OCA\\\\OpenRegister\\\\(\\w+\\\\)?Controller\\\\Support\\\\/', $type) === 1;
	}//end isBundleName()

	/**
	 * Whether the leaf is one of the held objects or inside a held bundle.
	 *
	 * @param array<int,object> $held The objects the controller holds.
	 * @param object            $leaf The bundled service double.
	 *
	 * @return bool
	 */
	private function reaches(array $held, object $leaf): bool {
		foreach ($held as $object) {
			if ($object === $leaf) {
				return true;
			}

			if ($this->isBundle(type: $object::class) === true
				&& $this->reaches(held: array_values(get_object_vars($object)), leaf: $leaf) === true
			) {
				return true;
			}

			if ($this->isBundle(type: $object::class) === false
				&& str_starts_with($object::class, 'OCA\\OpenRegister\\') === true
				&& in_array(needle: $leaf, haystack: $this->propertiesOf(object: $object), strict: true) === true
			) {
				return true;
			}
		}

		return false;
	}//end reaches()

	/**
	 * The values of an object's initialised properties, private ones included.
	 *
	 * @param object $object The object a controller built from bundled services.
	 *
	 * @return array<int,mixed>
	 */
	private function propertiesOf(object $object): array {
		$values = [];
		foreach ((new ReflectionClass($object))->getProperties() as $property) {
			if ($property->isInitialized($object) === true) {
				$values[] = $property->getValue($object);
			}
		}

		return $values;
	}//end propertiesOf()
}//end class
