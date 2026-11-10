<?php

/**
 * Characterisation of FlowConcurrency::map() result shaping.
 *
 * Pins how rejections are turned into results (a Throwable reason kept as
 * is, any other reason wrapped), how keyed input is re-indexed, and that
 * work is called with the re-indexed position.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use GuzzleHttp\Promise\Create;
use LogicException;
use OCA\OpenRegister\Service\Flow\FlowConcurrency;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowConcurrency
 */
final class FlowConcurrencyResultCharacterisationTest extends TestCase {

	/**
	 * Every way an item can end, in one map.
	 *
	 * @return void
	 */
	public function testEveryOutcomeIsShapedAsToday(): void {
		$thrown   = new LogicException('thrown');
		$rejected = new LogicException('rejected');
		$seen     = [];

		$out = (new FlowConcurrency())->map(
			['a' => 'plain', 'b' => 'promise', 'c' => 'string-reason', 'd' => 'throwable-reason', 'e' => 'throws', 'f' => 'null'],
			static function (string $item, int $index) use ($thrown, $rejected, &$seen) {
				$seen[] = [$item, $index];

				return match ($item) {
					'plain' => 1,
					'promise' => Create::promiseFor(2),
					'string-reason' => Create::rejectionFor('nope'),
					'throwable-reason' => Create::rejectionFor($rejected),
					'throws' => throw $thrown,
					'null' => null,
				};
			},
			2
		);

		$this->assertSame([0, 1, 2, 3, 4, 5], array_keys($out));
		$this->assertSame(['ok' => true, 'value' => 1, 'error' => null], $out[0]);
		$this->assertSame(['ok' => true, 'value' => 2, 'error' => null], $out[1]);

		$this->assertFalse($out[2]['ok']);
		$this->assertNull($out[2]['value']);
		$this->assertInstanceOf(RuntimeException::class, $out[2]['error']);
		$this->assertSame('nope', $out[2]['error']->getMessage());

		$this->assertSame(['ok' => false, 'value' => null, 'error' => $rejected], $out[3]);
		$this->assertSame(['ok' => false, 'value' => null, 'error' => $thrown], $out[4]);
		$this->assertSame(['ok' => true, 'value' => null, 'error' => null], $out[5]);

		$this->assertSame(
			[['plain', 0], ['promise', 1], ['string-reason', 2], ['throwable-reason', 3], ['throws', 4], ['null', 5]],
			$seen
		);
	}//end testEveryOutcomeIsShapedAsToday()
}//end class
