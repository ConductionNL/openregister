<?php

/**
 * Unit tests for ApplicationQuota — a quota payload turned into allocation setters.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\Application
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\Application;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Application\ApplicationQuota;
use PHPUnit\Framework\TestCase;

final class ApplicationQuotaTest extends TestCase {

	private ApplicationQuota $quota;

	protected function setUp(): void {
		$this->quota = new ApplicationQuota();
	}

	public function testOnlyTheSentAllocationsAreTouched(): void {
		$this->assertSame(
			['setStorageQuota' => 1024, 'setUserQuota' => 5],
			$this->quota->allocations(['storage' => 1024, 'users' => 5])
		);
	}

	public function testEveryKnownKeyMapsToItsSetter(): void {
		$this->assertSame(
			[
				'setStorageQuota' => 1,
				'setBandwidthQuota' => 2,
				'setRequestQuota' => 3,
				'setUserQuota' => 4,
				'setGroupQuota' => 5,
			],
			$this->quota->allocations(['groups' => 5, 'users' => 4, 'requests' => 3, 'bandwidth' => 2, 'storage' => 1])
		);
	}

	public function testUnknownKeysAreIgnored(): void {
		$this->assertSame([], $this->quota->allocations(['disk' => 10, 'cpu' => 2]));
	}

	public function testAnExplicitNullClearsEveryAllocation(): void {
		$this->assertSame(
			[
				'setStorageQuota' => null,
				'setBandwidthQuota' => null,
				'setRequestQuota' => null,
				'setUserQuota' => null,
				'setGroupQuota' => null,
			],
			$this->quota->allocations(null)
		);
	}

	public function testAJsonStringIsDecoded(): void {
		$this->assertSame(['setRequestQuota' => 100], $this->quota->allocations('{"requests":"100"}'));
	}

	public function testAnUndecodableStringIsANoOp(): void {
		$this->assertSame([], $this->quota->allocations('{not json'));
		$this->assertSame([], $this->quota->allocations('"a scalar"'));
	}

	public function testANonNumericAllocationMeansUnlimited(): void {
		$this->assertSame(
			['setStorageQuota' => null, 'setBandwidthQuota' => null],
			$this->quota->allocations(['storage' => 'lots', 'bandwidth' => null])
		);
	}

	public function testANegativeAllocationFloorsAtZeroAndAFractionTruncates(): void {
		$this->assertSame(
			['setStorageQuota' => 0, 'setGroupQuota' => 7],
			$this->quota->allocations(['storage' => -50, 'groups' => '7.9'])
		);
	}
}
