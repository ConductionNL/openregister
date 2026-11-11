<?php

/**
 * Unit coverage for SurvivorshipDate::parse.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Survivorship
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Survivorship;

use OCA\OpenRegister\Service\Survivorship\SurvivorshipDate;
use PHPUnit\Framework\TestCase;

/**
 * Locks what reads as a date.
 */
final class SurvivorshipDateTest extends TestCase {

	/**
	 * A date string parses; an empty, non-string or unparseable value is null.
	 *
	 * @return void
	 */
	public function testOnlyAReadableDateStringParses(): void {
		$this->assertSame('2026-01-01T00:00:00+00:00', SurvivorshipDate::parse('2026-01-01T00:00:00+00:00')?->format(DATE_ATOM));
		$this->assertNull(SurvivorshipDate::parse(''));
		$this->assertNull(SurvivorshipDate::parse(20260101));
		$this->assertNull(SurvivorshipDate::parse(null));
		$this->assertNull(SurvivorshipDate::parse('not a date'));
	}//end testOnlyAReadableDateStringParses()
}//end class
