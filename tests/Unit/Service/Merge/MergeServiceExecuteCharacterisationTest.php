<?php

/**
 * MergeServiceExecuteCharacterisationTest
 *
 * Pins what MergeService::executeMerge() writes, in which order: the loser's
 * merged status, the survivor's golden record, provenance and active status,
 * the merge-operation row, the log line and the event.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Merge
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/mdm-merge-engine/tasks.md#4.3
 */

declare(strict_types=1);

namespace Unit\Service\Merge;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\OpenRegister\Service\Merge\MergeReportingServices;
use OCA\OpenRegister\Service\Merge\MergeService;
use OCA\OpenRegister\Service\Merge\MergeSurvivorshipServices;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolver;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolver;
use OCA\OpenRegister\Service\Survivorship\TrustTierResolver;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

class MergeServiceExecuteCharacterisationTest extends TestCase {

	/**
	 * Each save: the payload as it was handed over, with its register and schema.
	 *
	 * @var array<int, array{payload: array, register: mixed, schema: mixed}>
	 */
	private array $saves = [];

	/**
	 * Each info log line's context.
	 *
	 * @var array<int, array>
	 */
	private array $logged = [];

	/**
	 * The dispatched events.
	 *
	 * @var array<int, object>
	 */
	private array $events = [];

	/**
	 * A merge service over a pair with embedded sources and the given merge annotation.
	 *
	 * @param array<string, mixed> $mergeConfig The `x-openregister-merge` annotation.
	 *
	 * @return MergeService
	 */
	private function service(array $mergeConfig): MergeService {
		$schema = new Schema();
		$schema->setSlug('organisation');
		$schema->setConfiguration(
			[
				'x-openregister-merge' => $mergeConfig,
				'x-openregister-survivorship' => [
					'sourceLinkField' => 'sources',
					'goldenRecordField' => 'golden',
					'provenanceField' => 'provenance',
					'tierOrder' => ['discard', 'bronze', 'silver', 'gold'],
					'defaultTier' => 'bronze',
					'discardTier' => 'discard',
				],
			]
		);

		$from = $this->object(uuid: 'from-uuid', sources: [['sourceSystem' => 'crm', 'lastUpdated' => '2026-05-01', 'values' => ['phone' => '020-9999999']]]);
		$into = $this->object(uuid: 'into-uuid', sources: [['sourceSystem' => 'kvk-api', 'lastUpdated' => '2026-05-01', 'values' => ['name' => 'Voorbeeld B.V.']]]);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnMap(
			[
				['from-uuid', [], false, null, null, true, true, true, true, $from],
				['into-uuid', [], false, null, null, true, true, true, true, $into],
			]
		);
		$objects->method('findAll')->willReturn([]);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true) {
				$register = $options->target->register;
				$schema = $options->target->schema;
				$payload = $object instanceof ObjectEntity ? $object->getObject() : $object;
				$this->saves[] = ['payload' => $payload, 'register' => $register, 'schema' => $schema];
				if ($object instanceof ObjectEntity) {
					return $object;
				}

				$saved = new ObjectEntity();
				$saved->setUuid('op-uuid');
				$saved->setObject($object);
				return $saved;
			}
		);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			}
		);

		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				$this->events[] = $event;
			}
		);

		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->method('canReadProperty')->willReturn(true);

		return new MergeService(
			$objects,
			$rbac,
			new MergeSurvivorshipServices($schemas, new SurvivorshipResolver(), new TrustTierResolver(), new SourceRecordResolver($objects, $schemas, $logger)),
			new MergeReportingServices($events, $this->createMock(AuditTrailMapper::class), $logger)
		);
	}//end service()

	/**
	 * An active object with embedded sources.
	 *
	 * @param string $uuid    The uuid.
	 * @param array  $sources The embedded source records.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, array $sources): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema('organisation');
		$object->setRegister('organisations');
		$object->setObject(['state' => 'live', 'status' => 'active', 'sources' => $sources]);

		return $object;
	}//end object()

	public function testTheDefaultStatusesAreWrittenToTheLoserAndTheSurvivorInOrder(): void {
		$operation = $this->service(mergeConfig: ['sourceLinkField' => 'sources', 'entityType' => 'organisation'])
			->executeMerge('from-uuid', 'into-uuid', 'duplicate', 'alice');

		$this->assertCount(3, $this->saves);

		$loser = $this->saves[0]['payload'];
		$this->assertSame('merged-into-other', $loser['status']);
		$this->assertCount(1, $loser['sources']);

		$survivor = $this->saves[1]['payload'];
		$this->assertSame('active', $survivor['status']);
		$this->assertCount(2, $survivor['sources']);
		$this->assertSame('Voorbeeld B.V.', $survivor['golden']['name']);
		$this->assertSame('020-9999999', $survivor['golden']['phone']);
		$this->assertArrayHasKey('provenance', $survivor);
		$this->assertArrayNotHasKey('goldenRecord', $survivor);

		$row = $this->saves[2];
		$this->assertSame('merge-operation', $row['register']);
		$this->assertSame('mergeOperation', $row['schema']);
		$this->assertSame('into-uuid', $row['payload']['mergedIntoUuid']);
		$this->assertSame(['from-uuid'], $row['payload']['mergedFromUuids']);
		$this->assertSame('duplicate', $row['payload']['reason']);
		$this->assertTrue($row['payload']['reversible']);
		$this->assertArrayHasKey('preMergeSnapshot', $row['payload']);
		$this->assertArrayNotHasKey('reverseFkMoves', $row['payload']['preMergeSnapshot']);
		$this->assertArrayNotHasKey('fieldDecisions', $row['payload']);
		$this->assertSame('op-uuid', $operation['id']);

		$this->assertSame([['OpenRegister MDM: merge executed', ['from' => 'from-uuid', 'into' => 'into-uuid', 'relinked' => 1, 'by' => 'alice']]], $this->logged);
		$this->assertCount(1, $this->events);
		$this->assertInstanceOf(ObjectsMergedEvent::class, $this->events[0]);
		$this->assertSame('into-uuid', $this->events[0]->getSurvivorUuid());
		$this->assertSame('op-uuid', $this->events[0]->getMergeOperationId());
	}//end testTheDefaultStatusesAreWrittenToTheLoserAndTheSurvivorInOrder()

	public function testTheAnnotationNamesTheStatusFieldAndBothStatuses(): void {
		$this->service(
			mergeConfig: ['sourceLinkField' => 'sources', 'statusField' => 'state', 'survivorStatus' => 'live', 'mergedStatus' => 'gone']
		)->executeMerge('from-uuid', 'into-uuid', 'duplicate', 'alice');

		$this->assertSame('gone', $this->saves[0]['payload']['state']);
		$this->assertSame('active', $this->saves[0]['payload']['status']);
		$this->assertSame('live', $this->saves[1]['payload']['state']);
	}//end testTheAnnotationNamesTheStatusFieldAndBothStatuses()
}//end class
