<?php

/**
 * OAuth2RelayGuardTest — where a relay may and may not forward an authorization code.
 *
 * The guard is the compensating control for the one thing a relay structurally
 * cannot do, which is verify a signature it does not hold the key for. So every
 * refusal below is load-bearing, and the most important assertion is the first:
 * an instance nobody configured as a relay forwards NOWHERE, rather than forwarding
 * anywhere.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-relay-forwards-a-code-and-never-exchanges-it
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\OAuth2RelayGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Credential\OAuth2RelayGuard
 */
class OAuth2RelayGuardTest extends TestCase {
	public function testAnUnconfiguredInstanceForwardsNowhere(): void {
		$guard = $this->makeGuard(allowList: '');

		$this->assertSame([], $guard->allowedOrigins());
		$this->assertFalse($guard->permits(callbackUrl: 'https://tenant.example/apps/openregister/oauth2/callback'));
	}

	public function testAnAllowListedTenantCallbackIsPermitted(): void {
		$guard = $this->makeGuard(allowList: 'https://tenant.example');

		$this->assertTrue($guard->permits(callbackUrl: 'https://tenant.example/apps/openregister/oauth2/callback'));
	}

	public function testASubfolderInstallOnAnAllowListedOriginIsPermitted(): void {
		$guard = $this->makeGuard(allowList: 'https://tenant.example');

		// A tenant whose Nextcloud lives in a subfolder, or uses index.php URLs, is
		// still the same origin. Pinning the whole path would refuse them silently.
		$this->assertTrue($guard->permits(callbackUrl: 'https://tenant.example/cloud/index.php/apps/openregister/oauth2/callback'));
	}

	public function testAnOriginThatIsNotOnTheListIsRefused(): void {
		$guard = $this->makeGuard(allowList: 'https://tenant.example');

		$this->assertFalse($guard->permits(callbackUrl: 'https://evil.example/apps/openregister/oauth2/callback'));
	}

	public function testAnAllowListedOriginWithTheWrongPathIsRefused(): void {
		$guard = $this->makeGuard(allowList: 'https://tenant.example');

		$this->assertFalse($guard->permits(callbackUrl: 'https://tenant.example/apps/openregister/api/objects'));
		$this->assertFalse($guard->permits(callbackUrl: 'https://tenant.example/apps/somethingelse/oauth2/callback'));
	}

	public function testUserinfoSmugglingIsRefused(): void {
		$guard = $this->makeGuard(allowList: 'https://tenant.example');

		$this->assertFalse($guard->permits(callbackUrl: 'https://tenant.example@evil.example/apps/openregister/oauth2/callback'));
	}

	public function testPlainHttpIsRefused(): void {
		$guard = $this->makeGuard(allowList: 'https://tenant.example');

		$this->assertFalse($guard->permits(callbackUrl: 'http://tenant.example/apps/openregister/oauth2/callback'));
	}

	public function testTheListAcceptsSeveralSeparatorsAndIgnoresRubbish(): void {
		$guard = $this->makeGuard(allowList: "https://one.example,  https://two.example\nhttp://three.example not-a-url");

		$this->assertSame(['https://one.example', 'https://two.example'], $guard->allowedOrigins());
	}

	/**
	 * Build the guard with a given allow-list.
	 *
	 * @param string $allowList The configured value.
	 *
	 * @return OAuth2RelayGuard The guard under test.
	 */
	private function makeGuard(string $allowList): OAuth2RelayGuard {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($allowList);

		return new OAuth2RelayGuard(appConfig: $config);
	}
}
