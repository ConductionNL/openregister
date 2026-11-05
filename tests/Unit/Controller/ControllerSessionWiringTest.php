<?php

/**
 * Wiring test for controllers that take ControllerSession.
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

use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Every controller that takes a ControllerSession keeps the bundle, or its user
 * session, group manager and logger, in the properties its actions read.
 */
class ControllerSessionWiringTest extends TestCase {

	/**
	 * The controllers that take a ControllerSession.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function controllerProvider(): array {
		$cases = [];
		foreach (glob(__DIR__ . '/../../../lib/{,*/}Controller/{,*/}*Controller.php', GLOB_BRACE) as $file) {
			$relative = substr($file, strpos($file, 'lib/') + strlen('lib/'), -4);
			$class    = 'OCA\\OpenRegister\\' . str_replace('/', '\\', $relative);
			$ctor     = (new ReflectionClass($class))->getConstructor();
			foreach ($ctor?->getParameters() ?? [] as $param) {
				$type = $param->getType();
				if ($type instanceof ReflectionNamedType && $type->getName() === ControllerSession::class) {
					$cases[$relative] = [$class];
				}
			}
		}

		return $cases;
	}//end controllerProvider()

	/**
	 * The bundle's services end up in the controller's own properties.
	 *
	 * @param class-string $class The controller.
	 *
	 * @return void
	 *
	 * @dataProvider controllerProvider
	 */
	public function testTheBundleIsWiredIntoTheController(string $class): void {
		$services = [
			IUserSession::class    => $this->createMock(IUserSession::class),
			IGroupManager::class   => $this->createMock(IGroupManager::class),
			LoggerInterface::class => $this->createMock(LoggerInterface::class),
		];
		$session  = new ControllerSession(...array_values($services));
		$args     = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $param) {
			$type = $param->getType();
			if ($param->isDefaultValueAvailable() === true) {
				break;
			}

			if ($type instanceof ReflectionNamedType === false || $type->isBuiltin() === true) {
				$args[] = 'openregister';
				continue;
			}

			$args[] = $type->getName() === ControllerSession::class ? $session : $this->standIn($type->getName());
		}

		$controller = new $class(...$args);
		$wired      = 0;
		foreach ((new ReflectionClass($class))->getProperties() as $property) {
			$type = $property->getType();
			if ($type instanceof ReflectionNamedType && $type->getName() === ControllerSession::class) {
				$this->assertSame($session, $property->getValue($controller), $class . '::$' . $property->getName());
				$wired++;
				continue;
			}

			if ($type instanceof ReflectionNamedType && isset($services[$type->getName()]) === true
				&& $property->getDeclaringClass()->getName() === $class
			) {
				$this->assertSame($services[$type->getName()], $property->getValue($controller), $class . '::$' . $property->getName());
				$wired++;
			}
		}

		$this->assertGreaterThan(0, $wired, $class . ' keeps none of the bundled services');
	}//end testTheBundleIsWiredIntoTheController()

	/**
	 * A test double for a constructor argument; a final services bundle is built from doubles of its own arguments.
	 *
	 * @param class-string $type The argument type.
	 *
	 * @return object
	 */
	private function standIn(string $type): object {
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

			$args[] = $this->standIn(type: $paramType->getName());
		}

		return $reflection->newInstanceArgs($args);
	}//end standIn()
}//end class
