<?php

/**
 * The APCu counter wrapper without APCu.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Observability;

use OCA\OpenRegister\Service\Observability\ApcuCounters;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Observability\ApcuCounters
 */
final class ApcuCountersTest extends TestCase {

	/**
	 * With APCu, counters are created, added to, subtracted from and listed by prefix, and a marker is claimed once;
	 * without APCu every call is a no-op answer: nothing listed, no claim.
	 *
	 * @return void
	 */
	public function testCountsWithApcuAndIsANoOpWithout(): void {
		$apcu = new ApcuCounters();
		if ($apcu->available() === true) {
			apcu_delete('or_test:a');
			apcu_delete('or_test_marker');
		}

		$apcu->increment(key: 'or_test:a', amount: 2);
		$apcu->increment(key: 'or_test:a', amount: 3);
		$apcu->subtract(key: 'or_test:a', amount: 1);

		if ($apcu->available() === true) {
			$this->assertSame(['or_test:a' => 4], $apcu->entries(prefix: 'or_test:'));
			$this->assertTrue($apcu->claim(key: 'or_test_marker', ttl: 60));
			$this->assertFalse($apcu->claim(key: 'or_test_marker', ttl: 60));
			apcu_delete('or_test:a');
			apcu_delete('or_test_marker');
			return;
		}

		$this->assertSame([], $apcu->entries(prefix: 'or_test:'));
		$this->assertFalse($apcu->claim(key: 'or_test_marker', ttl: 60));
	}//end testCountsWithApcuAndIsANoOpWithout()
}//end class
