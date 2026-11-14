<?php

/**
 * An instance opts in to deferred object events, and one request can ask to wait.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md#requirement-a-write-response-means-persisted-and-a-caller-can-ask-to-wait-for-its-side-effects
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\ObjectEventDeferral;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\ObjectEventDeferral
 */
final class ObjectEventDeferralTest extends TestCase {

	private function deferral(string $setting, ?string $header): ObjectEventDeferral {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())
			->method('getValueString')
			->with('openregister', 'defer_object_events', '')
			->willReturn($setting);

		$request = null;
		if ($header !== null) {
			$request = $this->createMock(IRequest::class);
			$request->method('getHeader')->willReturnMap([['X-OpenRegister-Effects', $header]]);
		}

		return new ObjectEventDeferral(appConfig: $appConfig, request: $request);
	}//end deferral()

	public function testAnInstanceThatDidNotOptInDispatchesInline(): void {
		$this->assertFalse($this->deferral('', '')->defers());
		$this->assertFalse($this->deferral('0', null)->defers());
		$this->assertFalse($this->deferral('true', '')->defers());
	}//end testAnInstanceThatDidNotOptInDispatchesInline()

	public function testAnOptedInInstanceDefers(): void {
		$this->assertTrue($this->deferral('1', '')->defers());
		$this->assertTrue($this->deferral('1', null)->defers());
		$this->assertTrue($this->deferral('1', 'async')->defers());
	}//end testAnOptedInInstanceDefers()

	public function testACallerWhoAsksToWaitGetsTheEffectsBeforeTheResponse(): void {
		$this->assertFalse($this->deferral('1', 'sync')->defers());
		$this->assertFalse($this->deferral('1', ' SYNC ')->defers());
	}//end testACallerWhoAsksToWaitGetsTheEffectsBeforeTheResponse()

	public function testOutsideARequestNobodyWaits(): void {
		$appConfig = $this->createMock(IAppConfig::class);

		$this->assertFalse((new ObjectEventDeferral(appConfig: $appConfig, request: null))->callerWaits());
	}//end testOutsideARequestNobodyWaits()
}//end class
