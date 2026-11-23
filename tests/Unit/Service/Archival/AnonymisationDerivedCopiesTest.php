<?php

/**
 * An anonymisation reaches the derived copies of a record: the state-history
 * projection, the content search index and the semantic search vectors.
 *
 * The stores are held in memory here, as rows, so the test can ask the
 * question the spec asks: after the act, does any copy still answer for the
 * name? And when one copy cannot be reached, is every other still whole?
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use OCA\OpenRegister\Kernel\Contracts\ChunkMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectPropertyHistoryInterface;
use OCA\OpenRegister\Service\Archival\AnonymisationDerivedCopies;
use OCA\OpenRegister\Service\Archival\AnonymisationPlan;
use OCA\OpenRegister\Service\Archival\AnonymisationRefusedException;
use OCA\OpenRegister\Service\Archival\AnonymisationRun;
use OCA\OpenRegister\Service\Archival\AnonymisedPayload;
use OCA\OpenRegister\Service\Archival\ContentIndexAnonymisationTarget;
use OCA\OpenRegister\Service\Archival\StateHistoryAnonymisationTarget;
use OCA\OpenRegister\Service\Archival\TargetWriteRun;
use OCA\OpenRegister\Service\Archival\VectorAnonymisationTarget;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The derived copies of one record, reached or left whole.
 */
#[CoversClass(AnonymisationDerivedCopies::class)]
#[CoversClass(StateHistoryAnonymisationTarget::class)]
#[CoversClass(ContentIndexAnonymisationTarget::class)]
#[CoversClass(VectorAnonymisationTarget::class)]
#[UsesClass(AnonymisationRun::class)]
#[UsesClass(AnonymisationPlan::class)]
#[UsesClass(AnonymisedPayload::class)]
#[UsesClass(AnonymisationRefusedException::class)]
#[UsesClass(TargetWriteRun::class)]
final class AnonymisationDerivedCopiesTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const UUID = 'b6a1c2d3-0000-4000-8000-000000000001';

	private const ID = 42;

	/** @var list<array{objectUuid: string, property: string, value: string}> */
	private array $history = [];

	/** @var list<array{sourceType: string, sourceId: int, text: string}> */
	private array $chunks = [];

	/** @var bool Whether the content index refuses to be read. */
	private bool $chunksUnreachable = false;

	protected function setUp(): void {
		$this->history = [
			['objectUuid' => self::UUID, 'property' => 'name', 'value' => 'Jan Jansen'],
			['objectUuid' => self::UUID, 'property' => 'name', 'value' => 'J. Jansen'],
			['objectUuid' => self::UUID, 'property' => 'status', 'value' => 'afgehandeld'],
			['objectUuid' => 'other-record', 'property' => 'name', 'value' => 'Jan Jansen'],
		];
		$this->chunks = [
			['sourceType' => 'object', 'sourceId' => self::ID, 'text' => 'Jan Jansen, Utrecht, 2019'],
			['sourceType' => 'object', 'sourceId' => 7, 'text' => 'Jan Jansen, another record'],
			['sourceType' => 'file', 'sourceId' => self::ID, 'text' => 'a file that happens to share the id'],
		];
		$this->chunksUnreachable = false;
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = [];
	}//end setUp()

	public function testTheNameIsNotFindableInTheHistoryOrTheContentIndexAfterwards(): void {
		$marker = (new AnonymisationRun())->apply(plan: $this->plan(), targets: $this->copies()->targetsFor(objectId: self::ID));

		$this->assertSame('complete', $marker['state']);
		$this->assertSame([], $this->historyHolding('Jan Jansen', self::UUID), 'the history projection keeps no former name');
		$this->assertSame([], $this->historyHolding('J. Jansen', self::UUID));
		$this->assertSame([], $this->chunksHolding('Jan Jansen', self::ID), 'the content index answers no query for the name');
	}//end testTheNameIsNotFindableInTheHistoryOrTheContentIndexAfterwards()

	public function testWhatTheProfileKeptAndOtherRecordsAreLeftAlone(): void {
		(new AnonymisationRun())->apply(plan: $this->plan(), targets: $this->copies()->targetsFor(objectId: self::ID));

		$this->assertSame(
			[
				['objectUuid' => self::UUID, 'property' => 'status', 'value' => 'afgehandeld'],
				['objectUuid' => 'other-record', 'property' => 'name', 'value' => 'Jan Jansen'],
			],
			array_values($this->history),
			'a kept property keeps its history, and another record keeps all of its own'
		);
		$this->assertSame([7, self::ID], array_map(fn (array $c): int => $c['sourceId'], array_values($this->chunks)));
		$this->assertSame('file', array_values($this->chunks)[1]['sourceType'], 'only the record\'s own chunks leave, not a file under the same id');
	}//end testWhatTheProfileKeptAndOtherRecordsAreLeftAlone()

	public function testTheVectorsOfTheRecordAreDroppedUnderEitherSpellingOfItsId(): void {
		(new AnonymisationRun())->apply(plan: $this->plan(), targets: $this->copies()->targetsFor(objectId: self::ID));

		$deletes = array_values(array_filter($this->queryLog, fn (string $q): bool => str_contains($q, 'delete(openregister_vectors')));
		$this->assertCount(1, $deletes);
		$tag = strtok($deletes[0], ' ');
		$this->assertSame(
			[
				$tag.' delete(openregister_vectors, NULL)',
				$tag.' param :p3 = object, 2',
				$tag.' expr.eq(entity_type, :p3, NULL)',
				$tag.' where(eq(entity_type, :p3, NULL))',
				$tag.' param :p4 = ["'.self::UUID.'","42"], 102',
				$tag.' expr.in(entity_id, :p4, NULL)',
				$tag.' andWhere(in(entity_id, :p4, NULL))',
				$tag.' executeStatement()',
			],
			array_values(array_filter($this->queryLog, fn (string $q): bool => str_starts_with($q, $tag.' ')))
		);
	}//end testTheVectorsOfTheRecordAreDroppedUnderEitherSpellingOfItsId()

	public function testAnUnreachableContentIndexLeavesEveryCopyWhole(): void {
		$this->chunksUnreachable = true;
		$historyBefore = $this->history;
		$chunksBefore = $this->chunks;

		try {
			(new AnonymisationRun())->apply(plan: $this->plan(), targets: $this->copies()->targetsFor(objectId: self::ID));
			$this->fail('an unreachable copy must stop the act');
		} catch (AnonymisationRefusedException $refusal) {
			$this->assertStringContainsString('the content search index could not be reached', $refusal->getMessage());
			$this->assertStringContainsString('The record is unchanged.', $refusal->getMessage());
		}

		$this->assertSame($historyBefore, $this->history, 'the history was not written');
		$this->assertSame($chunksBefore, $this->chunks);
		$this->assertSame([], array_values(array_filter($this->queryLog, fn (string $q): bool => str_contains($q, 'delete('))), 'no vector was dropped');
	}//end testAnUnreachableContentIndexLeavesEveryCopyWhole()

	public function testARecordWithoutADatabaseIdHasNoContentIndexToReach(): void {
		$targets = $this->copies()->targetsFor(objectId: null);

		$this->assertSame(
			['the state-history projection', 'the semantic search vectors'],
			array_map(fn ($t): string => $t->name(), $targets)
		);

		$targets[1]->apply(plan: $this->plan());
		$this->assertContains('#1 param :p2 = ["'.self::UUID.'"], 102', $this->queryLog, 'only the uuid is matched');
	}//end testARecordWithoutADatabaseIdHasNoContentIndexToReach()

	public function testTheTargetsAreNamedForTheRefusal(): void {
		$this->assertSame(
			['the state-history projection', 'the content search index', 'the semantic search vectors'],
			array_map(fn ($t): string => $t->name(), $this->copies()->targetsFor(objectId: self::ID))
		);
	}//end testTheTargetsAreNamedForTheRefusal()

	/**
	 * A plan that removes the name and keeps the status.
	 *
	 * @return AnonymisationPlan The plan.
	 */
	private function plan(): AnonymisationPlan {
		return new AnonymisationPlan(
			objectUuid: self::UUID,
			profileName: 'client',
			payload: new AnonymisedPayload(
				before: ['name' => 'Jan Jansen', 'status' => 'afgehandeld'],
				after: ['status' => 'afgehandeld'],
				changedProperties: ['name'],
				keptProperties: ['status']
			),
			saltFingerprint: 'abc123abc123'
		);
	}//end plan()

	/**
	 * The derived copies over the in-memory stores.
	 *
	 * @return AnonymisationDerivedCopies The copies.
	 */
	private function copies(): AnonymisationDerivedCopies {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());

		return new AnonymisationDerivedCopies(history: $this->historyStore(), chunks: $this->chunkStore(), db: $db);
	}//end copies()

	/**
	 * The history projection, over $this->history.
	 *
	 * @return ObjectPropertyHistoryInterface&MockObject The store.
	 */
	private function historyStore(): ObjectPropertyHistoryInterface&MockObject {
		$store = $this->createMock(ObjectPropertyHistoryInterface::class);
		$matches = fn (string $uuid, array $properties): callable =>
			fn (array $row): bool => $row['objectUuid'] === $uuid && in_array($row['property'], $properties, true);
		$store->method('countForObjectProperties')->willReturnCallback(
			fn (string $uuid, array $properties): int => count(array_filter($this->history, $matches($uuid, $properties)))
		);
		$store->method('deleteForObjectProperties')->willReturnCallback(
			function (string $uuid, array $properties) use ($matches): int {
				$before = count($this->history);
				$this->history = array_values(array_filter($this->history, fn (array $row): bool => $matches($uuid, $properties)($row) === false));
				return $before - count($this->history);
			}
		);

		return $store;
	}//end historyStore()

	/**
	 * The content index, over $this->chunks.
	 *
	 * @return ChunkMapperInterface&MockObject The store.
	 */
	private function chunkStore(): ChunkMapperInterface&MockObject {
		$store = $this->createMock(ChunkMapperInterface::class);
		$store->method('findBySource')->willReturnCallback(
			function (string $type, int $id): array {
				if ($this->chunksUnreachable === true) {
					throw new RuntimeException('connection refused');
				}
				return array_values(array_filter($this->chunks, fn (array $c): bool => $c['sourceType'] === $type && $c['sourceId'] === $id));
			}
		);
		$store->method('deleteBySource')->willReturnCallback(
			function (string $type, int $id): void {
				$this->chunks = array_values(
					array_filter($this->chunks, fn (array $c): bool => ($c['sourceType'] === $type && $c['sourceId'] === $id) === false)
				);
			}
		);

		return $store;
	}//end chunkStore()

	/**
	 * History rows of one record still holding a value.
	 *
	 * @param string $value The value.
	 * @param string $uuid  The record.
	 *
	 * @return list<array> The rows.
	 */
	private function historyHolding(string $value, string $uuid): array {
		return array_values(array_filter($this->history, fn (array $r): bool => $r['objectUuid'] === $uuid && $r['value'] === $value));
	}//end historyHolding()

	/**
	 * Chunks of one record whose text still contains a value.
	 *
	 * @param string $value The value.
	 * @param int    $id    The record's id.
	 *
	 * @return list<array> The chunks.
	 */
	private function chunksHolding(string $value, int $id): array {
		$holds = fn (array $c): bool => $c['sourceType'] === 'object' && $c['sourceId'] === $id && str_contains($c['text'], $value);

		return array_values(array_filter($this->chunks, $holds));
	}//end chunksHolding()
}//end class
