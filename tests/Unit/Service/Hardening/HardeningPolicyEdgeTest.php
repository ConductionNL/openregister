<?php

/**
 * The hardening policy at its edges: unknown controls and unreadable settings.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hardening
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Hardening;

use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An unknown control has no baseline and no administered value; an unreadable setting falls back safely.
 *
 * @covers \OCA\OpenRegister\Service\Hardening\HardeningPolicy
 */
final class HardeningPolicyEdgeTest extends TestCase {

	private IAppConfig&MockObject $config;

	/**
	 * Fresh app config per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IAppConfig::class);
	}//end setUp()

	/**
	 * An unknown control reads 0 everywhere, compares atLeast, and is not known.
	 *
	 * @return void
	 */
	public function testAnUnknownControlHasNoValue(): void {
		$this->config->expects($this->never())->method('getValueInt');
		$this->config->method('getValueString')->willReturn('');
		$policy = new HardeningPolicy(appConfig: $this->config);

		$this->assertSame(0, $policy->administered(control: 'no.such.control'));
		$this->assertSame(0, $policy->floor(control: 'no.such.control'));
		$this->assertSame(0, HardeningPolicy::baseline(control: 'no.such.control'));
		$this->assertSame('atLeast', HardeningPolicy::comparator(control: 'no.such.control'));
		$this->assertFalse(HardeningPolicy::isKnown(control: 'no.such.control'));
	}//end testAnUnknownControlHasNoValue()

	/**
	 * A reported control is known and carries its own baseline and comparator.
	 *
	 * @return void
	 */
	public function testAReportedControlCarriesItsBaselineAndComparator(): void {
		$this->assertTrue(HardeningPolicy::isKnown(control: 'session.lifetimeSeconds'));
		$this->assertTrue(HardeningPolicy::isKnown(control: 'admin.elevationSeconds'));
		$this->assertSame(86400, HardeningPolicy::baseline(control: 'session.lifetimeSeconds'));
		$this->assertSame('atMost', HardeningPolicy::comparator(control: 'session.lifetimeSeconds'));
		$this->assertSame('atMost', HardeningPolicy::comparator(control: 'admin.elevationSeconds'));
		$this->assertSame('atLeast', HardeningPolicy::comparator(control: 'password.minimumLength'));
	}//end testAReportedControlCarriesItsBaselineAndComparator()

	/**
	 * The allowlist-entries control counts the parsed origins rather than reading an int.
	 *
	 * @return void
	 */
	public function testTheAllowlistControlCountsTheOrigins(): void {
		$this->config->expects($this->never())->method('getValueInt');
		$this->config->method('getValueString')->willReturn('https://a.nl, HTTPS://A.NL ,https://b.nl,');

		$this->assertSame(2, (new HardeningPolicy(appConfig: $this->config))->administered(control: 'origins.allowlistEntries'));
	}//end testTheAllowlistControlCountsTheOrigins()

	/**
	 * A zero or negative administered value is not a setting: the baseline stays in force.
	 *
	 * @return void
	 */
	public function testANonPositiveAdministeredValueKeepsTheBaseline(): void {
		$this->config->method('getValueInt')->willReturnOnConsecutiveCalls(0, -5, 7);
		$policy = new HardeningPolicy(appConfig: $this->config);

		$this->assertSame(20, $policy->administered(control: 'auth.rateLimit.attemptsPerIdentity'));
		$this->assertSame(20, $policy->administered(control: 'auth.rateLimit.attemptsPerIdentity'));
		$this->assertSame(7, $policy->administered(control: 'auth.rateLimit.attemptsPerIdentity'));
	}//end testANonPositiveAdministeredValueKeepsTheBaseline()

	/**
	 * Unreadable string settings give no origins and no declared floors.
	 *
	 * @return void
	 */
	public function testUnreadableStringSettingsGiveNothing(): void {
		$this->config->method('getValueString')->willThrowException(new RuntimeException('no db'));
		$policy = new HardeningPolicy(appConfig: $this->config);

		$this->assertSame([], $policy->allowedOrigins());
		$this->assertSame([], $policy->declaredFloors());
		$this->assertSame(10, $policy->floor(control: 'password.minimumLength'));
	}//end testUnreadableStringSettingsGiveNothing()

	/**
	 * A floor map that is valid JSON but no object, or blank, declares nothing.
	 *
	 * @return void
	 */
	public function testANonObjectFloorMapDeclaresNothing(): void {
		$this->config->method('getValueString')->willReturnOnConsecutiveCalls('   ', '"text"', '{"login.lockoutSeconds": 7200, "3": 1}');
		$policy = new HardeningPolicy(appConfig: $this->config);

		$this->assertSame([], $policy->declaredFloors());
		$this->assertSame([], $policy->declaredFloors());
		$this->assertSame(['login.lockoutSeconds' => 7200], $policy->declaredFloors());
	}//end testANonObjectFloorMapDeclaresNothing()
}//end class
