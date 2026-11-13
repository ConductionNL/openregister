<?php

/**
 * CacheHandler clears, invalidates and counts as recorded.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CacheHandlerRuntime;
use OCA\OpenRegister\Service\Object\CacheHandlerStores;
use OCA\OpenRegister\Service\Object\CacheNameScopeSources;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionProperty;
use RuntimeException;
use Stringable;

/**
 * Walks each clearing call over filled memory, with distributed caches absent, working and failing.
 *
 * The recording was made on the code before these units were split.
 *
 * @covers \OCA\OpenRegister\Service\Object\CacheHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Service\Object\ObjectChange
 * @uses \OCA\OpenRegister\Service\Object\CacheHandlerRuntime
 * @uses \OCA\OpenRegister\Service\Object\CacheHandlerStores
 * @uses \OCA\OpenRegister\Service\Object\CacheNameScopeSources
 * @uses \OCA\OpenRegister\Service\Object\CachedName
 */
class CacheHandlerClearingGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/cache-handler-clearing-golden.json';

	/**
	 * Log lines and distributed calls of the current case.
	 *
	 * @var array<int, array>
	 */
	private array $trace = [];

	private function logger(): AbstractLogger {
		$trace  = &$this->trace;

		return new class($trace) extends AbstractLogger {
			public function __construct(private array &$trace) {
			}

			public function log($level, string|Stringable $message, array $context=[]): void {
				unset($context['file'], $context['line'], $context['executionTime']);
				ksort($context);
				$this->trace[] = [$level, (string) $message, $context];
			}
		};
	}//end logger()

	private function memcache(string $name, string $mode): IMemcache {
		$cache = $this->createStub(IMemcache::class);
		$cache->method('clear')->willReturnCallback(
			function () use ($name, $mode): bool {
				$this->trace[] = ["$name clear"];
				if ($mode === 'failing') {
					throw new RuntimeException('down');
				}

				return true;
			}
		);
		$cache->method('remove')->willReturnCallback(
			function (string $key) use ($name, $mode): bool {
				$this->trace[] = ["$name remove", $key];
				if ($mode === 'failing') {
					throw new RuntimeException('down');
				}

				return true;
			}
		);
		$cache->method('get')->willReturn(($mode === 'failing' ? null : 7));

		return $cache;
	}//end memcache()

	private function handler(string $mode): CacheHandler {
		$factory = null;
		if ($mode !== 'absent') {
			$factory = $this->createStub(ICacheFactory::class);
			$factory->method('createDistributed')->willReturnCallback(
				fn (string $prefix): IMemcache => $this->memcache($prefix, $mode)
			);
		}

		$handler = new CacheHandler(
			stores: new CacheHandlerStores(
				organisationMapper: $this->createStub(OrganisationMapperInterface::class)
			),
			runtime: new CacheHandlerRuntime(
				logger: $this->logger(),
				cacheFactory: $factory
			),
			nameScope: new CacheNameScopeSources(
				userSession: $this->createStub(IUserSession::class)
			)
		);

		$this->fill($handler);

		return $handler;
	}//end handler()

	private function fill(CacheHandler $handler): void {
		$values = [
			'objectCache'        => ['u-1' => 'x', 'u-2' => 'y'],
			'inMemoryQueryCache' => ['alpha-1' => [1], 'beta-1' => [2], 'alpha-2' => [3]],
			'nameCache'          => ['u-1' => 'One', '5' => 'One', 'u-2' => 'Two'],
			'nameOrganisations'  => ['u-1' => 'org', '5' => 'org'],
			'nameSources'        => ['u-1' => 'r/s'],
			'readabilityMemo'    => ['alice' => ['u-1' => true, '5' => false, 'u-2' => true]],
			'stats'              => ['hits' => 3, 'misses' => 1, 'preloads' => 2, 'query_hits' => 1, 'query_misses' => 2, 'name_hits' => 0, 'name_misses' => 0, 'name_warmups' => 4],
		];
		foreach ($values as $name => $value) {
			(new ReflectionProperty(CacheHandler::class, $name))->setValue($handler, $value);
		}
	}//end fill()

	private function memory(CacheHandler $handler): array {
		$memory = ['stats' => $handler->getStats()];
		foreach (['objectCache', 'inMemoryQueryCache', 'nameCache', 'nameOrganisations', 'nameSources', 'readabilityMemo'] as $name) {
			$memory[$name] = (new ReflectionProperty(CacheHandler::class, $name))->getValue($handler);
		}

		return $memory;
	}//end memory()

	private function object(bool $saved): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$object->setRegister('3');
		$object->setSchema('4');
		$object->setName('One renamed');
		$object->setOrganisation('org');
		if ($saved === true) {
			$object->setId(5);
		}

		return $object;
	}//end object()

	/**
	 * The calls.
	 *
	 * @return array<string, callable>
	 */
	private function calls(): array {
		$calls = [
			'nothing'      => static fn (CacheHandler $h) => null,
			'search all'   => static fn (CacheHandler $h) => $h->clearSearchCache(),
			'search alpha' => static fn (CacheHandler $h) => $h->clearSearchCache(pattern: 'alpha'),
			'all'          => static fn (CacheHandler $h) => $h->clearAllCaches(),
			'cache'        => static fn (CacheHandler $h) => $h->clearCache(),
			'names'        => static fn (CacheHandler $h) => $h->clearNameCache(),
			'no object'    => static fn (CacheHandler $h) => $h->invalidateForObjectChange(),
			'ids alone'    => static fn (CacheHandler $h) => $h->invalidateForObjectChange(operation: 'bulk', registerId: 8, schemaId: 9),
			'register'     => static fn (CacheHandler $h) => $h->invalidateForObjectChange(registerId: 8),
		];
		foreach (['create', 'update', 'delete', 'other'] as $operation) {
			foreach ([true, false] as $saved) {
				$object = $this->object($saved);
				$name   = "$operation ".($saved === true ? 'saved' : 'unsaved');
				$calls[$name] = static fn (CacheHandler $h) => $h->invalidateForObjectChange(object: $object, operation: $operation);
				$calls["$name own ids"] = static fn (CacheHandler $h) => $h->invalidateForObjectChange(object: $object, operation: $operation, registerId: 8, schemaId: 9);
			}
		}

		return $calls;
	}//end calls()

	private function record(): array {
		$recording = [];
		foreach (['absent', 'working', 'failing'] as $mode) {
			foreach ($this->calls() as $name => $call) {
				$handler     = $this->handler($mode);
				$this->trace = [];
				$call($handler);
				$recording["$mode|$name"] = ['trace' => $this->trace, 'memory' => $this->memory($handler)];
			}
		}

		return $recording;
	}//end record()

	public function testEveryClearingRunsAsRecorded(): void {
		$recording = json_decode(json_encode($this->record(), JSON_THROW_ON_ERROR), true);
		if (getenv('RECORD_GOLDEN') === 'cache-clearing') {
			file_put_contents(self::GOLDEN, json_encode($recording, JSON_UNESCAPED_SLASHES)."\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR), $recording);
	}//end testEveryClearingRunsAsRecorded()
}//end class
