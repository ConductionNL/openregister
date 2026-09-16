<?php

/**
 * IpRangeTest — an address binding wrong in the permissive direction is worse
 * than no binding, because an administrator reads "bound to the office range"
 * and stops thinking about it.
 *
 * Every case here is one somebody has shipped by hand.
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

use OCA\OpenRegister\Service\ApiCaller\IpRange;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ApiCaller\IpRange
 */
class IpRangeTest extends TestCase {

	/**
	 * @dataProvider provideMatches
	 */
	public function testMatching(string $address, string $range, bool $expected, string $why): void {
		$this->assertSame($expected, IpRange::matches(address: $address, range: $range), $why);
	}//end testMatching()

	public static function provideMatches(): array {
		return [
			'a literal address matches itself' => ['203.0.113.5', '203.0.113.5', true, ''],
			'a different literal does not' => ['203.0.113.6', '203.0.113.5', false, ''],
			'inside a /24' => ['203.0.113.5', '203.0.113.0/24', true, ''],
			'outside a /24' => ['203.0.114.5', '203.0.113.0/24', false, ''],
			'inside a /8' => ['10.4.5.6', '10.0.0.0/8', true, ''],
			'outside a /8' => ['11.4.5.6', '10.0.0.0/8', false, ''],
			'a /32 is exactly one address' => ['10.0.0.1', '10.0.0.10/32', false,
				'String-prefix matching answers yes here, and it is the most common way this gets written.'],
			'a /0 is everything' => ['1.2.3.4', '0.0.0.0/0', true, ''],
			'a partial byte boundary, inside' => ['192.168.1.130', '192.168.1.128/25', true, ''],
			'a partial byte boundary, outside' => ['192.168.1.127', '192.168.1.128/25', false, ''],
			'ipv6 inside its prefix' => ['2001:db8::1', '2001:db8::/32', true, ''],
			'ipv6 outside its prefix' => ['2001:db9::1', '2001:db8::/32', false, ''],
			'ipv4 never matches an ipv6 range' => ['203.0.113.5', '2001:db8::/32', false,
				'Comparing a 4-byte and a 16-byte value by prefix compares the shorter one and says yes far too often.'],
			'ipv6 never matches an ipv4 range' => ['2001:db8::1', '203.0.113.0/24', false, ''],
			'an ipv4-mapped ipv6 matches its ipv4 binding' => ['::ffff:192.0.2.1', '192.0.2.0/24', true,
				'A reverse proxy produces this form; left alone the control fails closed on a caller that did nothing wrong.'],
			'an ipv4-mapped ipv6 matches its ipv4 literal' => ['::ffff:203.0.113.5', '203.0.113.5', true, ''],
			'a prefix longer than the family is a typo, not a wildcard' => ['203.0.113.5', '203.0.113.0/33', false, ''],
			'an ipv6 prefix over 128 is a typo' => ['2001:db8::1', '2001:db8::/129', false, ''],
			'a non-numeric prefix is refused' => ['203.0.113.5', '203.0.113.0/all', false, ''],
			'a hostname is not an address' => ['203.0.113.5', 'example.test', false, ''],
			'garbage in the address is refused' => ['not an address', '0.0.0.0/0', false, ''],
			'an empty range is refused' => ['203.0.113.5', '', false, ''],
			'an empty address is refused' => ['', '203.0.113.0/24', false, ''],
			'surrounding whitespace is tolerated' => [' 203.0.113.5 ', ' 203.0.113.0/24 ', true, ''],
		];
	}//end provideMatches()
}//end class
