<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\FileService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Golden test for the FileService constructor: the debug log lines and the handlers
 * that receive the service through setFileService(), in order, are compared with
 * fixtures/file-service-constructor-golden.json, and which injected dependency ends up
 * in which property with fixtures/file-service-constructor-properties-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite them.
 *
 * Every dependency is a double named after its constructor parameter; a final class
 * among the parameters (a dependency bundle) is built for real from doubles in turn.
 */
class FileServiceConstructorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/file-service-constructor-golden.json';

	private const PROPERTIES_FIXTURE = __DIR__ . '/fixtures/file-service-constructor-properties-golden.json';

	/** @var array<int, array<int, mixed>> */
	private array $calls = [];

	/** @var array<string, object> */
	private array $doubles = [];

	public function testConstructorWiringMatchesGolden(): void {
		new FileService(...$this->argumentsFor(FileService::class));

		$this->assertMatchesFixture(self::FIXTURE, $this->calls);
	}//end testConstructorWiringMatchesGolden()

	public function testEveryDependencyLandsInTheSameProperty(): void {
		$service = new FileService(...$this->argumentsFor(FileService::class));

		$landed = [];
		foreach ((new ReflectionClass(FileService::class))->getProperties() as $property) {
			if ($property->isStatic() === true || $property->isInitialized($service) === false) {
				continue;
			}

			$value = $property->getValue($service);
			$source = array_search($value, $this->doubles, true);
			if (is_object($value) === true && $source !== false) {
				$landed[$property->getName()] = $source;
			}
		}

		$this->assertMatchesFixture(self::PROPERTIES_FIXTURE, $landed);
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

			$arguments[] = $this->doubleFor($type, $parameter->getName());
		}

		return $arguments;
	}//end argumentsFor()

	private function doubleFor(string $type, string $name): object {
		$calls = &$this->calls;
		$double = $this->createMock($type);
		if ($type === LoggerInterface::class) {
			$double->method('debug')->willReturnCallback(static function (string $message, array $context = []) use (&$calls): void {
				unset($context['file'], $context['line']);
				$calls[] = ['debug', $message, $context];
			});
		} elseif (method_exists($type, 'setFileService') === true) {
			$double->method('setFileService')->willReturnCallback(static function ($service) use (&$calls, $name): void {
				$calls[] = ['setFileService', $name, $service instanceof FileService];
			});
		}

		$this->doubles[$name] = $double;
		return $double;
	}//end doubleFor()

	/**
	 * Compare a recording with its fixture, or rewrite the fixture when asked.
	 *
	 * @param string       $fixture The fixture path.
	 * @param array<mixed> $actual  The recording.
	 *
	 * @return void
	 */
	private function assertMatchesFixture(string $fixture, array $actual): void {
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents($fixture, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string)file_get_contents($fixture), true), $actual);
	}//end assertMatchesFixture()
}//end class
