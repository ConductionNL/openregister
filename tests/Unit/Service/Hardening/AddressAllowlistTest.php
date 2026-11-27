<?php

/**
 * AddressAllowlistTest — single addresses and CIDR ranges, IPv4 and IPv6.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Hardening;

use OCA\OpenRegister\Service\Hardening\AddressAllowlist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Hardening\AddressAllowlist
 */
class AddressAllowlistTest extends TestCase {

	/**
	 * Entries, an address, and whether the list holds it.
	 *
	 * @return array<string, array{0: array<int, string>, 1: string, 2: bool}>
	 */
	public static function addressCases(): array {
		return [
			'exact IPv4' => [['192.0.2.10'], '192.0.2.10', true],
			'other IPv4' => [['192.0.2.10'], '192.0.2.11', false],
			'IPv4 /24 inside' => [['192.0.2.0/24'], '192.0.2.200', true],
			'IPv4 /24 outside' => [['192.0.2.0/24'], '192.0.3.1', false],
			'IPv4 /20 partial byte inside' => [['10.1.16.0/20'], '10.1.31.255', true],
			'IPv4 /20 partial byte outside' => [['10.1.16.0/20'], '10.1.32.0', false],
			'IPv4 /0 holds all' => [['0.0.0.0/0'], '203.0.113.9', true],
			'IPv6 /32 inside' => [['2001:db8::/32'], '2001:db8:ffff::1', true],
			'IPv6 /32 outside' => [['2001:db8::/32'], '2001:db9::1', false],
			'IPv6 exact, other notation' => [['2001:db8:0:0::1'], '2001:DB8::1', true],
			'IPv4 never in an IPv6 range' => [['::/0'], '192.0.2.1', false],
			'IPv4-mapped IPv6 read as IPv4' => [['192.0.2.0/24'], '::ffff:192.0.2.7', true],
			'second entry holds it' => [['198.51.100.1', '192.0.2.0/24'], '192.0.2.1', true],
			'unreadable address' => [['0.0.0.0/0'], 'not-an-address', false],
			'empty address' => [['0.0.0.0/0'], '', false],
			'empty list holds nothing' => [[], '192.0.2.1', false],
			'invalid entry matches nothing' => [['192.0.2.0/33'], '192.0.2.1', false],
		];
	}

	/**
	 * @param array<int, string> $entries The list.
	 * @param string             $address The address.
	 * @param bool               $held    Whether the list holds it.
	 *
	 * @return void
	 */
	#[DataProvider('addressCases')]
	public function testContains(array $entries, string $address, bool $held): void {
		self::assertSame($held, AddressAllowlist::contains(entries: $entries, address: $address));
	}

	/**
	 * Which entries are an address or a range.
	 *
	 * @return void
	 */
	public function testIsValidEntry(): void {
		foreach (['192.0.2.1', '192.0.2.0/24', '0.0.0.0/0', '2001:db8::/32', '::1', ' 10.0.0.0/8 '] as $valid) {
			self::assertTrue(AddressAllowlist::isValidEntry(entry: $valid), $valid);
		}

		foreach (['', 'example.nl', '192.0.2.0/33', '2001:db8::/129', '192.0.2.0/', '192.0.2.0/x', '192.0.2.0/-1', '300.0.0.1', '192.0.2.0/24/8'] as $invalid) {
			self::assertFalse(AddressAllowlist::isValidEntry(entry: $invalid), $invalid);
		}
	}

	/**
	 * A stored list is trimmed, lowercased, de-duplicated, and keeps only valid entries.
	 *
	 * @return void
	 */
	public function testParse(): void {
		self::assertSame(
			['192.0.2.0/24', '2001:db8::/32'],
			AddressAllowlist::parse(raw: ' 192.0.2.0/24 ,2001:DB8::/32,, garbage,192.0.2.0/24')
		);
		self::assertSame([], AddressAllowlist::parse(raw: ''));
	}
}
