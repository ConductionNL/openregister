<?php

/**
 * Characterisation tests for BulkSelectionResolver.
 *
 * Pins what the resolver answers for an id list and for a query selection,
 * the query it hands to the object service (stripped keys, register and
 * schema scope, the ceiling probe), and how hydration chunks and filters.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\BulkJob
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\BulkJob;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\SearchOptions;
use OCA\OpenRegister\Service\BulkJob\BulkJobSelection;
use OCA\OpenRegister\Service\BulkJob\BulkSelectionResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BulkSelectionResolverTest extends TestCase {

	/**
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface $objects;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Every call the object service saw: [query, ids, rbac, multitenancy].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * What the next searchObjects answers.
	 *
	 * @var array<int, mixed>|int
	 */
	private array|int $answer = [];

	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->objects->method('searchObjects')->willReturnCallback(
			function (array $query=[], bool $_rbac=true, bool $_multitenancy=true, SearchOptions $options=new SearchOptions()): array|int {
				$this->calls[] = [$query, $options->selection->ids, $_rbac, $_multitenancy];
				return $this->answer;
			}
		);
	}//end setUp()

	private function resolver(): BulkSelectionResolver {
		return new BulkSelectionResolver($this->objects, $this->logger);
	}//end resolver()

	private function resolve(string $type, array $criteria, ?int $registerId, ?int $schemaId, int $ceiling): array {
		if ($type === BulkJob::SELECTION_IDS) {
			return $this->resolver()->idsOf($criteria);
		}

		return $this->resolver()->queryUuids(new BulkJobSelection($criteria, $registerId, $schemaId), $ceiling);
	}//end resolve()

	private function hydrate(array $uuids, ?int $registerId, ?int $schemaId): array {
		return $this->resolver()->hydrate($uuids, new BulkJobSelection([], $registerId, $schemaId));
	}//end hydrate()

	private function entity(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		return $object;
	}//end entity()

	public function testAnIdListIsDeduplicatedAsStringsWithoutAQuery(): void {
		$this->assertSame(['a', '7', 'b'], $this->resolve(BulkJob::SELECTION_IDS, ['ids' => ['a', 7, 'a', 'b']], 1, 2, 10));
		$this->assertSame([], $this->calls);
	}//end testAnIdListIsDeduplicatedAsStringsWithoutAQuery()

	public function testAnIdListThatIsNotAListIsEmpty(): void {
		$this->assertSame([], $this->resolve(BulkJob::SELECTION_IDS, ['ids' => 'a'], 1, 2, 10));
		$this->assertSame([], $this->resolve(BulkJob::SELECTION_IDS, [], 1, 2, 10));
	}//end testAnIdListThatIsNotAListIsEmpty()

	public function testAQueryIsStrippedScopedAndProbedOnePastTheCeiling(): void {
		$this->answer = [$this->entity('u1'), 'not-an-entity', $this->entity('u2')];
		$criteria     = ['query' => ['status' => 'open', '_limit' => 5, '_page' => 2, '_fields' => 'x', '@self' => ['owner' => 'bob']]];

		$this->assertSame(['u1', 'u2'], $this->resolve(BulkJob::SELECTION_QUERY, $criteria, 3, 4, 10));
		$this->assertSame(
			[[['status' => 'open', '@self' => ['owner' => 'bob', 'register' => 3, 'schema' => 4], '_limit' => 11], null, true, true]],
			$this->calls
		);
	}//end testAQueryIsStrippedScopedAndProbedOnePastTheCeiling()

	public function testAQueryWithoutScopeKeepsANonArraySelfUntouched(): void {
		$this->resolve(BulkJob::SELECTION_QUERY, ['query' => ['@self' => 'junk', 'a' => 1]], null, null, 0);
		$this->assertSame([[['@self' => 'junk', 'a' => 1, '_limit' => 1], null, true, true]], $this->calls);
	}//end testAQueryWithoutScopeKeepsANonArraySelfUntouched()

	public function testAQueryWithoutAQueryKeyRunsAnEmptyScopedQuery(): void {
		$this->resolve(BulkJob::SELECTION_QUERY, [], null, 9, 4);
		$this->assertSame([[['@self' => ['schema' => 9], '_limit' => 5], null, true, true]], $this->calls);
	}//end testAQueryWithoutAQueryKeyRunsAnEmptyScopedQuery()

	public function testAQueryAnsweringACountIsEmptyAndWarns(): void {
		$this->answer = 3;
		$this->logger->expects($this->once())->method('warning')->with(
			'[BulkSelectionResolver] The selection query answered a count rather than objects',
			['register' => 1, 'schema' => 2]
		);
		$this->assertSame([], $this->resolve(BulkJob::SELECTION_QUERY, ['query' => []], 1, 2, 10));
	}//end testAQueryAnsweringACountIsEmptyAndWarns()

	public function testHydrateChunksScopesAndKeysByUuid(): void {
		$this->answer = [$this->entity('x'), 'skip'];
		$uuids        = array_map(static fn (int $i): string => 'o' . $i, range(1, 201));

		$this->assertSame(['x'], array_keys($this->hydrate($uuids, 5, null)));
		$this->assertCount(2, $this->calls);
		$this->assertSame([['@self' => ['register' => 5]], array_slice($uuids, 0, 200), true, true], $this->calls[0]);
		$this->assertSame([['@self' => ['register' => 5]], ['o201'], true, true], $this->calls[1]);
	}//end testHydrateChunksScopesAndKeysByUuid()

	public function testHydrateSkipsAChunkAnsweringACount(): void {
		$this->answer = 2;
		$this->assertSame([], $this->hydrate(['a'], null, null));
		$this->assertSame([[[], ['a'], true, true]], $this->calls);
	}//end testHydrateSkipsAChunkAnsweringACount()
}//end class
