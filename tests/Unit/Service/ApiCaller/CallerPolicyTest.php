<?php

/**
 * CallerPolicyTest — an unconfigured instance bounds nothing, and the two
 * lookups deliberately differ.
 *
 * The asymmetry is the thing to guard: the ceiling has a wildcard fallback and
 * the address binding does not. A wildcard address binding would lock out every
 * caller an administrator forgot to list, including themselves, from a JSON
 * blob with no confirmation step.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ApiCaller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ApiCaller;

use OCA\OpenRegister\Service\ApiCaller\CallerPolicy;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ApiCaller\CallerPolicy
 */
class CallerPolicyTest extends TestCase {

	/**
	 * Build a policy over administered limits and bindings.
	 *
	 * @param string $limits The raw api_caller_limits value.
	 * @param string $addresses The raw api_caller_addresses value.
	 *
	 * @return CallerPolicy The policy.
	 */
	private function policy(string $limits = '', string $addresses = ''): CallerPolicy {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key) use ($limits, $addresses): string {
				if ($key === CallerPolicy::LIMITS_KEY) {
					return $limits;
				}

				if ($key === CallerPolicy::ADDRESSES_KEY) {
					return $addresses;
				}

				return '';
			}
		);

		return new CallerPolicy($appConfig);
	}//end policy()

	public function testAnUnconfiguredInstanceBoundsNothing(): void {
		$policy = $this->policy();

		$this->assertNull($policy->limitFor(principal: 'leverancier'));
		$this->assertNull($policy->addressesFor(principal: 'leverancier'));
		$this->assertTrue($policy->allowsAddress(principal: 'leverancier', address: '203.0.113.5'));
	}//end testAnUnconfiguredInstanceBoundsNothing()

	public function testANamedCeilingApplies(): void {
		$policy = $this->policy(
			limits: json_encode(['leverancier' => ['limit' => 60, 'windowSeconds' => 60]])
		);

		$this->assertSame(['limit' => 60, 'windowSeconds' => 60], $policy->limitFor(principal: 'leverancier'));
	}//end testANamedCeilingApplies()

	public function testTheWildcardBoundsEverybodyElse(): void {
		$policy = $this->policy(
			limits: json_encode(
				[
					'*' => ['limit' => 600, 'windowSeconds' => 60],
					'runaway' => ['limit' => 10, 'windowSeconds' => 60],
				]
			)
		);

		$this->assertSame(600, $policy->limitFor(principal: 'someone-else')['limit']);
		$this->assertSame(
			10,
			$policy->limitFor(principal: 'runaway')['limit'],
			'A named entry has to win, or one runaway leverancier cannot be tightened without tightening everyone.'
		);
	}//end testTheWildcardBoundsEverybodyElse()

	public function testAnAnonymousCallerIsLookedUpUnderItsOwnName(): void {
		$policy = $this->policy(
			limits: json_encode(['anonymous' => ['limit' => 5, 'windowSeconds' => 60]])
		);

		$this->assertSame(5, $policy->limitFor(principal: '')['limit']);
	}//end testAnAnonymousCallerIsLookedUpUnderItsOwnName()

	/**
	 * @dataProvider provideUnusableCeilings
	 */
	public function testAMalformedCeilingMeansNoCeilingRatherThanAGuessedOne(mixed $declared): void {
		$policy = $this->policy(limits: json_encode(['leverancier' => $declared]));

		$this->assertNull(
			$policy->limitFor(principal: 'leverancier'),
			'Guessing a limit out of a typo is how an administrator refuses traffic they never meant to bound.'
		);
	}//end testAMalformedCeilingMeansNoCeilingRatherThanAGuessedOne()

	public static function provideUnusableCeilings(): array {
		return [
			'not an object' => [60],
			'no limit' => [['windowSeconds' => 60]],
			'zero limit' => [['limit' => 0, 'windowSeconds' => 60]],
			'negative limit' => [['limit' => -1, 'windowSeconds' => 60]],
			'zero window' => [['limit' => 60, 'windowSeconds' => 0]],
		];
	}//end provideUnusableCeilings()

	public function testAnAbsentWindowDefaultsToAMinute(): void {
		$policy = $this->policy(limits: json_encode(['leverancier' => ['limit' => 60]]));

		$this->assertSame(60, $policy->limitFor(principal: 'leverancier')['windowSeconds']);
	}//end testAnAbsentWindowDefaultsToAMinute()

	public function testMalformedJsonBoundsNothing(): void {
		$this->assertNull($this->policy(limits: 'not json')->limitFor(principal: 'leverancier'));
	}//end testMalformedJsonBoundsNothing()

	public function testABoundCallerIsRefusedFromAnotherAddress(): void {
		$policy = $this->policy(addresses: json_encode(['leverancier' => ['203.0.113.0/24']]));

		$this->assertTrue($policy->allowsAddress(principal: 'leverancier', address: '203.0.113.5'));
		$this->assertFalse($policy->allowsAddress(principal: 'leverancier', address: '198.51.100.5'));
	}//end testABoundCallerIsRefusedFromAnotherAddress()

	public function testACallerThatIsNotBoundIsNotRefused(): void {
		$policy = $this->policy(addresses: json_encode(['leverancier' => ['203.0.113.0/24']]));

		$this->assertTrue(
			$policy->allowsAddress(principal: 'somebody-else', address: '198.51.100.5'),
			'Only a caller an administrator actually bound is bound.'
		);
	}//end testACallerThatIsNotBoundIsNotRefused()

	public function testThereIsNoWildcardAddressBinding(): void {
		$policy = $this->policy(addresses: json_encode(['*' => ['203.0.113.0/24']]));

		$this->assertTrue(
			$policy->allowsAddress(principal: 'leverancier', address: '198.51.100.5'),
			'A wildcard binding would lock out every caller an administrator forgot to list, including themselves.'
		);
		$this->assertNull($policy->addressesFor(principal: 'leverancier'));
	}//end testThereIsNoWildcardAddressBinding()

	public function testAnEmptyAddressListIsNoBinding(): void {
		$policy = $this->policy(addresses: json_encode(['leverancier' => []]));

		$this->assertNull(
			$policy->addressesFor(principal: 'leverancier'),
			'An empty list read as a binding would refuse every call from a caller whose list an editor cleared.'
		);
		$this->assertTrue($policy->allowsAddress(principal: 'leverancier', address: '198.51.100.5'));
	}//end testAnEmptyAddressListIsNoBinding()

	public function testSeveralRangesAreAccepted(): void {
		$policy = $this->policy(
			addresses: json_encode(['leverancier' => ['203.0.113.0/24', '2001:db8::/32', '198.51.100.7']])
		);

		$this->assertTrue($policy->allowsAddress(principal: 'leverancier', address: '203.0.113.9'));
		$this->assertTrue($policy->allowsAddress(principal: 'leverancier', address: '2001:db8::99'));
		$this->assertTrue($policy->allowsAddress(principal: 'leverancier', address: '198.51.100.7'));
		$this->assertFalse($policy->allowsAddress(principal: 'leverancier', address: '198.51.100.8'));
	}//end testSeveralRangesAreAccepted()
}//end class
