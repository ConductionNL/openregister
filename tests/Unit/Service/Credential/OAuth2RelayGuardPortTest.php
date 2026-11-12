<?php

/**
 * Relay targets on a non-default port, and allowlist entries that name no origin.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\OAuth2RelayGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The port is part of the origin on both sides; entries without a host are dropped.
 *
 * @covers \OCA\OpenRegister\Service\Credential\OAuth2RelayGuard
 */
final class OAuth2RelayGuardPortTest extends TestCase {

	/**
	 * A guard whose allowlist is the given raw setting.
	 *
	 * @param string $raw The stored allowlist.
	 *
	 * @return OAuth2RelayGuard The guard.
	 */
	private function guard(string $raw): OAuth2RelayGuard {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('openregister', OAuth2RelayGuard::CONFIG_KEY, '')->willReturn($raw);
		return new OAuth2RelayGuard(appConfig: $config);
	}//end guard()

	/**
	 * An allowlisted origin with a port permits exactly that port.
	 *
	 * @return void
	 */
	public function testThePortIsPartOfTheOrigin(): void {
		$guard = $this->guard(raw: 'HTTPS://Relay.Example:8443/');

		$this->assertSame(['https://relay.example:8443'], $guard->allowedOrigins());
		$this->assertTrue($guard->permits(callbackUrl: 'https://relay.example:8443/apps/openregister/oauth2/callback'));
		$this->assertFalse($guard->permits(callbackUrl: 'https://relay.example/apps/openregister/oauth2/callback'));
		$this->assertFalse($guard->permits(callbackUrl: 'https://relay.example:9443/apps/openregister/oauth2/callback'));
	}//end testThePortIsPartOfTheOrigin()

	/**
	 * Entries that are not https, or name no host, are dropped from the allowlist.
	 *
	 * @return void
	 */
	public function testEntriesWithoutAnHttpsHostAreDropped(): void {
		$this->assertSame(
			['https://ok.example'],
			$this->guard(raw: "https://  http://plain.example, https://ok.example\nftp://x.example")->allowedOrigins()
		);
	}//end testEntriesWithoutAnHttpsHostAreDropped()

	/**
	 * A callback that does not parse as a URL is refused.
	 *
	 * @return void
	 */
	public function testAnUnparseableCallbackIsRefused(): void {
		$this->assertFalse($this->guard(raw: 'https://relay.example')->permits(callbackUrl: 'https:///apps/openregister/oauth2/callback'));
	}//end testAnUnparseableCallbackIsRefused()
}//end class
