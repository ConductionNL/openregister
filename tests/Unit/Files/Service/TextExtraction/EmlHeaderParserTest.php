<?php

/**
 * Date and address parsing of EML headers.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\TextExtraction;

use OCA\OpenRegister\Files\Service\TextExtraction\EmlHeaderParser;
use PHPUnit\Framework\TestCase;

class EmlHeaderParserTest extends TestCase {

	public function testMissingDateIsNull(): void {
		$parser = new EmlHeaderParser();
		$this->assertNull($parser->date(null));
		$this->assertNull($parser->date(''));
	}//end testMissingDateIsNull()

	public function testRfc2822DateIsParsedWithItsOffset(): void {
		$date = (new EmlHeaderParser())->date('Tue, 03 Mar 2026 14:05:06 +0100');

		$this->assertNotNull($date);
		$this->assertSame('2026-03-03T14:05:06+01:00', $date->format(DATE_ATOM));
	}//end testRfc2822DateIsParsedWithItsOffset()

	public function testDateWithoutWeekdayMatchesTheShortPattern(): void {
		$date = (new EmlHeaderParser())->date('03 Mar 2026 14:05:06 +0000');

		$this->assertNotNull($date);
		$this->assertSame('2026-03-03T14:05:06+00:00', $date->format(DATE_ATOM));
	}//end testDateWithoutWeekdayMatchesTheShortPattern()

	public function testFreeFormDateFallsBackToTheGeneralParser(): void {
		$date = (new EmlHeaderParser())->date('2026-03-03T14:05:06+02:00');

		$this->assertNotNull($date);
		$this->assertSame('2026-03-03T14:05:06+02:00', $date->format(DATE_ATOM));
	}//end testFreeFormDateFallsBackToTheGeneralParser()

	public function testUnparseableDateIsNull(): void {
		$this->assertNull((new EmlHeaderParser())->date('not a date at all'));
	}//end testUnparseableDateIsNull()

	public function testAddressesKeepCommasInsideQuotesAndAngles(): void {
		$this->assertSame(
			['"Doe, John" <john@example.com>', 'b@example.com'],
			(new EmlHeaderParser())->addresses('"Doe, John" <john@example.com>, b@example.com')
		);
	}//end testAddressesKeepCommasInsideQuotesAndAngles()
}//end class
