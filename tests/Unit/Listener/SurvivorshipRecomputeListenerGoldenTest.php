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
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Listener\SurvivorshipRecomputeListener;
use OCA\OpenRegister\Service\Survivorship\MasterRecord;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolverInterface;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolverInterface;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipTrustLookup;
use OCA\OpenRegister\Service\Survivorship\TrustTierResolver;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Listener\Support\SurvivorshipResolvers;

/**
 * Golden outcomes of the golden-record recompute: when an existing golden
 * record is kept and when it is overwritten, which fields are written, and
 * that trust rows are read RBAC- and tenant-scoped. Pinned on the code before
 * the A29 refactor so the refactor cannot change them.
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
 */
class SurvivorshipRecomputeListenerGoldenTest extends TestCase {

	/**
	 * Schema mapper.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private $schemaMapper;

	/**
	 * Object layer.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private $objects;

	/**
	 * Golden-record resolver.
	 *
	 * @var SurvivorshipResolverInterface&MockObject
	 */
	private $resolver;

	/**
	 * Source-record resolver.
	 *
	 * @var SourceRecordResolverInterface&MockObject
	 */
	private $sources;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * The resolution the resolver answers.
	 *
	 * @var array<string, mixed>
	 */
	private const RESOLUTION = ['goldenRecord' => ['name' => 'Acme'], 'attributeProvenance' => ['name' => ['source' => 'crm']]];

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->resolver = $this->createMock(SurvivorshipResolverInterface::class);
		$this->sources = $this->createMock(SourceRecordResolverInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The listener on the doubles.
	 *
	 * @return SurvivorshipRecomputeListener The listener.
	 */
	private function listener(): SurvivorshipRecomputeListener {
		return new SurvivorshipRecomputeListener(
			$this->schemaMapper,
			$this->objects,
			new SurvivorshipResolvers($this->resolver, new TrustTierResolver(), $this->sources),
			$this->logger
		);
	}//end listener()

	/**
	 * A schema carrying the given survivorship annotation.
	 *
	 * @param array<string, mixed> $survivorship The annotation.
	 * @param string|null $slug The schema slug.
	 *
	 * @return void
	 */
	private function schema(array $survivorship, ?string $slug = 'organisation'): void {
		$schema = new Schema();
		$schema->setSlug($slug);
		$schema->setConfiguration(['x-openregister-survivorship' => $survivorship]);
		$this->schemaMapper->method('find')->willReturn($schema);
	}//end schema()

	/**
	 * An object with data.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema('organisation');
		$object->setUuid('master-1');
		$object->setRegister('5');
		$object->setObject($data);

		return $object;
	}//end object()

	/**
	 * Reverse-FK with no sources keeps a stored golden record and resolves nothing.
	 *
	 * @return void
	 */
	public function testReverseFkWithoutSourcesKeepsTheStoredGoldenRecord(): void {
		$this->schema(survivorship: ['sourceLink' => ['mode' => 'reverseFk']]);
		$this->sources->method('isReverseFk')->willReturn(true);
		$this->sources->expects($this->once())->method('resolveSources')
			->with(new MasterRecord(data: ['goldenRecord' => ['name' => 'Kept'], 'id' => 'master-1'], uuid: 'master-1', register: '5'), ['sourceLink' => ['mode' => 'reverseFk']])
			->willReturn([]);
		$this->resolver->expects($this->never())->method('resolveGoldenRecord');
		$this->objects->expects($this->never())->method('findAll');

		$object = $this->object(data: ['goldenRecord' => ['name' => 'Kept']]);
		$this->listener()->handle(new ObjectCreatingEvent($object));

		$this->assertSame(['name' => 'Kept'], $object->getObject()['goldenRecord']);
	}//end testReverseFkWithoutSourcesKeepsTheStoredGoldenRecord()

	/**
	 * The preservation reads the CONFIGURED golden field, not the default.
	 *
	 * @return void
	 */
	public function testReverseFkPreservationReadsTheConfiguredField(): void {
		$this->schema(survivorship: ['sourceLink' => ['mode' => 'reverseFk'], 'goldenRecordField' => 'master']);
		$this->sources->method('isReverseFk')->willReturn(true);
		$this->sources->method('resolveSources')->willReturn([]);
		$this->resolver->expects($this->once())->method('resolveGoldenRecord')->willReturn(self::RESOLUTION);

		$object = $this->object(data: ['goldenRecord' => ['name' => 'Ignored']]);
		$this->listener()->handle(new ObjectCreatingEvent($object));

		$this->assertSame(['name' => 'Acme'], $object->getObject()['master']);
	}//end testReverseFkPreservationReadsTheConfiguredField()

	/**
	 * Embedded mode with an empty source list clears and rewrites the record.
	 *
	 * @return void
	 */
	public function testEmbeddedEmptySourcesStillRecompute(): void {
		$this->schema(survivorship: ['sourceLinkField' => 'sources']);
		$this->sources->method('isReverseFk')->willReturn(false);
		$this->sources->method('resolveSources')->willReturn([]);
		$this->resolver->expects($this->once())->method('resolveGoldenRecord')->willReturn(self::RESOLUTION);

		$object = $this->object(data: ['goldenRecord' => ['name' => 'Old']]);
		$this->listener()->handle(new ObjectCreatingEvent($object));

		$this->assertSame(self::RESOLUTION['goldenRecord'], $object->getObject()['goldenRecord']);
		$this->assertSame(self::RESOLUTION['attributeProvenance'], $object->getObject()['attributeProvenance']);
	}//end testEmbeddedEmptySourcesStillRecompute()

	/**
	 * Trust rows are read RBAC- and tenant-scoped, for the configured entity
	 * type, and only object payloads reach the resolver with the overrides.
	 *
	 * @return void
	 */
	public function testTrustRowsAreReadScopedAndThreaded(): void {
		$config = ['sourceLinkField' => 'sources', 'entityType' => 'company', 'overridesField' => 'pins', 'goldenRecordField' => 'g', 'provenanceField' => 'p'];
		$this->schema(survivorship: $config);
		$this->sources->method('resolveSources')->willReturn([['values' => ['name' => 'Acme']]]);
		$row = new ObjectEntity();
		$row->setObject(['tier' => 'gold']);
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['schema' => 'trustConfiguration', 'entityType' => 'company']], true, true)
			->willReturn([$row, ['not' => 'an entity']]);
		$seen = [];
		$this->resolver->expects($this->once())->method('resolveGoldenRecord')
			->willReturnCallback(
				function (array $sourceRecords, array $passed, SurvivorshipTrustLookup $trust, mixed $overrides) use (&$seen): array {
					$this->assertInstanceOf(TrustTierResolver::class, $trust->trustResolver);
					$seen = [$trust->entityType, $sourceRecords, $passed, $trust->trustRows, $overrides];

					return self::RESOLUTION;
				}
			);

		$object = $this->object(data: ['pins' => ['name' => 'Pinned']]);
		$this->listener()->handle(new ObjectUpdatingEvent($object, $this->object(data: [])));

		$this->assertSame(['company', [['values' => ['name' => 'Acme']]], $config, [['id' => null, 'tier' => 'gold']], ['name' => 'Pinned']], $seen);
		$data = $object->getObject();
		$this->assertSame(['name' => 'Pinned'], $data['pins']);
		$this->assertSame(self::RESOLUTION['goldenRecord'], $data['g']);
		$this->assertSame(self::RESOLUTION['attributeProvenance'], $data['p']);
		$this->assertArrayNotHasKey('goldenRecord', $data);
	}//end testTrustRowsAreReadScopedAndThreaded()

	/**
	 * Empty field names fall back to the defaults; the slug is the entity type
	 * when none is configured; a failing trust lookup yields no rows.
	 *
	 * @return void
	 */
	public function testDefaultsAndFailingTrustLookup(): void {
		$this->schema(survivorship: ['sourceLinkField' => 'sources', 'overridesField' => '', 'goldenRecordField' => '', 'provenanceField' => '']);
		$this->sources->method('resolveSources')->willReturn([['values' => []]]);
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['schema' => 'trustConfiguration', 'entityType' => 'organisation']], true, true)
			->willThrowException(new RuntimeException('denied'));
		$seen = [];
		$this->resolver->expects($this->once())->method('resolveGoldenRecord')
			->willReturnCallback(
				function (array $sourceRecords, array $passed, SurvivorshipTrustLookup $trust, mixed $overrides) use (&$seen): array {
					$seen = [$trust->trustRows, $overrides];

					return self::RESOLUTION;
				}
			);

		$object = $this->object(data: ['attributeOverrides' => ['name' => 'Default pin']]);
		$this->listener()->handle(new ObjectCreatingEvent($object));

		$this->assertSame([[], ['name' => 'Default pin']], $seen);
		$this->assertSame(self::RESOLUTION['goldenRecord'], $object->getObject()['goldenRecord']);
		$this->assertSame(self::RESOLUTION['attributeProvenance'], $object->getObject()['attributeProvenance']);
	}//end testDefaultsAndFailingTrustLookup()

	/**
	 * No entity type at all: the trust register is never queried.
	 *
	 * @return void
	 */
	public function testNoEntityTypeNeverQueriesTrustRows(): void {
		$this->schema(survivorship: ['sourceLinkField' => 'sources'], slug: null);
		$this->sources->method('resolveSources')->willReturn([['values' => []]]);
		$this->objects->expects($this->never())->method('findAll');
		$this->resolver->expects($this->once())->method('resolveGoldenRecord')->willReturn(self::RESOLUTION);

		$this->listener()->handle(new ObjectCreatingEvent($this->object(data: [])));
	}//end testNoEntityTypeNeverQueriesTrustRows()

	/**
	 * No linkage, no annotation, no schema, or another event: nothing resolves.
	 *
	 * @return void
	 */
	public function testNothingResolvesWithoutLinkageOrSchema(): void {
		$this->schemaMapper->method('find')->willReturnOnConsecutiveCalls(
			$this->schemaOf(configuration: ['x-openregister-survivorship' => ['entityType' => 'x']]),
			$this->schemaOf(configuration: ['x-openregister-survivorship' => []]),
			$this->throwException(new RuntimeException('missing'))
		);
		$this->sources->method('isReverseFk')->willReturn(false);
		$this->sources->expects($this->never())->method('resolveSources');
		$this->logger->expects($this->never())->method('warning');

		$listener = $this->listener();
		$listener->handle(new ObjectCreatingEvent($this->object(data: [])));
		$listener->handle(new ObjectCreatingEvent($this->object(data: [])));
		$listener->handle(new ObjectCreatingEvent($this->object(data: [])));
		$noSchema = $this->object(data: []);
		$noSchema->setSchema('');
		$listener->handle(new ObjectCreatingEvent($noSchema));
		$listener->handle(new Event());
	}//end testNothingResolvesWithoutLinkageOrSchema()

	/**
	 * A resolver failure is logged with this exact text and the data is untouched.
	 *
	 * @return void
	 */
	public function testFailureIsLoggedAndDataUntouched(): void {
		$this->schema(survivorship: ['sourceLinkField' => 'sources']);
		$this->sources->method('resolveSources')->willReturn([['values' => []]]);
		$this->resolver->method('resolveGoldenRecord')->willThrowException(new RuntimeException('bad tier'));
		$this->logger->expects($this->once())->method('warning')
			->with('Survivorship resolution failed on master-1: bad tier');

		$object = $this->object(data: ['a' => 1]);
		$this->listener()->handle(new ObjectCreatingEvent($object));

		$this->assertSame(1, $object->getObject()['a']);
		$this->assertArrayNotHasKey('goldenRecord', $object->getObject());
	}//end testFailureIsLoggedAndDataUntouched()

	/**
	 * A schema with the given configuration.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return Schema The schema.
	 */
	private function schemaOf(array $configuration): Schema {
		$schema = new Schema();
		$schema->setSlug('organisation');
		$schema->setConfiguration($configuration);

		return $schema;
	}//end schemaOf()
}//end class
