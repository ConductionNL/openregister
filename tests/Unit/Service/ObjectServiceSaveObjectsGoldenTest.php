<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\Object\BulkSaveOptions;
use Exception;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\ObjectServiceFactory;

/**
 * Pins what ObjectService::saveObjects() forwards to the bulk handler and how it invalidates
 * caches afterwards.
 *
 * @covers \OCA\OpenRegister\Service\ObjectService
 * @uses   \OCA\OpenRegister\Service\RegisterScopedSchemaResolver
 * @uses   \OCA\OpenRegister\Service\Object\ObjectSaveRun
 * @uses   \OCA\OpenRegister\Service\Object\BulkCacheInvalidator
 * @uses   \OCA\OpenRegister\Service\Object\SavedScopeIds
 */
class ObjectServiceSaveObjectsGoldenTest extends TestCase {

	/**
	 * @var list<array>
	 */
	private array $calls = [];

	/**
	 * Builds the service for one scenario.
	 *
	 * @param array $s Scenario settings.
	 *
	 * @return ObjectService
	 */
	private function service(array $s): ObjectService {
		$bulk = $this->createMock(SaveObjects::class);
		$bulk->method('saveObjects')->willReturnCallback(function (array $objects, BulkSaveOptions $options, bool $_rbac, bool $_multitenancy) use ($s) {
			$args = [$objects, $options->defaults->register, $options->defaults->schema, $_rbac, $_multitenancy, $options->validation, $options->events, $options->audit];
			$this->calls[] = ['bulk', $args];
			return ['statistics' => $s['stats'], 'saved' => ['x']];
		});
		$cache = $this->createMock(CacheHandler::class);
		$cache->method('invalidateForObjectChange')->willReturnCallback(function (...$args) use ($s) {
			$this->calls[] = ['invalidate', $args];
			if ($s['cacheFails'] === true) {
				throw new Exception('cache down');
			}
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function ($message, array $context = []) {
			$this->calls[] = ['warning', $message, $context];
		});

		$given = ['saveObjectsHandler' => $bulk, 'cacheHandler' => $cache, 'logger' => $logger];
		$args = [];
		foreach ((new ReflectionMethod(ObjectServiceFactory::class, 'args'))->getParameters() as $param) {
			$name = $param->getName();
			if (isset($given[$name]) === true) {
				$args[$name] = $given[$name];
			} elseif ($param->getType() !== null && $param->getType()->isBuiltin() === false) {
				$args[$name] = $this->createMock($param->getType()->getName());
			}
		}
		return new ObjectService(...ObjectServiceFactory::args(...$args));
	}

	/**
	 * Scenario table.
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		$base = ['stats' => ['objectsCreated' => 2, 'objectsUpdated' => 1], 'cacheFails' => false, 'args' => []];
		return [
			'created and updated' => [$base],
			'only updated' => [['stats' => ['objectsUpdated' => '3']] + $base],
			'one created' => [['stats' => ['objectsCreated' => 1]] + $base],
			'nothing changed' => [['stats' => ['objectsCreated' => 0, 'objectsUpdated' => 0]] + $base],
			'no statistics' => [['stats' => []] + $base],
			'invalidation fails' => [['cacheFails' => true] + $base],
			'all flags' => [['args' => ['options' => new BulkSaveOptions(validation: true, events: true, audit: false), '_rbac' => false, '_multitenancy' => false]] + $base],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testSaveObjectsGolden(array $s): void {
		$result = $this->service($s)->saveObjects(...(['objects' => [['a' => 1]]] + $s['args']));
		$this->calls[] = ['result', $result];

		$file = __DIR__ . '/fixtures/object-service-save-objects-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		$actual = json_decode(json_encode($this->calls), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}
		$this->assertSame($all[$name], $actual);
	}
}
