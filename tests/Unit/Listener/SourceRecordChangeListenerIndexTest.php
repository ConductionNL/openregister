<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Listener\SourceRecordChangeListener;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\Merge\MasterRecomputeService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Listener\Support\SourceRecordChangeServices;

/**
 * The reverse-FK index behind the source-record listener: how it is built,
 * cached, invalidated, and which declarations count.
 *
 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#4.1
 */
class SourceRecordChangeListenerIndexTest extends TestCase {

	/**
	 * Schema mapper.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private $schemaMapper;

	/**
	 * Distributed cache.
	 *
	 * @var ICache&MockObject
	 */
	private $cache;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * Recompute service.
	 *
	 * @var MasterRecomputeService&MockObject
	 */
	private $recompute;

	/**
	 * Masters recomputed inline, in order.
	 *
	 * @var array<int, string>
	 */
	private array $recomputed = [];

	/**
	 * Build the doubles; deferral is off so recomputes are observable inline.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$this->cache = $this->createMock(ICache::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->recompute = $this->createMock(MasterRecomputeService::class);
		$this->recompute->method('recompute')->willReturnCallback(
			function (string $masterUuid): void {
				$this->recomputed[] = $masterUuid;
			}
		);
	}//end setUp()

	/**
	 * The listener on the doubles.
	 *
	 * @return SourceRecordChangeListener The listener.
	 */
	private function listener(): SourceRecordChangeListener {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($this->cache);
		$deferral = $this->createMock(ListenerDeferralService::class);
		$deferral->method('isDeferralEnabled')->willReturn(false);

		return new SourceRecordChangeListener(new SourceRecordChangeServices($this->schemaMapper, $deferral, $this->recompute), $this->logger, $factory);
	}//end listener()

	/**
	 * A schema with a slug and configuration.
	 *
	 * @param string $slug The slug.
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return Schema The schema.
	 */
	private function schema(string $slug, array $configuration = []): Schema {
		$schema = new Schema();
		$schema->setSlug($slug);
		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	/**
	 * A source object.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity The object.
	 */
	private function source(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema('source');
		$object->setObject($data);

		return $object;
	}//end source()

	/**
	 * A cold cache builds the index from both annotations, skips incomplete or
	 * embedded declarations, de-duplicates the fields and caches the result.
	 *
	 * @return void
	 */
	public function testColdCacheBuildsDedupesAndCachesTheIndex(): void {
		$link = ['mode' => 'reverseFk', 'sourceSchema' => 'source', 'referenceField' => 'master'];
		$this->schemaMapper->method('find')->willReturn($this->schema(slug: 'source'));
		$this->schemaMapper->expects($this->once())->method('findAll')->with(false, false)->willReturn(
			[
				$this->schema(slug: 'a', configuration: ['x-openregister-survivorship' => ['sourceLink' => $link], 'x-openregister-merge' => ['sourceLink' => $link]]),
				$this->schema(slug: 'b', configuration: ['x-openregister-merge' => ['sourceLink' => ['mode' => 'reverseFk', 'sourceSchema' => 'source', 'referenceField' => 'other']]]),
				$this->schema(slug: 'c', configuration: ['x-openregister-survivorship' => ['sourceLink' => ['sourceSchema' => 'source', 'referenceField' => 'embedded']]]),
				$this->schema(slug: 'd', configuration: ['x-openregister-survivorship' => ['sourceLink' => ['mode' => 'reverseFk', 'sourceSchema' => 'source']]]),
				$this->schema(slug: 'e', configuration: ['x-openregister-survivorship' => ['sourceLink' => 'reverseFk']]),
			]
		);
		$this->cache->method('get')->willReturn(null);
		$this->cache->expects($this->once())->method('set')
			->with('reverse_fk_index', ['source' => ['master', 'other']], 3600);

		$listener = $this->listener();
		$listener->handle(
			new ObjectUpdatedEvent($this->source(data: ['master' => 'm-1', 'other' => 'm-2']), $this->source(data: ['master' => 'm-0', 'other' => 'm-2']))
		);
		$listener->handle(new ObjectDeletedEvent($this->source(data: ['master' => 'm-3'])));

		$this->assertSame(['m-1', 'm-0', 'm-2', 'm-3'], $this->recomputed);
	}//end testColdCacheBuildsDedupesAndCachesTheIndex()

	/**
	 * A warm cache answers without loading any schema.
	 *
	 * @return void
	 */
	public function testWarmCacheIsUsed(): void {
		$this->schemaMapper->method('find')->willReturn($this->schema(slug: 'source'));
		$this->schemaMapper->expects($this->never())->method('findAll');
		$this->cache->method('get')->willReturn(['source' => ['master']]);

		$this->listener()->handle(new ObjectDeletedEvent($this->source(data: ['master' => 'm-9'])));

		$this->assertSame(['m-9'], $this->recomputed);
	}//end testWarmCacheIsUsed()

	/**
	 * A schema change drops the memoised and cached index, so the next write rebuilds it.
	 *
	 * @return void
	 */
	public function testSchemaChangeInvalidatesTheIndex(): void {
		$this->schemaMapper->method('find')->willReturn($this->schema(slug: 'source'));
		$this->cache->method('get')->willReturnOnConsecutiveCalls(['source' => ['master']], ['source' => ['other']]);
		$this->cache->expects($this->once())->method('remove')->with('reverse_fk_index');

		$listener = $this->listener();
		$listener->handle(new ObjectDeletedEvent($this->source(data: ['master' => 'm-1', 'other' => 'o-1'])));
		$listener->handle(new SchemaUpdatedEvent($this->schema(slug: 'x'), $this->schema(slug: 'x')));
		$listener->handle(new ObjectDeletedEvent($this->source(data: ['master' => 'm-1', 'other' => 'o-1'])));

		$this->assertSame(['m-1', 'o-1'], $this->recomputed);
	}//end testSchemaChangeInvalidatesTheIndex()

	/**
	 * Schemas that cannot be loaded give an empty index that is memoised but never cached.
	 *
	 * @return void
	 */
	public function testFailedBuildIsMemoisedNotCached(): void {
		$this->schemaMapper->method('find')->willReturn($this->schema(slug: 'source'));
		$this->schemaMapper->expects($this->once())->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->cache->method('get')->willReturn(false);
		$this->cache->expects($this->never())->method('set');
		$this->logger->expects($this->once())->method('warning')
			->with('Source-record change: could not load schemas for reverse index: db down');

		$listener = $this->listener();
		$listener->handle(new ObjectDeletedEvent($this->source(data: ['master' => 'm-1'])));
		$listener->handle(new ObjectDeletedEvent($this->source(data: ['master' => 'm-1'])));

		$this->assertSame([], $this->recomputed);
	}//end testFailedBuildIsMemoisedNotCached()

	/**
	 * A failure anywhere is logged with this text and never thrown.
	 *
	 * @return void
	 */
	public function testFailureIsLogged(): void {
		$this->schemaMapper->method('find')->willReturn($this->schema(slug: 'source'));
		$this->cache->method('get')->willReturn(['source' => ['master']]);
		$this->recompute = $this->createMock(MasterRecomputeService::class);
		$this->recompute->method('recompute')->willThrowException(new RuntimeException('save failed'));
		$this->logger->expects($this->once())->method('warning')->with('Source-record change recompute failed: save failed');

		$this->listener()->handle(new ObjectDeletedEvent($this->source(data: ['master' => 'm-1'])));
	}//end testFailureIsLogged()
}//end class
