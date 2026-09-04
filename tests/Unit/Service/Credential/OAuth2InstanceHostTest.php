<?php

/**
 * OAuth2InstanceHostTest — the guard behind a per-account API host.
 *
 * `baseUrlFrom: "instanceBaseUrl"` moves the host-lock from the catalogue onto the
 * credential, and this class is the whole of what that move costs. Every rejection
 * below is a way the broker could otherwise be pointed somewhere it must never go:
 * at the instance's own network, at an address the range checks never enumerated,
 * or at a host that reads as one thing to a person and another to a parser.
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
 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-per-account-host-is-pinned-at-mint-and-immutable-afterwards
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Credential\OAuth2InstanceHost;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Credential\OAuth2InstanceHost
 */
class OAuth2InstanceHostTest extends TestCase {
	public function testAPublicHttpsOriginIsNormalised(): void {
		$this->assertSame('https://mastodon.example', OAuth2InstanceHost::normalise(candidate: 'https://mastodon.example/'));
		$this->assertSame('https://mastodon.example', OAuth2InstanceHost::normalise(candidate: ' https://Mastodon.Example '));
		$this->assertSame('https://mastodon.example', OAuth2InstanceHost::normalise(candidate: 'https://mastodon.example:443'));
	}

	/**
	 * @dataProvider unsafeHosts
	 */
	public function testAnUnsafeHostIsRefused(string $candidate): void {
		$this->expectException(InvalidArgumentException::class);
		OAuth2InstanceHost::normalise(candidate: $candidate);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unsafeHosts(): array {
		return [
			'empty' => [''],
			'plain http' => ['http://mastodon.example'],
			'loopback name' => ['https://localhost'],
			'loopback literal' => ['https://127.0.0.1'],
			'private range literal' => ['https://192.168.1.10'],
			'link-local literal' => ['https://169.254.169.254'],
			'ipv6 literal' => ['https://[::1]'],
			'userinfo smuggling' => ['https://api.example.com@evil.example'],
			'a path, not an origin' => ['https://mastodon.example/some/path'],
			'a query' => ['https://mastodon.example?a=b'],
			'a fragment' => ['https://mastodon.example#x'],
			'a non-default port' => ['https://mastodon.example:8443'],
			'a bare label' => ['https://intranet'],
			'a .local domain' => ['https://server.local'],
			'a .internal domain' => ['https://api.internal'],
		];
	}
}
