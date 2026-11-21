<?php

/**
 * MergeService reference relinking, end to end over an in-memory store.
 *
 * A merge moves every reference to the losing record onto the survivor through
 * the save path, leaves the one the merging user may not update, keeps each
 * move in the snapshot, and a reversal puts every moved reference back unless
 * it was edited in between. From the change mdm-merge-relinks-every-reference
 * (tasks 1.2 and 1.3).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Merge
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-a-merge-relinks-every-reference-to-the-losing-record
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace Unit\Service\Merge;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\MergeInstruction;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Merge\MergeReportingServices;
use OCA\OpenRegister\Service\Merge\MergeService;
use OCA\OpenRegister\Service\Merge\MergeSurvivorshipServices;
use OCA\OpenRegister\Service\Merge\ReferenceFinderInterface;
use OCA\OpenRegister\Service\Merge\ReferenceRelinker;
use OCA\OpenRegister\Service\Merge\ReferencingObject;
use OCA\OpenRegister\Service\Merge\SavePathReferenceWriter;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolver;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolver;
use OCA\OpenRegister\Service\Survivorship\TrustTierResolver;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(MergeService::class)]
#[CoversClass(SavePathReferenceWriter::class)]
#[UsesClass(ReferenceRelinker::class)]
class MergeServiceReferenceRelinkTest extends TestCase {

	/** @var array<string, array{register: string, schema: string, data: array<string, mixed>}> */
	private array $store = [];

	/** @var array<int, string> Uuids the merging user may not update. */
	private array $locked = [];

	/** @var int How many saves ran. */
	private int $saves = 0;

	private ObjectService $objectService;

	protected function setUp(): void {
		$this->store = [
			'from-uuid' => ['register' => 'catalogue', 'schema' => 'module', 'data' => ['title' => 'Zaaksysteem (kopie)', 'status' => 'active', 'sources' => []]],
			'into-uuid' => ['register' => 'catalogue', 'schema' => 'module', 'data' => ['title' => 'Zaaksysteem', 'status' => 'active', 'sources' => [], 'replaces' => 'from-uuid']],
			'suite-uuid' => ['register' => 'catalogue', 'schema' => 'suite', 'data' => ['title' => 'Basis', 'applications' => ['from-uuid', 'into-uuid']]],
			'connection-uuid' => ['register' => 'catalogue', 'schema' => 'connection', 'data' => ['title' => 'Koppeling', 'module' => 'from-uuid']],
			'locked-uuid' => ['register' => 'catalogue', 'schema' => 'connection', 'data' => ['title' => 'Vergrendeld', 'module' => 'from-uuid']],
		];
		$this->locked = ['locked-uuid'];
		$this->saves = 0;

		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('find')->willReturnCallback(fn (int|string $id): ?ObjectEntity => $this->entity(uuid: (string)$id));
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions()): ObjectEntity {
				$this->saves++;
				if (is_array($object) === true) {
					$this->store['op-uuid'] = ['register' => 'mdm', 'schema' => 'mergeOperation', 'data' => $object];
					return $this->entity(uuid: 'op-uuid');
				}

				$uuid = (string)$object->getUuid();
				if (in_array($uuid, $this->locked, true) === true) {
					throw new NotAuthorizedException('You may not change this object.');
				}

				$this->store[$uuid]['data'] = ($object->getObject() ?? []);
				return $this->entity(uuid: $uuid);
			}
		);
	}//end setUp()

	/**
	 * A fresh entity for a stored object, as a read returns it.
	 *
	 * @param string $uuid The object.
	 *
	 * @return ObjectEntity|null
	 */
	private function entity(string $uuid): ?ObjectEntity {
		if (isset($this->store[$uuid]) === false) {
			return null;
		}

		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister($this->store[$uuid]['register']);
		$entity->setSchema($this->store[$uuid]['schema']);
		$entity->setObject($this->store[$uuid]['data']);

		return $entity;
	}//end entity()

	/**
	 * The service, with a relinker over this store capped at the given number of moves.
	 *
	 * @param int $cap The move cap.
	 *
	 * @return MergeService
	 */
	private function service(int $cap = ReferenceRelinker::DEFAULT_CAP): MergeService {
		$schema = new Schema();
		$schema->setSlug('module');
		$schema->setConfiguration(
			[
				'x-openregister-merge' => ['sourceLinkField' => 'sources', 'entityType' => 'module'],
				'x-openregister-survivorship' => [
					'sourceLinkField' => 'sources',
					'goldenRecordField' => 'goldenRecord',
					'provenanceField' => 'attributeProvenance',
					'tierOrder' => ['discard', 'bronze', 'silver', 'gold'],
					'defaultTier' => 'bronze',
					'discardTier' => 'discard',
				],
			]
		);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);
		$logger = $this->createMock(LoggerInterface::class);
		$propertyRbac = $this->createMock(PropertyRbacHandler::class);
		$propertyRbac->method('canReadProperty')->willReturn(true);

		$finder = new class($this) implements ReferenceFinderInterface {
			public function __construct(private readonly MergeServiceReferenceRelinkTest $test) {
			}

			public function referencingObjects(string $uuid): iterable {
				return $this->test->referencing(uuid: $uuid);
			}
		};

		return new MergeService(
			$this->objectService,
			$propertyRbac,
			new MergeSurvivorshipServices($schemaMapper, new SurvivorshipResolver(), new TrustTierResolver(), new SourceRecordResolver($this->objectService, $schemaMapper, $logger)),
			new MergeReportingServices($this->createMock(IEventDispatcher::class), $this->createMock(AuditTrailMapper::class), $logger),
			new ReferenceRelinker($finder, new SavePathReferenceWriter($this->objectService, $logger), $logger, $cap)
		);
	}//end service()

	/**
	 * What the relation index would answer: every other stored object whose data holds the uuid.
	 *
	 * @param string $uuid The referenced record.
	 *
	 * @return array<int, ReferencingObject>
	 */
	public function referencing(string $uuid): array {
		$found = [];
		foreach ($this->store as $objectUuid => $row) {
			if ($objectUuid !== $uuid && str_contains((string)json_encode($row['data']), '"' . $uuid . '"') === true) {
				$found[] = new ReferencingObject(uuid: $objectUuid, register: $row['register'], schema: $row['schema'], data: $row['data']);
			}
		}

		return $found;
	}//end referencing()

	private function merge(MergeService $service): array {
		return $service->merge(instruction: new MergeInstruction(from: 'from-uuid', into: 'into-uuid', reason: 'duplicate', mergedBy: 'steward'));
	}//end merge()

	public function testAMergeMovesEveryReferenceTheStewardMayUpdate(): void {
		$operation = $this->merge(service: $this->service());

		$this->assertSame(['into-uuid'], $this->store['suite-uuid']['data']['applications'], 'the suite lists the survivor once');
		$this->assertSame('into-uuid', $this->store['connection-uuid']['data']['module']);
		$this->assertSame('from-uuid', $this->store['locked-uuid']['data']['module'], 'a reference the steward may not update is left');
		$this->assertSame([['object' => 'locked-uuid', 'path' => 'module']], $operation['skippedReferences']);
		$this->assertEqualsCanonicalizing(
			[['schema' => 'suite', 'count' => 1], ['schema' => 'connection', 'count' => 1]],
			$operation['references']
		);
		$this->assertSame('from-uuid', $this->store['into-uuid']['data']['replaces'], 'the survivor is saved from its own payload, never relinked here');

		$moves = $this->store['op-uuid']['data']['preMergeSnapshot']['referenceMoves'];
		$this->assertEqualsCanonicalizing(
			[
				['object' => 'suite-uuid', 'path' => 'applications', 'before' => ['from-uuid', 'into-uuid'], 'after' => ['into-uuid']],
				['object' => 'connection-uuid', 'path' => 'module', 'before' => 'from-uuid', 'after' => 'into-uuid'],
			],
			$moves
		);
	}//end testAMergeMovesEveryReferenceTheStewardMayUpdate()

	public function testReversingTheMergePutsEveryReferenceBack(): void {
		$service = $this->service();
		$this->merge(service: $service);

		$result = $service->reverseMerge('op-uuid', 'steward');

		$this->assertSame(['from-uuid', 'into-uuid'], $this->store['suite-uuid']['data']['applications']);
		$this->assertSame('from-uuid', $this->store['connection-uuid']['data']['module']);
		$this->assertSame([], $result['keptReferenceEdits']);
	}//end testReversingTheMergePutsEveryReferenceBack()

	public function testAReferenceEditedAfterTheMergeKeepsTheEdit(): void {
		$service = $this->service();
		$this->merge(service: $service);
		$this->store['connection-uuid']['data']['module'] = 'other-uuid';

		$result = $service->reverseMerge('op-uuid', 'steward');

		$this->assertSame('other-uuid', $this->store['connection-uuid']['data']['module']);
		$this->assertSame(['from-uuid', 'into-uuid'], $this->store['suite-uuid']['data']['applications']);
		$this->assertSame([['object' => 'connection-uuid', 'path' => 'module']], $result['keptReferenceEdits']);
	}//end testAReferenceEditedAfterTheMergeKeepsTheEdit()

	public function testAMergeOverTheCapIsRefusedBeforeAnythingIsWritten(): void {
		$this->locked = [];

		try {
			$this->merge(service: $this->service(cap: 2));
			$this->fail('the merge should be refused');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('"from-uuid" is referenced from more than 2 places', $e->getMessage());
		}

		$this->assertSame(0, $this->saves);
		$this->assertSame('from-uuid', $this->store['connection-uuid']['data']['module']);
	}//end testAMergeOverTheCapIsRefusedBeforeAnythingIsWritten()

	public function testThePreviewListsTheReferencesBySchemaWithoutWriting(): void {
		$preview = $this->service()->previewMerge(from: 'from-uuid', into: 'into-uuid');

		$this->assertEqualsCanonicalizing(
			[['schema' => 'suite', 'count' => 1], ['schema' => 'connection', 'count' => 2]],
			$preview['references']
		);
		$this->assertSame(0, $this->saves);
	}//end testThePreviewListsTheReferencesBySchemaWithoutWriting()

	public function testWithoutARelinkerNothingElseMoves(): void {
		$schema = new Schema();
		$schema->setSlug('module');
		$schema->setConfiguration(['x-openregister-merge' => ['sourceLinkField' => 'sources'], 'x-openregister-survivorship' => ['sourceLinkField' => 'sources']]);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);
		$logger = $this->createMock(LoggerInterface::class);
		$propertyRbac = $this->createMock(PropertyRbacHandler::class);
		$propertyRbac->method('canReadProperty')->willReturn(true);
		$service = new MergeService(
			$this->objectService,
			$propertyRbac,
			new MergeSurvivorshipServices($schemaMapper, new SurvivorshipResolver(), new TrustTierResolver(), new SourceRecordResolver($this->objectService, $schemaMapper, $logger)),
			new MergeReportingServices($this->createMock(IEventDispatcher::class), $this->createMock(AuditTrailMapper::class), $logger)
		);

		$operation = $this->merge(service: $service);

		$this->assertSame('from-uuid', $this->store['connection-uuid']['data']['module']);
		$this->assertSame([], $operation['references']);
		$this->assertSame([], $service->previewMerge(from: 'from-uuid', into: 'into-uuid')['references']);
	}//end testWithoutARelinkerNothingElseMoves()
}//end class
