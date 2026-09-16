<?php

/**
 * The dismissed-pair store.
 *
 * Covers the canonical ordering a pair is stored under, the replace-rather-
 * than-append behaviour of a repeated dismissal, the reversal that keeps the
 * row, and the fail-safe on a lookup that throws.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
 */

declare(strict_types=1);

namespace Unit\Service\Quality;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Quality\DismissedPairStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class DismissedPairStoreTest extends TestCase {

	/**
	 * Object read/write path.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * Store under test.
	 *
	 * @var DismissedPairStore
	 */
	private DismissedPairStore $store;

	/**
	 * Wire the store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->store = new DismissedPairStore(
			$this->objectService,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Build a stored row.
	 *
	 * @param string $uuid Row uuid.
	 * @param array<string, mixed> $payload Row payload.
	 *
	 * @return ObjectEntity
	 */
	private function row(string $uuid, array $payload): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject($payload);

		return $object;
	}//end row()

	/**
	 * A pair reviewed from either direction lands on one key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function testTheKeyIsTheSameWhicheverWayRound(): void {
		$this->assertSame(
			DismissedPairStore::key('bbb', 'aaa'),
			DismissedPairStore::key('aaa', 'bbb')
		);
		$this->assertSame('aaa|bbb', DismissedPairStore::key('bbb', 'aaa'));
		$this->assertSame(['aaa', 'bbb'], DismissedPairStore::canonical('bbb', 'aaa'));
	}//end testTheKeyIsTheSameWhicheverWayRound()

	/**
	 * Active dismissals come back keyed by canonical pair key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function testActiveDismissalsAreKeyedByPair(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				$this->row('row-1', ['objectA' => 'aaa', 'objectB' => 'bbb', 'fingerprint' => 'fp1']),
				$this->row('row-2', ['objectA' => 'ccc', 'objectB' => 'ddd', 'fingerprint' => 'fp2']),
			]
		);

		$rows = $this->store->activeFor('reg', 'party');

		$this->assertArrayHasKey('aaa|bbb', $rows);
		$this->assertSame('fp1', $rows['aaa|bbb']['fingerprint']);
		$this->assertSame('row-2', $rows['ccc|ddd']['id']);
	}//end testActiveDismissalsAreKeyedByPair()

	/**
	 * A lookup that throws answers an empty map, so the scorer offers every
	 * pair. An unavailable store must show a reviewer a pair they have seen
	 * before, never hide one they have not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function testAFailedLookupOffersEveryPair(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('down'));

		$this->assertSame([], $this->store->activeFor('reg', 'party'));
	}//end testAFailedLookupOffersEveryPair()

	/**
	 * A dismissal is stored in canonical order, naming the actor, the reason
	 * and the moment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function testDismissalNamesWhoWhyAndWhen(): void {
		$this->objectService->method('findAll')->willReturn([]);

		$written = null;
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function (...$args) use (&$written): ObjectEntity {
					$written = $args[0];
					return $this->row('new-row', is_array($args[0]) ? $args[0] : []);
				}
			);

		$stored = $this->store->dismiss(
			first: 'bbb',
			second: 'aaa',
			registerSlug: 'reg',
			schemaSlug: 'party',
			fingerprint: 'fp1',
			reason: 'different people, same street',
			dismissedBy: 'reviewer'
		);

		$this->assertSame('aaa', $written['objectA']);
		$this->assertSame('bbb', $written['objectB']);
		$this->assertSame('reviewer', $written['dismissedBy']);
		$this->assertSame('different people, same street', $written['reason']);
		$this->assertTrue($written['active']);
		$this->assertNotEmpty($written['dismissedAt']);
		$this->assertSame('new-row', $stored['id']);
	}//end testDismissalNamesWhoWhyAndWhen()

	/**
	 * Dismissing a pair twice replaces its row rather than adding a second
	 * one: "is this pair dismissed, and against what values" has one answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function testRedismissingReplacesTheRow(): void {
		$this->objectService->method('findAll')->willReturn(
			[$this->row('existing-row', ['objectA' => 'aaa', 'objectB' => 'bbb'])]
		);

		$uuidPassed = 'not-set';
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args) use (&$uuidPassed): ObjectEntity {
				// ObjectService::saveObject is (object, extend, register, schema, uuid, ...),
				// so the uuid is the FIFTH argument, not the fourth.
				$uuidPassed = ($args[4] ?? null);
				return $this->row('existing-row', is_array($args[0]) ? $args[0] : []);
			}
		);

		$this->store->dismiss(
			first: 'aaa',
			second: 'bbb',
			registerSlug: 'reg',
			schemaSlug: 'party',
			fingerprint: 'fp2',
			reason: 'still different',
			dismissedBy: 'reviewer'
		);

		$this->assertSame('existing-row', $uuidPassed);
	}//end testRedismissingReplacesTheRow()

	/**
	 * A reversal keeps the row and records who undid it, so the audit answers
	 * both halves of "who decided, and who changed their mind".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function testReversalKeepsTheRow(): void {
		$this->objectService->method('findAll')->willReturn(
			[$this->row('existing-row', ['objectA' => 'aaa', 'objectB' => 'bbb', 'active' => true, 'dismissedBy' => 'reviewer'])]
		);

		$written = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args) use (&$written): ObjectEntity {
				$written = $args[0];
				return $this->row('existing-row', is_array($args[0]) ? $args[0] : []);
			}
		);

		$this->store->undismiss(
			first: 'aaa',
			second: 'bbb',
			registerSlug: 'reg',
			schemaSlug: 'party',
			reversedBy: 'supervisor'
		);

		$this->assertFalse($written['active']);
		$this->assertSame('supervisor', $written['reversedBy']);
		$this->assertSame('reviewer', $written['dismissedBy']);
		$this->assertNotEmpty($written['reversedAt']);
	}//end testReversalKeepsTheRow()

	/**
	 * Undoing a dismissal that was never made changes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function testUndismissingAnUnknownPairWritesNothing(): void {
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertNull(
			$this->store->undismiss(
				first: 'aaa',
				second: 'bbb',
				registerSlug: 'reg',
				schemaSlug: 'party',
				reversedBy: 'supervisor'
			)
		);
	}//end testUndismissingAnUnknownPairWritesNothing()
}//end class
