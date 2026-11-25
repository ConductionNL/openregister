<?php

/**
 * DictionaryExpansion reports what the dictionary added and dropped, and stays silent when it did nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Search;

use OCA\OpenRegister\Service\Search\DictionaryExpansion;
use OCA\OpenRegister\Service\Search\DictionaryStopwords;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The three ways a dictionary can treat a term, and the report each gives.
 */
#[CoversClass(DictionaryExpansion::class)]
#[UsesClass(DictionaryStopwords::class)]
class DictionaryExpansionTest extends TestCase {

	public function testUnchangedTermIsNotReportable(): void {
		$expansion = DictionaryExpansion::unchanged(term: 'vergunning');

		$this->assertSame('vergunning', $expansion->term());
		$this->assertFalse($expansion->changed());
		$this->assertFalse($expansion->isReportable());
		$this->assertSame(
			[
				'original' => 'vergunning',
				'searched' => 'vergunning',
				'added' => [],
				'removedStopwords' => [],
				'keptBecauseRemovalWouldEmptyIt' => false,
			],
			$expansion->jsonSerialize()
		);
	}//end testUnchangedTermIsNotReportable()

	public function testExpandedTermReportsAddedSynonymsAndDroppedStopwords(): void {
		$expansion = DictionaryExpansion::expanded(
			term: 'omgevingsvergunning OR bouwvergunning',
			original: 'de omgevingsvergunning',
			added: ['bouwvergunning'],
			removed: ['de']
		);

		$this->assertSame('omgevingsvergunning OR bouwvergunning', $expansion->term());
		$this->assertTrue($expansion->changed());
		$this->assertTrue($expansion->isReportable());
		$this->assertSame(
			[
				'original' => 'de omgevingsvergunning',
				'searched' => 'omgevingsvergunning OR bouwvergunning',
				'added' => ['bouwvergunning'],
				'removedStopwords' => ['de'],
				'keptBecauseRemovalWouldEmptyIt' => false,
			],
			$expansion->jsonSerialize()
		);
	}//end testExpandedTermReportsAddedSynonymsAndDroppedStopwords()

	public function testExpandedWithIdenticalTermIsStillReportableWhenStopwordsWereDropped(): void {
		$expansion = DictionaryExpansion::expanded(term: 'kat', original: 'kat', added: [], removed: ['het']);

		$this->assertFalse($expansion->changed());
		$this->assertTrue($expansion->isReportable());
	}//end testExpandedWithIdenticalTermIsStillReportableWhenStopwordsWereDropped()

	public function testExpandedWithNothingChangedIsNotReportable(): void {
		$expansion = DictionaryExpansion::expanded(term: 'kat', original: 'kat', added: [], removed: []);

		$this->assertFalse($expansion->isReportable());
	}//end testExpandedWithNothingChangedIsNotReportable()

	public function testAllStopwordTermKeepsTheOriginalAndSaysWhy(): void {
		$expansion = DictionaryExpansion::stopwordsWouldEmptyIt(term: 'de het', removed: ['de', 'het']);

		$this->assertSame('de het', $expansion->term());
		$this->assertFalse($expansion->changed());
		$this->assertTrue($expansion->isReportable());
		$this->assertSame(
			[
				'original' => 'de het',
				'searched' => 'de het',
				'added' => [],
				'removedStopwords' => ['de', 'het'],
				'keptBecauseRemovalWouldEmptyIt' => true,
			],
			$expansion->jsonSerialize()
		);
	}//end testAllStopwordTermKeepsTheOriginalAndSaysWhy()

	public function testKeptFlagAloneMakesItReportable(): void {
		$expansion = DictionaryExpansion::stopwordsWouldEmptyIt(term: 'x', removed: []);

		$this->assertTrue($expansion->isReportable());
	}//end testKeptFlagAloneMakesItReportable()
}//end class
