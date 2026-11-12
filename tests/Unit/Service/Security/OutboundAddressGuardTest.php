<?php

/**
 * Unit tests for OutboundAddressGuard.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Security;

use OCA\OpenRegister\Service\Security\OutboundAddressGuard;
use PHPUnit\Framework\TestCase;

/**
 * Address normalisation and classification for outbound requests.
 *
 * @covers \OCA\OpenRegister\Service\Security\OutboundAddressGuard
 */
class OutboundAddressGuardTest extends TestCase {
	/**
	 * The guard under test.
	 *
	 * @var OutboundAddressGuard
	 */
	private OutboundAddressGuard $guard;

	/**
	 * Create the guard.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->guard = new OutboundAddressGuard();

	}//end setUp()


	/**
	 * IPv6 forms that embed an IPv4 address, and the address they reach.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function embeddedIpv4(): array {
		return [
			'mapped dotted'      => ['::ffff:127.0.0.1', '127.0.0.1'],
			'mapped hex'         => ['::ffff:7f00:1', '127.0.0.1'],
			'mapped long form'   => ['0:0:0:0:0:ffff:a9fe:a9fe', '169.254.169.254'],
			'compatible dotted'  => ['::10.0.0.1', '10.0.0.1'],
			'compatible loopback' => ['::1', '0.0.0.1'],
			'unspecified'        => ['::', '0.0.0.0'],
			'nat64'              => ['64:ff9b::a00:1', '10.0.0.1'],
			'6to4'               => ['2002:c0a8:101::1', '192.168.1.1'],
			'plain ipv4'         => ['8.8.8.8', '8.8.8.8'],
			'plain ipv6'         => ['2606:4700:0:0::1', '2606:4700::1'],
			'not an address'     => ['example.org', 'example.org'],
		];

	}//end embeddedIpv4()


	/**
	 * Normalisation rewrites embedded IPv4 to IPv4 and leaves the rest canonical.
	 *
	 * @dataProvider embeddedIpv4
	 *
	 * @param string $input    The address as written.
	 * @param string $expected The address that is classified.
	 *
	 * @return void
	 */
	public function testNormaliseRewritesEmbeddedIpv4(string $input, string $expected): void {
		$this->assertSame($expected, $this->guard->normalise($input));

	}//end testNormaliseRewritesEmbeddedIpv4()


	/**
	 * Addresses that are internal.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function internalAddresses(): array {
		return [
			'this network'       => ['0.1.2.3'],
			'unspecified'        => ['0.0.0.0'],
			'private'            => ['172.16.4.4'],
			'shared address'     => ['100.64.0.1'],
			'loopback'           => ['127.0.0.1'],
			'link-local'         => ['169.254.1.1'],
			'ietf assignments'   => ['192.0.0.1'],
			'benchmarking'       => ['198.18.0.1'],
			'multicast'          => ['224.0.0.1'],
			'broadcast'          => ['255.255.255.255'],
			'mapped loopback'    => ['::ffff:127.0.0.1'],
			'mapped metadata'    => ['::ffff:a9fe:a9fe'],
			'nat64 private'      => ['64:ff9b::a00:1'],
			'6to4 loopback'      => ['2002:7f00:1::'],
			'ipv6 loopback'      => ['::1'],
			'unique-local'       => ['fd00::1'],
			'link-local ipv6'    => ['fe80::1'],
			'site-local ipv6'    => ['fec0::1'],
			'multicast ipv6'     => ['ff02::1'],
			'discard-only ipv6'  => ['100::1'],
			'documentation ipv6' => ['2001:db8::1'],
			'not an address'     => ['example.org'],
		];

	}//end internalAddresses()


	/**
	 * Internal addresses are classified as internal in every notation.
	 *
	 * @dataProvider internalAddresses
	 *
	 * @param string $address The address to classify.
	 *
	 * @return void
	 */
	public function testInternalAddressesAreInternal(string $address): void {
		$this->assertTrue($this->guard->isInternal($address));

	}//end testInternalAddressesAreInternal()


	/**
	 * Routable addresses are not internal.
	 *
	 * @return void
	 */
	public function testRoutableAddressesAreNotInternal(): void {
		foreach (['8.8.8.8', '203.0.113.10', '::ffff:8.8.8.8', '2606:4700::1', '172.32.0.1'] as $address) {
			$this->assertFalse($this->guard->isInternal($address), $address);
		}

	}//end testRoutableAddressesAreNotInternal()


	/**
	 * A literal comes back as written, without a lookup.
	 *
	 * @return void
	 */
	public function testAddressesOfReturnsALiteral(): void {
		$this->assertSame(['::ffff:7f00:1'], $this->guard->addressesOf('[::FFFF:7F00:1]'));
		$this->assertSame(['10.0.0.1'], $this->guard->addressesOf('10.0.0.1'));

	}//end testAddressesOfReturnsALiteral()


	/**
	 * A name yields every A and AAAA answer, and each one is classified.
	 *
	 * @return void
	 */
	public function testAddressesOfReturnsEveryAnswer(): void {
		$guard = new class extends OutboundAddressGuard {
			/**
			 * Fixed answers for a public A record and a mapped AAAA record.
			 *
			 * @param string $host The host name.
			 *
			 * @return array<string>
			 */
			protected function resolve(string $host): array {
				return ['93.184.216.34', '::ffff:7f00:1'];
			}
		};

		$addresses = $guard->addressesOf('Mixed.Example.org');

		$this->assertSame(['93.184.216.34', '::ffff:7f00:1'], $addresses);
		$this->assertFalse($guard->isInternal($addresses[0]));
		$this->assertTrue($guard->isInternal($addresses[1]));

	}//end testAddressesOfReturnsEveryAnswer()


	/**
	 * A name that does not resolve yields no address.
	 *
	 * @return void
	 */
	public function testAddressesOfIsEmptyForAnUnresolvableName(): void {
		$this->assertSame([], $this->guard->addressesOf('no-such-host.invalid'));

	}//end testAddressesOfIsEmptyForAnUnresolvableName()
}//end class
