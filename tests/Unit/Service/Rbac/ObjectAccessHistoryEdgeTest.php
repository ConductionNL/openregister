<?php

/**
 * Edge tests for ObjectAccessHistory.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\Rbac\ObjectAccessHistory;
use PHPUnit\Framework\TestCase;

/**
 * Malformed changes, unreadable moments and undated entries never produce an "as of" answer.
 *
 * Every timestamp carries an explicit UTC offset, so nothing depends on the server timezone.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\ObjectAccessHistory
 * @uses \OCA\OpenRegister\Service\Rbac\ObjectPermissionsResolver
 */
class ObjectAccessHistoryEdgeTest extends TestCase {

	/**
	 * An audit entry.
	 *
	 * @param mixed       $changed The change set.
	 * @param string|null $at      When, or null for an undated entry.
	 *
	 * @return AuditTrail
	 */
	private function entry(mixed $changed, ?string $at): AuditTrail {
		$entry = new AuditTrail();
		$entry->setChanged($changed);
		$entry->setUser('admin');
		$entry->setAction('update');
		if ($at !== null) {
			$entry->setCreated(new DateTime($at));
		}

		return $entry;
	}//end entry()

	/**
	 * Malformed change payloads are skipped, and unreadable blocks read as null.
	 *
	 * @return void
	 */
	public function testMalformedChangesAreSkippedAndBlocksNormalised(): void {
		$history = (new ObjectAccessHistory())->forEntries(
			entries: [
				$this->entry(changed: ['authorization' => 'not-a-diff'], at: '2026-03-01T00:00:00+00:00'),
				$this->entry(changed: null, at: '2026-03-01T00:00:00+00:00'),
				$this->entry(changed: ['authorization' => ['old' => '{broken', 'new' => '']], at: '2026-02-01T00:00:00+00:00'),
			]
		);

		$this->assertCount(1, $history['changes']);
		$this->assertNull($history['changes'][0]['from']);
		$this->assertNull($history['changes'][0]['to']);
		$this->assertSame('2026-02-01T00:00:00+00:00', $history['changes'][0]['at']);
		$this->assertNull($history['asOf'], 'No moment was asked, so there is no as-of answer.');
	}//end testMalformedChangesAreSkippedAndBlocksNormalised()

	/**
	 * A blank or unreadable moment gives no as-of answer.
	 *
	 * @return void
	 */
	public function testUnreadableMomentGivesNoAnswer(): void {
		$entries = [$this->entry(changed: ['authorization' => ['old' => null, 'new' => ['read' => ['a']]]], at: '2026-01-01T00:00:00+00:00')];

		$this->assertNull((new ObjectAccessHistory())->forEntries(entries: $entries, moment: '  ')['asOf']);
		$this->assertNull((new ObjectAccessHistory())->forEntries(entries: $entries, moment: 'not a moment at all')['asOf']);
	}//end testUnreadableMomentGivesNoAnswer()

	/**
	 * An undated change cannot answer; the next dated one before the moment does,
	 * and names the undated change as what came afterwards.
	 *
	 * @return void
	 */
	public function testUndatedChangeIsPassedOver(): void {
		$history = (new ObjectAccessHistory())->forEntries(
			entries: [
				$this->entry(changed: ['authorization' => ['old' => ['read' => ['a']], 'new' => ['read' => ['b']]]], at: null),
				$this->entry(changed: ['authorization' => ['old' => null, 'new' => ['read' => ['a']]]], at: '2026-01-01T00:00:00+00:00'),
			],
			moment: '2026-06-01T00:00:00+00:00'
		);

		$this->assertSame('2026-01-01T00:00:00+00:00', $history['asOf']['at']);
		$this->assertSame('admin', $history['asOf']['setBy']);
		$this->assertSame(['at' => null, 'by' => 'admin', 'to' => ['read' => ['b']]], $history['asOf']['changedAfterwardsBy']);
	}//end testUndatedChangeIsPassedOver()
}//end class
