<?php

/**
 * CacheHandler counts its object and name lookups for the metrics endpoint
 * (production-observability, "Cache hit ratio").
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-performance-baseline-metrics
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CacheHandlerRuntime;
use OCA\OpenRegister\Service\Object\CacheHandlerStores;
use OCA\OpenRegister\Service\Object\CacheNameScopeSources;
use OCA\OpenRegister\Service\Observability\RequestMetricsRecorder;
use OCP\AppFramework\IAppContainer;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Object\CacheHandler
 * @uses \OCA\OpenRegister\Service\Object\CacheHandlerRuntime
 * @uses \OCA\OpenRegister\Service\Object\CacheHandlerStores
 * @uses \OCA\OpenRegister\Service\Object\CacheNameScopeSources
 * @uses \OCA\OpenRegister\Service\Object\CachedName
 * @uses \OCA\OpenRegister\Service\Object\NameLookupRun
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
final class CacheHandlerLookupMetricsTest extends TestCase {

	/**
	 * Every recordCacheLookup() call, as [cache, hit, count].
	 *
	 * @var array<int, array{0: string, 1: bool, 2: int}>
	 */
	private array $lookups = [];

	/**
	 * The object mapper behind the lazy container.
	 *
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper&MockObject $objectMapper;

	/**
	 * The distributed name cache.
	 *
	 * @var IMemcache&MockObject
	 */
	private IMemcache&MockObject $names;

	/**
	 * The organisation mapper.
	 *
	 * @var OrganisationMapper&MockObject
	 */
	private OrganisationMapper&MockObject $organisations;

	protected function setUp(): void {
		parent::setUp();
		$this->lookups       = [];
		$this->objectMapper  = $this->createMock(MagicMapper::class);
		$this->objectMapper->method('filterReadableUuids')
			->willReturnCallback(static fn (int $registerId, int $schemaId, array $uuids): array => array_values($uuids));
		$this->names         = $this->createMock(IMemcache::class);
		$this->organisations = $this->createMock(OrganisationMapper::class);
	}//end setUp()

	public function testAnObjectLookupCountsAMissThenAHit(): void {
		$this->objectMapper->expects($this->once())->method('find')->willReturn($this->entity());
		$handler = $this->handler(recorder: $this->recorder());

		$handler->getObject(1);
		$handler->getObject(1);

		$this->assertSame([['object', false, 1], ['object', true, 1]], $this->lookups);
	}//end testAnObjectLookupCountsAMissThenAHit()

	public function testASingleNameCountsAHitFromMemoryOrTheDistributedCacheAndAMissOtherwise(): void {
		$this->names->method('get')->willReturnCallback(
			static fn (string $key): ?array => $key === 'name_uuid-dist' ? ['n' => 'Distributed', 'o' => null, 's' => '1:1'] : null
		);
		$this->organisations->method('findByUuid')->willThrowException(new Exception('none'));
		$handler = $this->handler(recorder: $this->recorder());
		$handler->setObjectName('uuid-mem', 'Memory', source: '1:1');

		$this->assertSame('Memory', $handler->getSingleObjectName('uuid-mem'));
		$this->assertSame('Distributed', $handler->getSingleObjectName('uuid-dist'));
		$handler->getSingleObjectName('uuid-gone');

		$this->assertSame([['name', true, 1], ['name', true, 1], ['name', false, 1]], $this->lookups);
	}//end testASingleNameCountsAHitFromMemoryOrTheDistributedCacheAndAMissOtherwise()

	public function testABulkNameLookupCountsItsHitsAndMissesOnce(): void {
		$this->names->method('get')->willReturnCallback(
			static fn (string $key): ?array => $key === 'name_uuid-2' ? ['n' => 'Two', 'o' => null, 's' => '1:1'] : null
		);
		$this->organisations->method('findMultipleByUuid')->willReturn([]);
		$this->objectMapper->method('findMultiple')->willReturn([]);
		$handler = $this->handler(recorder: $this->recorder());
		$handler->setObjectName('uuid-1', 'One', source: '1:1');

		$handler->getMultipleObjectNames(['uuid-1', 'uuid-2', 'uuid-3', 'uuid-4']);

		$this->assertSame([['name', true, 2], ['name', false, 2]], $this->lookups);
	}//end testABulkNameLookupCountsItsHitsAndMissesOnce()

	public function testABulkLookupAnsweredFromMemoryCountsNoMiss(): void {
		$handler = $this->handler(recorder: $this->recorder());
		$handler->setObjectName('uuid-1', 'One', source: '1:1');

		$handler->getMultipleObjectNames(['uuid-1']);

		$this->assertSame([['name', true, 1]], $this->lookups);
	}//end testABulkLookupAnsweredFromMemoryCountsNoMiss()

	public function testAFailingRecorderNeverFailsTheLookup(): void {
		$recorder = $this->createMock(RequestMetricsRecorder::class);
		$recorder->method('recordCacheLookup')->willThrowException(new RuntimeException('apcu gone'));
		$this->objectMapper->method('find')->willReturn($this->entity());

		$this->assertInstanceOf(ObjectEntity::class, $this->handler(recorder: $recorder)->getObject(1));
	}//end testAFailingRecorderNeverFailsTheLookup()

	public function testWithoutARecorderLookupsStillWork(): void {
		$this->objectMapper->method('find')->willReturn($this->entity());
		$handler = $this->handler(recorder: null);

		$this->assertInstanceOf(ObjectEntity::class, $handler->getObject(1));
		$this->assertSame(1, $handler->getStats()['misses']);
	}//end testWithoutARecorderLookupsStillWork()

	private function recorder(): RequestMetricsRecorder {
		$recorder = $this->createMock(RequestMetricsRecorder::class);
		$recorder->method('recordCacheLookup')->willReturnCallback(
			function (string $cache, bool $hit, int $count = 1): void {
				$this->lookups[] = [$cache, $hit, $count];
			}
		);

		return $recorder;
	}//end recorder()

	private function handler(?RequestMetricsRecorder $recorder): CacheHandler {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturnCallback(
			fn (string $prefix): IMemcache => $prefix === 'openregister_object_names' ? $this->names : $this->createMock(IMemcache::class)
		);
		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturnCallback(
			fn (string $class): object => $class === MagicMapperInterface::class ? $this->objectMapper : $this->createMock($class)
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'multitenancy' ? '{"enabled":false}' : $default
		);

		return new CacheHandler(
			stores: new CacheHandlerStores(organisationMapper: $this->organisations, registerMapper: null, schemaMapper: null, db: null),
			runtime: new CacheHandlerRuntime(
				logger: $this->createMock(LoggerInterface::class),
				cacheFactory: $cacheFactory,
				container: $container,
				metrics: $recorder
			),
			nameScope: new CacheNameScopeSources(userSession: $session, groupManager: null, appConfig: $appConfig)
		);
	}//end handler()

	private function entity(): ObjectEntity {
		$entity = new ObjectEntity();
		$id     = (new ReflectionClass($entity))->getProperty('id');
		$id->setValue($entity, 1);
		$entity->setUuid('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
		$entity->setRegister('1');
		$entity->setSchema('1');
		return $entity;
	}//end entity()
}//end class
