<?php

/**
 * FlowStepRetry unit test.
 *
 * A step's retry settings: read from the lowered step, refused above the caps
 * with the key and the cap named, and the delay before each extra try, fixed
 * or doubling, never past one hour.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
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

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowStepRetry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FlowStepRetry::class)]
final class FlowStepRetryTest extends TestCase {

	/**
	 * The spec's flaky-service step reads as three fixed one-minute retries.
	 *
	 * @return void
	 */
	public function testAStepDeclaresItsRetry(): void {
		$retry = FlowStepRetry::for(step: ['type' => 'openregister.http', 'retry' => ['attempts' => 3, 'delaySeconds' => 60, 'backoff' => 'fixed']]);

		$this->assertNotNull($retry);
		$this->assertSame([3, 60, 'fixed'], [$retry->attempts, $retry->delaySeconds, $retry->backoff]);
		$this->assertTrue($retry->allowsAnother(made: 0));
		$this->assertTrue($retry->allowsAnother(made: 2));
		$this->assertFalse($retry->allowsAnother(made: 3), 'a fourth failure counts as failed');
	}//end testAStepDeclaresItsRetry()

	/**
	 * No declaration, zero attempts, or one over the caps means no retry.
	 *
	 * @return void
	 */
	public function testNothingOrSomethingRefusedMeansNoRetry(): void {
		$this->assertNull(FlowStepRetry::for(step: null));
		$this->assertNull(FlowStepRetry::for(step: ['type' => 'openregister.http']));
		$this->assertNull(FlowStepRetry::for(step: ['retry' => ['attempts' => 0, 'delaySeconds' => 5]]));
		$this->assertNull(FlowStepRetry::for(step: ['retry' => ['attempts' => 50]]));
		$this->assertNull(FlowStepRetry::for(step: ['retry' => 'three times']));
	}//end testNothingOrSomethingRefusedMeansNoRetry()

	/**
	 * Defaults: no delay and fixed backoff.
	 *
	 * @return void
	 */
	public function testDefaults(): void {
		$retry = FlowStepRetry::for(step: ['retry' => ['attempts' => 2]]);

		$this->assertSame([2, 0, 'fixed'], [$retry->attempts, $retry->delaySeconds, $retry->backoff]);
		$this->assertSame(0, $retry->delayBefore(attempt: 2));
	}//end testDefaults()

	/**
	 * Every fault names the key, and the over-cap ones name the cap.
	 *
	 * @return void
	 */
	public function testFaultsNameTheKeyAndTheCap(): void {
		$this->assertSame([], FlowStepRetry::faults(retry: null));
		$this->assertSame([], FlowStepRetry::faults(retry: ['attempts' => 10, 'delaySeconds' => 3600, 'backoff' => 'exponential']));
		$this->assertSame(['retry.attempts is 50; the cap is 10'], FlowStepRetry::faults(retry: ['attempts' => 50]));
		$this->assertSame(
			['retry.delaySeconds is 7200; the cap is 3600', 'retry.backoff must be "fixed" or "exponential"'],
			FlowStepRetry::faults(retry: ['attempts' => 1, 'delaySeconds' => 7200, 'backoff' => 'linear'])
		);
		$this->assertSame(['retry.attempts must be a whole number from 0 to 10'], FlowStepRetry::faults(retry: ['attempts' => '3']));
		$this->assertSame(['retry.delaySeconds must be a whole number from 0 to 3600'], FlowStepRetry::faults(retry: ['attempts' => 1, 'delaySeconds' => -5]));
		$this->assertSame(['retry must be an object with attempts, delaySeconds and backoff'], FlowStepRetry::faults(retry: 3));
	}//end testFaultsNameTheKeyAndTheCap()

	/**
	 * Fixed keeps the delay; exponential doubles it per try and stops at one hour.
	 *
	 * @return void
	 */
	public function testDelays(): void {
		$fixed = FlowStepRetry::for(step: ['retry' => ['attempts' => 3, 'delaySeconds' => 60, 'backoff' => 'fixed']]);
		$doubling = FlowStepRetry::for(step: ['retry' => ['attempts' => 10, 'delaySeconds' => 60, 'backoff' => 'exponential']]);

		$this->assertSame([60, 60, 60], [$fixed->delayBefore(attempt: 1), $fixed->delayBefore(attempt: 2), $fixed->delayBefore(attempt: 3)]);
		$this->assertSame([60, 120, 240, 480], [$doubling->delayBefore(attempt: 1), $doubling->delayBefore(attempt: 2), $doubling->delayBefore(attempt: 3), $doubling->delayBefore(attempt: 4)]);
		$this->assertSame(3600, $doubling->delayBefore(attempt: 7));
		$this->assertSame(3600, $doubling->delayBefore(attempt: 10));
		$this->assertSame(60, $doubling->delayBefore(attempt: 0));
	}//end testDelays()
}//end class
