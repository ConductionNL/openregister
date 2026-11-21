<?php

/**
 * Whether one address falls inside one administered range.
 *
 * WHY THIS IS ITS OWN CLASS. An address binding is a security control, and a
 * security control that is wrong in the permissive direction is worse than no
 * control: an administrator reads "bound to the leverancier's office range"
 * and stops thinking about it. So the matching is separated from the policy
 * that uses it and tested on its own, including the cases that have bitten
 * everybody who has written this by hand:
 *
 *  - `10.0.0.1` must not match the range `10.0.0.10/32` by string prefix.
 *  - An IPv4 address must never match an IPv6 range, or the reverse.
 *  - `::ffff:192.0.2.1`, the IPv4-mapped IPv6 form a proxy can produce, has to
 *    match a `192.0.2.0/24` binding, or the control fails CLOSED in a way that
 *    looks like the leverancier's network changed.
 *  - A prefix length outside the family's range is a typo, not a wildcard.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiCaller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ApiCaller;

/**
 * Address and CIDR matching for the caller address binding.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiCaller
 */
final class IpRange {

	/**
	 * Whether an address falls inside a range.
	 *
	 * @param string $address The address the call came from.
	 * @param string $range A literal address, or CIDR notation.
	 *
	 * @return bool True when the address is inside the range.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public static function matches(string $address, string $range): bool {
		$packedAddress = self::pack(value: trim($address));
		if ($packedAddress === null) {
			return false;
		}

		$trimmedRange = trim($range);
		if (str_contains($trimmedRange, '/') === false) {
			return self::matchesLiteral(packedAddress: $packedAddress, literal: $trimmedRange);
		}

		return self::matchesCidr(packedAddress: $packedAddress, cidr: $trimmedRange);
	}//end matches()

	/**
	 * Whether an address is one literal address.
	 *
	 * @param string $packedAddress The packed address.
	 * @param string $literal       The literal address, trimmed.
	 *
	 * @return bool True when they are the same address.
	 */
	private static function matchesLiteral(string $packedAddress, string $literal): bool {
		$packedRange = self::pack(value: $literal);

		return ($packedRange !== null && hash_equals($packedRange, $packedAddress));
	}//end matchesLiteral()

	/**
	 * Whether an address falls inside a CIDR range of its own family.
	 *
	 * @param string $packedAddress The packed address.
	 * @param string $cidr          The range in CIDR notation, trimmed.
	 *
	 * @return bool True when the address is inside the range.
	 */
	private static function matchesCidr(string $packedAddress, string $cidr): bool {
		[$network, $prefix] = explode('/', $cidr, 2);

		$packedNetwork = self::pack(value: trim($network));
		if ($packedNetwork === null) {
			return false;
		}

		// A range and an address from different families never match. Without
		// this, comparing a 4-byte and a 16-byte string by prefix would compare
		// whichever is shorter and answer yes far too often.
		if (strlen($packedNetwork) !== strlen($packedAddress)) {
			return false;
		}

		$bits = self::prefixBits(prefix: trim($prefix), packedLength: strlen($packedNetwork));
		if ($bits === null) {
			return false;
		}

		return self::prefix(packed: $packedAddress, bits: $bits) === self::prefix(packed: $packedNetwork, bits: $bits);
	}//end matchesCidr()

	/**
	 * Pack an address into its binary form, normalising the IPv4-mapped shape.
	 *
	 * A reverse proxy in front of this instance can present `::ffff:192.0.2.1`
	 * for a caller an administrator wrote down as `192.0.2.1`. Left alone, that
	 * is a 16-byte value that can never match a 4-byte binding, and the control
	 * refuses a caller that did nothing wrong.
	 *
	 * @param string $value The address.
	 *
	 * @return string|null The packed address, or null when it is not one.
	 */
	private static function pack(string $value): ?string {
		if ($value === '') {
			return null;
		}

		$candidate = $value;
		if (stripos($candidate, '::ffff:') === 0) {
			$tail = substr($candidate, 7);
			if (filter_var($tail, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
				$candidate = $tail;
			}
		}

		if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
			return null;
		}

		$packed = inet_pton($candidate);
		if ($packed === false) {
			return null;
		}

		return $packed;

	}//end pack()

	/**
	 * Read a prefix length, refusing one outside the family's range.
	 *
	 * @param string $prefix The declared prefix length.
	 * @param int $packedLength The packed address length: 4 or 16 bytes.
	 *
	 * @return int|null The prefix length in bits, or null when it is not valid.
	 */
	private static function prefixBits(string $prefix, int $packedLength): ?int {
		if (preg_match('/^[0-9]{1,3}$/', $prefix) !== 1) {
			return null;
		}

		$bits = (int)$prefix;
		$maximum = ($packedLength * 8);
		if ($bits > $maximum) {
			return null;
		}

		return $bits;

	}//end prefixBits()

	/**
	 * The leading bits of a packed address, as a string that compares equal
	 * for two addresses exactly when those bits are equal.
	 *
	 * @param string $packed The packed address.
	 * @param int    $bits   The prefix length, at most the address length in bits.
	 *
	 * @return string The whole leading bytes, then the masked partial byte when the prefix ends inside one.
	 */
	private static function prefix(string $packed, int $bits): string {
		$wholeBytes = intdiv($bits, 8);
		$prefix = substr($packed, 0, $wholeBytes);
		$remainder = ($bits % 8);
		if ($remainder === 0) {
			return $prefix;
		}

		$mask = (0xFF << (8 - $remainder)) & 0xFF;

		return $prefix . chr(ord($packed[$wholeBytes]) & $mask);

	}//end prefix()
}//end class
