<?php

/**
 * OpenRegister EndsWithinSeconds
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Support;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Fails a test that runs longer than TIME_BOUND_SECONDS instead of letting it hang the suite.
 *
 * For tests whose regression is an endless loop. Uses SIGALRM, so it only bounds
 * the test where the pcntl extension is loaded; elsewhere the test runs unbounded.
 */
trait EndsWithinSeconds {
	/**
	 * Arm the alarm before each test.
	 *
	 * @return void
	 */
	#[Before]
	protected function armTimeBound(): void {
		if (function_exists('pcntl_alarm') === false) {
			return;
		}

		pcntl_async_signals(true);
		pcntl_signal(
			SIGALRM,
			static function (): void {
				throw new AssertionFailedError('The test did not end within its time bound: a loop guard no longer holds.');
			}
		);
		pcntl_alarm(self::TIME_BOUND_SECONDS);
	}//end armTimeBound()

	/**
	 * Disarm the alarm after each test.
	 *
	 * @return void
	 */
	#[After]
	protected function disarmTimeBound(): void {
		if (function_exists('pcntl_alarm') === false) {
			return;
		}

		pcntl_alarm(0);
		pcntl_signal(SIGALRM, SIG_DFL);
	}//end disarmTimeBound()
}//end trait
