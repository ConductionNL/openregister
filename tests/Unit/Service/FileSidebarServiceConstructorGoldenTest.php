<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Files\Service\FileSidebarService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Golden test for the FileSidebarService constructor: which injected dependency ends up in which
 * property, compared with fixtures/file-sidebar-service-constructor-properties-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * Every dependency is a double named after its constructor parameter; a final class
 * among the parameters (a dependency bundle) is built for real from doubles in turn.
 */
class FileSidebarServiceConstructorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/file-sidebar-service-constructor-properties-golden.json';

	/** @var array<string, object> */
	private array $doubles = [];

	public function testEveryDependencyLandsInTheSameProperty(): void {
		$service = new FileSidebarService(...$this->argumentsFor(FileSidebarService::class));

		$landed = [];
		foreach ((new ReflectionClass(FileSidebarService::class))->getProperties() as $property) {
			if ($property->isStatic() === true || $property->isInitialized($service) === false) {
				continue;
			}

			$source = array_search($property->getValue($service), $this->doubles, true);
			if ($source !== false) {
				$landed[$property->getName()] = $source;
			}
		}

		ksort($landed);
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($landed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $landed);
	}//end testEveryDependencyLandsInTheSameProperty()

	/**
	 * The constructor arguments of a class, bundles built for real and the rest doubled.
	 *
	 * @param string $class The class to build arguments for.
	 *
	 * @return array<int, object>
	 */
	private function argumentsFor(string $class): array {
		$arguments = [];
		foreach ((new ReflectionMethod($class, '__construct'))->getParameters() as $parameter) {
			$type = (string)$parameter->getType();
			if (class_exists($type) === true && (new ReflectionClass($type))->isFinal() === true) {
				$arguments[] = new $type(...$this->argumentsFor($type));
				continue;
			}

			$double = $this->createMock(ltrim($type, '?'));
			$this->doubles[$parameter->getName()] = $double;
			$arguments[] = $double;
		}

		return $arguments;
	}//end argumentsFor()
}//end class
