<?php

/**
 * OutboundUrlGuard: the address rules webhooks and harvests share.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Http
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Http;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Http\OutboundUrlGuard;
use OCA\OpenRegister\Service\Security\OutboundAddressGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Http\OutboundUrlGuard
 * @uses \OCA\OpenRegister\Service\Security\OutboundAddressGuard
 */
class OutboundUrlGuardTest extends TestCase {

	/**
	 * A guard whose host lookups answer from a table; IP literals answer themselves.
	 *
	 * @param array<string, list<string>> $dns Addresses per host name.
	 */
	private function guard(array $dns = [], string $label = 'Target URL'): OutboundUrlGuard {
		$addresses = $this->getMockBuilder(OutboundAddressGuard::class)->onlyMethods(['addressesOf'])->getMock();
		$addresses->method('addressesOf')->willReturnCallback(
			static function (string $host) use ($dns): array {
				$bare = trim($host, '[]');
				if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
					return [$bare];
				}

				return ($dns[$host] ?? []);
			}
		);

		return new OutboundUrlGuard($addresses, $label);
	}

	public function testAPublicHostPasses(): void {
		$this->guard(['feed.example' => ['93.184.216.34']])->assertSafe('https://feed.example/catalog');
		$this->addToAssertionCount(1);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function refusedUrls(): array {
		return [
			'metadata address' => ['http://169.254.169.254/latest/meta-data', 'resolves to a blocked IP range (link-local/metadata)'],
			'loopback' => ['http://127.0.0.1/', 'resolves to a blocked IP range (loopback)'],
			'private' => ['http://10.1.2.3/', 'resolves to a blocked IP range (RFC-1918)'],
			'ipv6 loopback' => ['http://[::1]/', 'resolves to a blocked IP range (loopback)'],
			'mapped metadata' => ['http://[::ffff:169.254.169.254]/', 'resolves to a blocked IP range (IPv4-mapped link-local/metadata)'],
			'carrier-grade nat' => ['http://100.64.0.1/', 'resolves to a blocked IP range (reserved)'],
			'ftp scheme' => ['ftp://feed.example/', 'must use http or https scheme; got "ftp"'],
			'no host' => ['http:///path', 'is not parseable'],
			'unresolvable' => ['https://nowhere.invalid/', 'host could not be resolved'],
		];
	}

	/**
	 * @dataProvider refusedUrls
	 */
	public function testARefusedUrlNamesWhy(string $url, string $reason): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Feed URL ' . $reason);

		$this->guard([], 'Feed URL')->assertSafe($url);
	}

	public function testAHostWithOneInternalRecordIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Target URL resolves to a blocked IP range (RFC-1918)');

		$this->guard(['split.example' => ['93.184.216.34', '192.168.1.10']])->assertSafe('https://split.example/');
	}

	public function testAllowPrivateSkipsOnlyTheAddressChecks(): void {
		$guard = $this->guard();
		$guard->assertSafe('http://127.0.0.1:8000/hook', true);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('must use http or https scheme');
		$guard->assertSafe('file:///etc/passwd', true);
	}

	public function testIsPrivateHost(): void {
		$guard = $this->guard(['public.example' => ['93.184.216.34'], 'inside.example' => ['10.0.0.5']]);

		$this->assertTrue($guard->isPrivateHost('inside.example'));
		$this->assertFalse($guard->isPrivateHost('public.example'));
		$this->assertFalse($guard->isPrivateHost(''));
	}

	public function testIpv6Labels(): void {
		$guard = $this->guard();

		$this->assertSame('unspecified', $guard->blockedIpv6Reason('::'));
		$this->assertSame('unique-local', $guard->blockedIpv6Reason('fd00::1'));
		$this->assertSame('link-local', $guard->blockedIpv6Reason('fe80::1'));
		$this->assertSame('documentation', $guard->blockedIpv6Reason('2001:db8::1'));
		$this->assertSame('IPv4-mapped', $guard->blockedIpv6Reason('::ffff:93.184.216.34'));
		$this->assertSame('IPv4-mapped RFC-1918', $guard->blockedIpv6Reason('::ffff:172.16.0.1'));
		$this->assertNull($guard->blockedIpv6Reason('2606:4700::1111'));
		$this->assertNull($guard->blockedIpv6Reason('not-an-address'));
	}
}
