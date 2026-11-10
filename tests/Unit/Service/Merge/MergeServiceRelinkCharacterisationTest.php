<?php

/**
 * MergeService relink characterisation tests.
 *
 * Pins the two relink paths of an executed merge — reverse-FK (source objects
 * rewritten to point at the survivor, moves recorded on the snapshot) and
 * embedded (the loser's source links unioned onto the survivor payload) — so
 * the helper signatures behind them can change without changing the outcome.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Merge
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#3.2
 */

declare(strict_types=1);

namespace Unit\Service\Merge;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Merge\MergeReportingServices;
use OCA\OpenRegister\Service\Merge\MergeService;
use OCA\OpenRegister\Service\Merge\MergeSurvivorshipServices;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolver;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolver;
use OCA\OpenRegister\Service\Survivorship\TrustTierResolver;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MergeServiceRelinkCharacterisationTest extends TestCase {

	private ObjectService&MockObject $objectService;

	private SchemaMapper&MockObject $schemaMapper;

	private MergeService $service;

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->schemaMapper  = $this->createMock(SchemaMapper::class);
		$logger              = $this->createMock(LoggerInterface::class);
		$propertyRbac        = $this->createMock(PropertyRbacHandler::class);
		$propertyRbac->method('canReadProperty')->willReturn(true);

		$this->service = new MergeService(
			$this->objectService,
			$propertyRbac,
			new MergeSurvivorshipServices($this->schemaMapper, new SurvivorshipResolver(), new TrustTierResolver(), new SourceRecordResolver($this->objectService, $this->schemaMapper, $logger)),
			new MergeReportingServices($this->createMock(IEventDispatcher::class), $this->createMock(AuditTrailMapper::class), $logger)
		);
	}//end setUp()

	/**
	 * A schema whose survivorship block selects the given source link.
	 *
	 * @param array<string, mixed> $survivorship The `x-openregister-survivorship` block.
	 *
	 * @return Schema
	 */
	private function schema(array $survivorship): Schema {
		$schema = new Schema();
		$schema->setSlug('organisation');
		$schema->setConfiguration(
			[
				'x-openregister-merge' => ['sourceLinkField' => 'sources', 'entityType' => 'organisation'],
				'x-openregister-survivorship' => $survivorship,
			]
		);

		return $schema;
	}//end schema()

	/**
	 * An active master object.
	 *
	 * @param string               $uuid Its uuid.
	 * @param array<string, mixed> $data Extra payload.
	 *
	 * @return ObjectEntity
	 */
	private function master(string $uuid, array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema('organisation');
		$object->setRegister('organisations');
		$object->setObject(array_merge(['status' => 'active'], $data));

		return $object;
	}//end master()

	/**
	 * Wire find() for the pair and record every saveObject() payload.
	 *
	 * @param ObjectEntity $from The loser.
	 * @param ObjectEntity $into The survivor.
	 *
	 * @return \ArrayObject<int, mixed> The recorded saved objects.
	 */
	private function wire(ObjectEntity $from, ObjectEntity $into): \ArrayObject {
		$this->objectService->method('find')->willReturnCallback(
			static fn (...$args): ?ObjectEntity => match ($args[0] ?? '') {
				'from-uuid' => $from,
				'into-uuid' => $into,
				default => null,
			}
		);

		$saved = new \ArrayObject();
		$this->objectService->method('saveObject')->willReturnCallback(
			static function ($object) use ($saved): ObjectEntity {
				$saved[] = $object;
				if ($object instanceof ObjectEntity) {
					return $object;
				}

				$operation = new ObjectEntity();
				$operation->setUuid('op-uuid');
				$operation->setObject($object);

				return $operation;
			}
		);

		return $saved;
	}//end wire()

	public function testReverseFkRelinkRewritesSourcesAndRecordsTheMoves(): void {
		$this->schemaMapper->method('find')->willReturn(
			$this->schema(['sourceLink' => ['mode' => 'reverseFk', 'sourceSchema' => '42', 'referenceField' => 'master']])
		);
		$saved = $this->wire(from: $this->master('from-uuid', []), into: $this->master('into-uuid', []));

		$pointing = new ObjectEntity();
		$pointing->setUuid('source-1');
		$pointing->setObject(['master' => 'from-uuid', 'seen' => '2026-05-01 10:00:00']);
		$stale = new ObjectEntity();
		$stale->setUuid('source-2');
		$stale->setObject(['master' => 'elsewhere']);

		$queries = [];
		$this->objectService->method('findAll')->willReturnCallback(
			static function (array $query) use (&$queries, $pointing, $stale): array {
				$queries[] = $query['filters'];
				if (($query['filters']['master'] ?? '') === 'from-uuid' && count($queries) === 1) {
					return [$pointing, $stale];
				}

				return [];
			}
		);

		$operation = $this->service->executeMerge('from-uuid', 'into-uuid', 'duplicate', 'alice');

		$this->assertSame(['schema' => '42', 'master' => 'from-uuid', 'register' => 'organisations'], $queries[0]);
		$this->assertSame('into-uuid', $saved[0]->getObject()['master']);
		$this->assertSame('2026-05-01T10:00:00+00:00', $saved[0]->getObject()['seen']);
		$this->assertSame(
			[['sourceUuid' => 'source-1', 'referenceField' => 'master', 'prior' => 'from-uuid']],
			$operation['preMergeSnapshot']['reverseFkMoves']
		);
	}//end testReverseFkRelinkRewritesSourcesAndRecordsTheMoves()

	public function testReverseFkWithoutADescriptorRelinksNothing(): void {
		$this->schemaMapper->method('find')->willReturn(
			$this->schema(['sourceLink' => ['mode' => 'reverseFk', 'referenceField' => 'master']])
		);
		$saved = $this->wire(from: $this->master('from-uuid', []), into: $this->master('into-uuid', []));
		$this->objectService->method('findAll')->willReturn([]);

		$operation = $this->service->executeMerge('from-uuid', 'into-uuid', 'duplicate', 'alice');

		$this->assertArrayNotHasKey('reverseFkMoves', $operation['preMergeSnapshot']);
		$this->assertCount(3, $saved);
	}//end testReverseFkWithoutADescriptorRelinksNothing()

	public function testEmbeddedRelinkUnionsTheLosersSourcesOntoTheSurvivor(): void {
		$this->schemaMapper->method('find')->willReturn($this->schema(['sourceLinkField' => 'sources']));
		$shared = ['sourceSystem' => 'kvk', 'values' => ['name' => 'B.V.']];
		$saved  = $this->wire(
			from: $this->master('from-uuid', ['sources' => [$shared, ['sourceSystem' => 'crm', 'values' => []]]]),
			into: $this->master('into-uuid', ['sources' => [$shared]])
		);
		$this->objectService->method('findAll')->willReturn([]);

		$this->service->executeMerge('from-uuid', 'into-uuid', 'duplicate', 'alice');

		$this->assertSame(
			[$shared, ['sourceSystem' => 'crm', 'values' => []]],
			$saved[1]->getObject()['sources']
		);
	}//end testEmbeddedRelinkUnionsTheLosersSourcesOntoTheSurvivor()

	public function testEmbeddedRelinkIgnoresANonArraySourceField(): void {
		$this->schemaMapper->method('find')->willReturn($this->schema(['sourceLinkField' => 'sources']));
		$saved = $this->wire(
			from: $this->master('from-uuid', ['sources' => 'not-a-list']),
			into: $this->master('into-uuid', ['sources' => 'also-not'])
		);
		$this->objectService->method('findAll')->willReturn([]);

		$this->service->executeMerge('from-uuid', 'into-uuid', 'duplicate', 'alice');

		$this->assertSame('also-not', $saved[1]->getObject()['sources']);
	}//end testEmbeddedRelinkIgnoresANonArraySourceField()
}//end class
