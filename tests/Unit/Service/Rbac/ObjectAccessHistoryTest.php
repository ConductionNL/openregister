<?php

/**
 * Who could open this dossier in March, and what took it away afterwards.
 *
 * The point-in-time half of the auditor's question. A list of changes answers it
 * only after somebody replays the list by hand, which is how an access review
 * turns into an afternoon.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\Rbac\ObjectAccessHistory;
use PHPUnit\Framework\TestCase;

/**
 * Task 7.3: the access set at a past moment.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\ObjectAccessHistory
 */
class ObjectAccessHistoryTest extends TestCase {

	/**
	 * The report under test.
	 *
	 * @var ObjectAccessHistory
	 */
	private ObjectAccessHistory $history;

	/**
	 * Build the report over the shared readers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->history = new ObjectAccessHistory();
	}//end setUp()

	/**
	 * One audit-trail entry recording an authorization change.
	 *
	 * @param string     $at   The moment, as anything strtotime reads.
	 * @param string     $by   The user who made the change.
	 * @param array|null $from The block before.
	 * @param array|null $to   The block after.
	 *
	 * @return AuditTrail The entry.
	 */
	private function entry(string $at, string $by, ?array $from, ?array $to): AuditTrail {
		$trail = new AuditTrail();
		$trail->setAction('update');
		$trail->setUser($by);
		$trail->setUserName(ucfirst($by));
		$trail->setCreated(new DateTime($at));
		$trail->setChanged(['authorization' => ['old' => $from, 'new' => $to]]);

		return $trail;
	}//end entry()

	/**
	 * 🔴 The history answers who held a right at a past moment, and what removed it.
	 *
	 * @return void
	 */
	public function testTheHistoryAnswersAboutAPastMoment(): void {
		// Newest first, which is the order the trail mapper answers in.
		$entries = [
			$this->entry(
				at: '2026-06-01T10:00:00+02:00',
				by: 'ruben',
				from: ['read' => ['behandelaars', 'stagiairs']],
				to: ['read' => ['behandelaars']]
			),
			$this->entry(
				at: '2026-03-01T09:00:00+01:00',
				by: 'ana',
				from: ['read' => ['behandelaars']],
				to: ['read' => ['behandelaars', 'stagiairs']]
			),
		];

		$history = $this->history->forEntries(entries: $entries, moment: '2026-04-15T12:00:00+02:00');

		$this->assertCount(2, $history['changes']);
		$this->assertNotNull($history['asOf'], 'no set was reported for the moment asked about');

		$principals = array_column($history['asOf']['holders'], 'principal');
		$this->assertContains(
			'stagiairs',
			$principals,
			'the grant held in April is missing from the April answer'
		);
		$this->assertSame('ana', $history['asOf']['setBy']);

		// And the second half of the auditor's question: what changed it after.
		$this->assertNotNull($history['asOf']['changedAfterwardsBy']);
		$this->assertSame('ruben', $history['asOf']['changedAfterwardsBy']['by']);
		$this->assertSame(
			['behandelaars'],
			$history['asOf']['changedAfterwardsBy']['to']['read'],
			'the later change is reported with the set it left behind, by value'
		);
	}//end testTheHistoryAnswersAboutAPastMoment()

	/**
	 * A change that touched only the data is not a change to the access set.
	 *
	 * Reporting one would tell an auditor that access moved on a day nobody
	 * touched it, which is the kind of finding that costs a week.
	 *
	 * @return void
	 */
	public function testAChangeThatDidNotTouchTheRulesIsNotReported(): void {
		$dataOnly = new AuditTrail();
		$dataOnly->setAction('update');
		$dataOnly->setUser('ana');
		$dataOnly->setCreated(new DateTime('2026-05-01T09:00:00+02:00'));
		$dataOnly->setChanged(['object' => ['old' => ['title' => 'oud'], 'new' => ['title' => 'nieuw']]]);

		$history = $this->history->forEntries(entries: [$dataOnly], moment: '2026-06-01T00:00:00+02:00');

		$this->assertSame([], $history['changes']);
		$this->assertNull($history['asOf']);
	}//end testAChangeThatDidNotTouchTheRulesIsNotReported()

	/**
	 * A stored block that arrived as JSON text is read, not dropped.
	 *
	 * @return void
	 */
	public function testAnAuthorizationStoredAsJsonTextIsStillRead(): void {
		$entry = new AuditTrail();
		$entry->setAction('update');
		$entry->setUser('ana');
		$entry->setCreated(new DateTime('2026-02-01T09:00:00+01:00'));
		$entry->setChanged(
			[
				'authorization' => [
					'old' => null,
					'new' => json_encode(['read' => ['behandelaars']]),
				],
			]
		);

		$history = $this->history->forEntries(entries: [$entry], moment: '2026-03-01T09:00:00+01:00');

		$this->assertCount(1, $history['changes']);
		$this->assertSame(
			['behandelaars'],
			array_column($history['asOf']['holders'], 'principal')
		);
	}//end testAnAuthorizationStoredAsJsonTextIsStillRead()
}//end class
