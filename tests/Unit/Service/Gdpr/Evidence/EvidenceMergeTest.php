<?php

/**
 * Unit tests for EvidenceMerge
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Evidence
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Evidence;

use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceItem;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceMerge;
use PHPUnit\Framework\TestCase;

/**
 * Pins how harvested evidence is merged into a case: deduplicated by content
 * hash against what the case already holds and within one harvest.
 *
 * @covers \OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceMerge
 * @uses \OCA\OpenRegister\Service\Gdpr\Case\CasePayload
 * @uses \OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceItem
 */
final class EvidenceMergeTest extends TestCase {

	/**
	 * New items are appended after the existing evidence and counted.
	 *
	 * @return void
	 */
	public function testNewItemsAreAppended(): void {
		$existing = ['sourceId' => 'files', 'contentHash' => 'h1', 'status' => 'collected'];
		$merge    = new EvidenceMerge(data: ['evidence' => [$existing]]);
		$merge->noteProvider(sourceId: 'mail');

		$merge->merge(items: [new EvidenceItem(sourceId: 'mail', contentHash: 'h2', status: EvidenceItem::STATUS_PENDING)]);

		$this->assertTrue(condition: $merge->changed());
		$this->assertSame(
			expected: [$existing, ['sourceId' => 'mail', 'contentHash' => 'h2', 'status' => 'pending']],
			actual: $merge->evidence()
		);
		$this->assertSame(
			expected: ['caseUuid' => 'case-1', 'evaluated' => 1, 'appended' => 1, 'skipped' => 0, 'providers' => ['mail']],
			actual: $merge->summary(caseUuid: 'case-1')
		);
	}//end testNewItemsAreAppended()

	/**
	 * Items whose hash the case holds, that repeat within the harvest, or that have no hash are skipped.
	 *
	 * @return void
	 */
	public function testKnownRepeatedAndUnhashedItemsAreSkipped(): void {
		$merge = new EvidenceMerge(data: ['evidence' => [['contentHash' => 'h1'], 'not-a-record', ['sourceId' => 'nohash']]]);

		$merge->merge(
			items: [
				new EvidenceItem(sourceId: 'a', contentHash: 'h1'),
				new EvidenceItem(sourceId: 'b', contentHash: 'h3'),
				new EvidenceItem(sourceId: 'c', contentHash: 'h3'),
				new EvidenceItem(sourceId: 'd', contentHash: ''),
			]
		);

		$summary = $merge->summary(caseUuid: 'case-2');
		$this->assertSame(expected: 4, actual: $summary['evaluated']);
		$this->assertSame(expected: 1, actual: $summary['appended']);
		$this->assertSame(expected: 3, actual: $summary['skipped']);
		$this->assertSame(expected: [], actual: $summary['providers']);
		$this->assertCount(expectedCount: 4, haystack: $merge->evidence());
		$this->assertSame(expected: 'b', actual: $merge->evidence()[3]['sourceId']);
	}//end testKnownRepeatedAndUnhashedItemsAreSkipped()

	/**
	 * A case without evidence and a harvest that finds nothing leave it unchanged.
	 *
	 * @return void
	 */
	public function testAnEmptyHarvestChangesNothing(): void {
		$merge = new EvidenceMerge(data: []);

		$merge->merge(items: []);

		$this->assertFalse(condition: $merge->changed());
		$this->assertSame(expected: [], actual: $merge->evidence());
	}//end testAnEmptyHarvestChangesNothing()
}//end class
