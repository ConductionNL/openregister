<?php

/**
 * DraftExpiry branch tests: what each guard in the sweep decides.
 *
 * Every early return, catch and empty-loop case of the sweep gets a test that
 * asserts what that branch decides: which schemas are walked, which drafts are
 * deleted, and how the run counts what it left.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\DraftExpiry;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;

/**
 * Branch coverage for the draft-lifetime sweep.
 *
 * @covers \OCA\OpenRegister\Service\Object\DraftExpiry
 * @covers \OCA\OpenRegister\Service\Object\DraftExpiryPair
 * @covers \OCA\OpenRegister\Service\Object\DraftExpiryBatch
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Register\RegisterSchemaIds
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 * @uses \OCA\OpenRegister\Db\Schema\ObjectSourceDeclaration
 * @uses \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses \OCA\OpenRegister\Service\Object\ExpiredDraftListRun
 * @uses \OCA\OpenRegister\Service\Object\ExpiredDraftTally
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 */
class DraftExpiryBranchesTest extends TestCase {

	/**
	 * The object operations the sweep reads and deletes through.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface $objects;

	/**
	 * The register mapper.
	 *
	 * @var RegisterMapperInterface&MockObject
	 */
	private RegisterMapperInterface $registers;

	/**
	 * The schema mapper.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface $schemas;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The fixed moment every sweep runs at.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $now;

	/**
	 * Build the sweep with mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->now = new DateTimeImmutable('2026-10-12T03:00:00', new DateTimeZone('UTC'));
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The sweep under test.
	 *
	 * @return DraftExpiry
	 */
	private function expiry(): DraftExpiry {
		return new DraftExpiry($this->objects, $this->registers, $this->schemas, $this->logger);
	}//end expiry()

	/**
	 * A register listing schema ids, returned by findAll.
	 *
	 * @param array<int, mixed> $schemaIds The ids.
	 *
	 * @return Register
	 */
	private function register(array $schemaIds): Register {
		$register = new Register();
		$register->setId(10);
		$register->setSlug('service');
		$register->setSchemas($schemaIds);

		return $register;
	}//end register()

	/**
	 * A schema with a configuration.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return Schema
	 */
	private function schema(array $configuration = []): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('ticket');
		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	/**
	 * An object with a status, uuid and updated moment.
	 *
	 * @param string      $uuid    The uuid.
	 * @param string|null $status  The status.
	 * @param string|null $updated The updated moment.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, ?string $status, ?string $updated): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setStatus($status);
		if ($updated !== null) {
			$entity->setUpdated(new DateTime($updated, new DateTimeZone('UTC')));
		}

		return $entity;
	}//end object()

	/**
	 * Wire one register with one schema found by id 1.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return void
	 */
	private function oneSchema(Schema $schema): void {
		$this->registers->method('findAll')->willReturn([$this->register(schemaIds: [1])]);
		$this->schemas->method('find')->willReturn($schema);
	}//end oneSchema()

	/**
	 * Something that is not a register, a schema id that is neither int nor string, and a schema that cannot be loaded: all skipped.
	 *
	 * @return void
	 */
	public function testNonRegistersOddIdsAndUnloadableSchemasAreSkipped(): void {
		$this->registers->method('findAll')->willReturn(['not a register', $this->register(schemaIds: [['x'], 7])]);
		$this->schemas->expects($this->once())->method('find')->with(7, [], false, false)->willThrowException(new RuntimeException('gone'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[DraftExpiry] A schema could not be loaded, its drafts are left alone',
			['schema' => '7', 'exception' => 'gone']
		);
		$this->objects->expects($this->never())->method('searchObjects');

		$counts = $this->expiry()->sweep(now: $this->now);

		$this->assertSame(0, $counts['schemas']);
		$this->assertSame([], $counts['perSchema']);
	}//end testNonRegistersOddIdsAndUnloadableSchemasAreSkipped()

	/**
	 * A register whose schema list is null walks nothing.
	 *
	 * @return void
	 */
	public function testARegisterWithoutSchemasWalksNothing(): void {
		$register = new Register();
		$register->setId(10);
		$this->registers->method('findAll')->willReturn([$register]);
		$this->schemas->expects($this->never())->method('find');

		$this->assertSame(0, $this->expiry()->sweep(now: $this->now)['schemas']);
	}//end testARegisterWithoutSchemasWalksNothing()

	/**
	 * A lifetime of zero opts the schema out; an object-source schema holds no drafts.
	 *
	 * @return void
	 */
	public function testOptedOutAndObjectSourceSchemasAreNotWalked(): void {
		$this->registers->method('findAll')->willReturn([$this->register(schemaIds: [1, 2])]);
		$this->schemas->method('find')->willReturnOnConsecutiveCalls(
			$this->schema(configuration: ['draftLifetimeDays' => 0]),
			$this->schema(configuration: ['x-openregister-object-source' => ['provider' => 'view']])
		);
		$this->objects->expects($this->never())->method('searchObjects');

		$this->assertSame(0, $this->expiry()->sweep(now: $this->now)['schemas']);
	}//end testOptedOutAndObjectSourceSchemasAreNotWalked()

	/**
	 * Without a setting the cutoff is thirty days back, sent as a UTC moment, and a schema with nothing expired leaves no row.
	 *
	 * @return void
	 */
	public function testTheDefaultLifetimeIsThirtyDaysAndAnEmptySchemaLeavesNoRow(): void {
		$this->oneSchema(schema: $this->schema());
		$queries = [];
		$this->objects->method('searchObjects')->willReturnCallback(
			function (array $query) use (&$queries): array {
				$queries[] = $query;
				return [];
			}
		);

		$counts = $this->expiry()->sweep(now: $this->now);

		$this->assertSame(1, $counts['schemas']);
		$this->assertSame([], $counts['perSchema']);
		$this->assertCount(2, $queries);
		$this->assertSame(['lt' => '2026-09-12 03:00:00'], $queries[0]['@self']['updated']);
		$this->assertSame('IS NULL', $queries[1]['@self']['updated']);
		$this->assertSame(['lt' => '2026-09-12 03:00:00'], $queries[1]['@self']['created']);
		$this->assertSame('10', $queries[0]['@self']['register']);
		$this->assertSame('1', $queries[0]['@self']['schema']);
		$this->assertSame('true', $queries[0]['_drafts']);
		$this->assertSame(0, $queries[0]['_offset']);
		$this->assertSame(DraftExpiry::PAGE_SIZE, $queries[0]['_limit']);
	}//end testTheDefaultLifetimeIsThirtyDaysAndAnEmptySchemaLeavesNoRow()

	/**
	 * Of what is listed, only an expired draft with a uuid is deleted: a published object, a young draft, a uuid-less draft and a non-entity are not.
	 *
	 * @return void
	 */
	public function testOnlyAnExpiredDraftWithAUuidIsDeleted(): void {
		$this->oneSchema(schema: $this->schema(configuration: ['draftLifetimeDays' => 7]));
		$old = $this->object(uuid: 'old', status: 'draft', updated: '2026-09-01T00:00:00');
		$this->objects->method('searchObjects')->willReturnOnConsecutiveCalls(
			[
				$old,
				$this->object(uuid: 'pub', status: 'published', updated: '2026-09-01T00:00:00'),
				$this->object(uuid: 'young', status: 'draft', updated: '2026-10-11T00:00:00'),
				$this->object(uuid: '', status: 'draft', updated: '2026-09-01T00:00:00'),
				$this->object(uuid: 'never', status: 'draft', updated: null),
				['not' => 'an entity'],
			],
			[]
		);
		$this->objects->expects($this->once())->method('find')
			->with(...FindCallShape::findArgs('old', [], false, $this->isInstanceOf(Register::class), $this->isInstanceOf(Schema::class), false, false, false, false))
			->willReturn($old);
		$this->objects->expects($this->once())->method('deleteObject')
			->with($this->callback(static fn (ObjectDeletion $deletion): bool => $deletion->uuid == 'old' && $deletion->target->register instanceof Register && $deletion->target->schema instanceof Schema), false, false)
			->willReturn(true);

		$counts = $this->expiry()->sweep(now: $this->now);

		$this->assertSame(1, $counts['expired']);
		$this->assertSame(1, $counts['removed']);
		$this->assertSame(0, $counts['refused']);
		$this->assertSame(
			['register' => 'service', 'schema' => 'ticket', 'lifetimeDays' => 7, 'expired' => 1, 'removed' => 1, 'refused' => 0, 'deferred' => 0],
			$counts['perSchema'][0]
		);
	}//end testOnlyAnExpiredDraftWithAUuidIsDeleted()

	/**
	 * A draft changed between the list and the delete, a delete answering false, and a delete that throws are each refused, and the sweep carries on.
	 *
	 * @return void
	 */
	public function testEachRefusalIsCountedAndTheSweepCarriesOn(): void {
		$this->oneSchema(schema: $this->schema(configuration: ['draftLifetimeDays' => 7]));
		$a = $this->object(uuid: 'a', status: 'draft', updated: '2026-09-01T00:00:00');
		$b = $this->object(uuid: 'b', status: 'draft', updated: '2026-09-01T00:00:00');
		$c = $this->object(uuid: 'c', status: 'draft', updated: '2026-09-01T00:00:00');
		$d = $this->object(uuid: 'd', status: 'draft', updated: '2026-09-01T00:00:00');
		$this->objects->method('searchObjects')->willReturnOnConsecutiveCalls([$a, $b, $c, $d], []);
		$this->objects->method('find')->willReturnCallback(
			function (string $id) use ($a, $c, $d): ?ObjectEntity {
				return [
					'a' => $this->object(uuid: 'a', status: 'published', updated: '2026-09-01T00:00:00'),
					'b' => null,
					'c' => $c,
					'd' => $d,
				][$id];
			}
		);
		$this->objects->method('deleteObject')->willReturnCallback(
			function (ObjectDeletion $deletion, bool $_rbac = true, bool $_multitenancy = true, bool $_retentionSweep = false): bool {
				$uuid = $deletion->uuid;
				if ($uuid === 'c') {
					throw new RuntimeException('append-only');
				}

				return false;
			}
		);
		$this->logger->expects($this->exactly(2))->method('info');
		$this->logger->expects($this->once())->method('warning')->with(
			'[DraftExpiry] An expired draft was not removed',
			['register' => 'service', 'schema' => 'ticket', 'uuid' => 'c', 'exception' => 'append-only']
		);

		$counts = $this->expiry()->sweep(now: $this->now);

		$this->assertSame(4, $counts['expired']);
		$this->assertSame(0, $counts['removed']);
		$this->assertSame(4, $counts['refused']);
	}//end testEachRefusalIsCountedAndTheSweepCarriesOn()

	/**
	 * The preview has no budget: every expired draft is deferred and nothing is read again or deleted.
	 *
	 * @return void
	 */
	public function testThePreviewDefersEverythingAndDeletesNothing(): void {
		$this->oneSchema(schema: $this->schema(configuration: ['draftLifetimeDays' => 7]));
		$this->objects->method('searchObjects')->willReturnOnConsecutiveCalls(
			[$this->object(uuid: 'a', status: 'draft', updated: '2026-09-01T00:00:00')],
			[$this->object(uuid: 'a', status: 'draft', updated: '2026-09-01T00:00:00')]
		);
		$this->objects->expects($this->never())->method('find');
		$this->objects->expects($this->never())->method('deleteObject');

		$counts = $this->expiry()->preview(now: $this->now);

		$this->assertSame(1, $counts['expired'], 'a uuid listed by both filters counts once');
		$this->assertSame(1, $counts['deferred']);
		$this->assertSame(0, $counts['removed']);
	}//end testThePreviewDefersEverythingAndDeletesNothing()

	/**
	 * A list that throws is logged and stops that schema; a list answering a count instead of rows stops it silently.
	 *
	 * @return void
	 */
	public function testAnUnreadableListStopsTheSchema(): void {
		$this->registers->method('findAll')->willReturn([$this->register(schemaIds: [1, 2])]);
		$this->schemas->method('find')->willReturn($this->schema(configuration: ['draftLifetimeDays' => 7]));
		$calls = 0;
		$this->objects->method('searchObjects')->willReturnCallback(
			function () use (&$calls): array|int {
				$calls++;
				if ($calls === 1) {
					throw new RuntimeException('table missing');
				}

				return 5;
			}
		);
		$this->logger->expects($this->once())->method('warning')->with(
			'[DraftExpiry] The drafts of a schema could not be listed',
			['register' => 'service', 'schema' => 'ticket', 'exception' => 'table missing']
		);

		$counts = $this->expiry()->sweep(now: $this->now);

		$this->assertSame(2, $calls, 'each schema stops at its first unreadable page, the second filter is not read');
		$this->assertSame(2, $counts['schemas']);
		$this->assertSame([], $counts['perSchema']);
	}//end testAnUnreadableListStopsTheSchema()

	/**
	 * Full pages up to the read cap: what lies beyond is counted as deferred, the second filter is only counted, and a failing count adds nothing.
	 *
	 * @return void
	 */
	public function testBeyondTheReadCapIsCountedAsDeferred(): void {
		$this->oneSchema(schema: $this->schema(configuration: ['draftLifetimeDays' => 7]));
		$young = [];
		for ($i = 0; $i < DraftExpiry::PAGE_SIZE; $i++) {
			$young[] = $this->object(uuid: 'y' . $i, status: 'draft', updated: '2026-10-11T00:00:00');
		}

		$young[0] = $this->object(uuid: 'old', status: 'draft', updated: '2026-09-01T00:00:00');
		$pages = 0;
		$this->objects->method('searchObjects')->willReturnCallback(
			function () use (&$pages, $young): array {
				$pages++;
				return $young;
			}
		);
		$counted = [];
		$this->objects->method('countSearchObjects')->willReturnCallback(
			function (array $query) use (&$counted): int {
				$counted[] = $query['@self']['updated'];
				if (count($counted) === 2) {
					throw new RuntimeException('count failed');
				}

				return (DraftExpiry::PAGE_SIZE * DraftExpiry::MAX_PAGES) + 3;
			}
		);
		$this->objects->method('find')->willReturn($young[0]);
		$this->objects->method('deleteObject')->willReturn(true);
		$this->logger->expects($this->once())->method('warning')->with(
			'[DraftExpiry] The expired drafts beyond the read cap could not be counted',
			$this->anything()
		);

		$counts = $this->expiry()->sweep(now: $this->now);

		$this->assertSame(DraftExpiry::MAX_PAGES, $pages, 'the first filter reads the whole cap, the second none');
		$this->assertSame([['lt' => '2026-10-05 03:00:00'], 'IS NULL'], $counted);
		$this->assertSame(1, $counts['removed']);
		$this->assertSame(3, $counts['deferred']);
		$this->assertSame(4, $counts['expired']);
	}//end testBeyondTheReadCapIsCountedAsDeferred()

	/**
	 * A full last page with a count below what was read still defers at least one.
	 *
	 * @return void
	 */
	public function testAFullCapDefersAtLeastOne(): void {
		$this->oneSchema(schema: $this->schema(configuration: ['draftLifetimeDays' => 7]));
		$young = [];
		for ($i = 0; $i < DraftExpiry::PAGE_SIZE; $i++) {
			$young[] = $this->object(uuid: 'y' . $i, status: 'draft', updated: '2026-10-11T00:00:00');
		}

		$this->objects->method('searchObjects')->willReturn($young);
		$this->objects->method('countSearchObjects')->willReturn(0);
		$this->objects->expects($this->never())->method('deleteObject');

		$counts = $this->expiry()->sweep(now: $this->now);

		$this->assertSame(1, $counts['deferred']);
		$this->assertSame(1, $counts['expired']);
		$this->assertSame(1, $counts['perSchema'][0]['deferred']);
	}//end testAFullCapDefersAtLeastOne()
}//end class
