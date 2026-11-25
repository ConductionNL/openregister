<?php

/**
 * An address allowlist: single addresses and ranges, IPv4 and IPv6.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hardening;

/**
 * Parses, validates and matches an address allowlist.
 *
 * An entry is one address (`192.0.2.10`, `2001:db8::1`) or a range in CIDR
 * notation (`192.0.2.0/24`, `2001:db8::/32`). An IPv4 address never matches
 * an IPv6 range or the other way round, except that an IPv4-mapped IPv6
 * address (`::ffff:192.0.2.10`) is read as the IPv4 address it carries, so a
 * dual-stack listener does not lock an IPv4 range out.
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
 */
final class AddressAllowlist {

	/**
	 * Split a stored allowlist into its entries.
	 *
	 * Entries are trimmed and lowercased, blanks are dropped and each entry is
	 * kept once. An entry that is not an address or a range is dropped too:
	 * the write path refuses one, so a stored invalid entry can only come from
	 * a hand edit, and reading it as "matches nothing" keeps the list closed.
	 *
	 * @param string $raw The comma-separated stored value.
	 *
	 * @return array<int, string> The valid entries, in stored order.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
	 */
	public static function parse(string $raw): array {
		$entries = [];
		foreach (explode(',', $raw) as $candidate) {
			$entry = strtolower(trim($candidate));
			if ($entry !== '' && self::isValidEntry(entry: $entry) === true && in_array($entry, $entries, true) === false) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}//end parse()

	/**
	 * Whether a text is one address or one CIDR range.
	 *
	 * @param string $entry The candidate entry.
	 *
	 * @return bool True when it can be matched against.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
	 */
	public static function isValidEntry(string $entry): bool {
		return self::range(entry: trim($entry)) !== null;
	}//end isValidEntry()

	/**
	 * Whether an address is on the list.
	 *
	 * An empty list holds nothing; deciding what an empty list means for a
	 * surface is the caller's business. An address that cannot be read is on
	 * no list.
	 *
	 * @param array<int, string> $entries The allowlist entries.
	 * @param string             $address The address to look up.
	 *
	 * @return bool True when an entry holds the address.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
	 */
	public static function contains(array $entries, string $address): bool {
		$packed = self::packed(address: trim($address));
		if ($packed === null) {
			return false;
		}

		foreach ($entries as $entry) {
			$range = self::range(entry: trim((string)$entry));
			if ($range !== null && self::inRange(packed: $packed, network: $range[0], bits: $range[1]) === true) {
				return true;
			}
		}

		return false;
	}//end contains()

	/**
	 * An entry as its packed network and prefix length, or null when it is neither an address nor a range.
	 *
	 * @param string $entry The entry.
	 *
	 * @return array{0: string, 1: int}|null The packed network and the prefix length.
	 */
	private static function range(string $entry): ?array {
		$address = $entry;
		$bits    = null;
		$slash   = strpos($entry, '/');
		if ($slash !== false) {
			$address = substr($entry, 0, $slash);
			$prefix  = substr($entry, ($slash + 1));
			if ($prefix === '' || ctype_digit($prefix) === false) {
				return null;
			}

			$bits = (int)$prefix;
		}

		$packed = self::packed(address: $address);
		if ($packed === null) {
			return null;
		}

		$width = (strlen($packed) * 8);
		if ($bits === null) {
			$bits = $width;
		}

		if ($bits > $width) {
			return null;
		}

		return [$packed, $bits];
	}//end range()

	/**
	 * An address in packed binary form, with an IPv4-mapped IPv6 address read as IPv4.
	 *
	 * @param string $address The address text.
	 *
	 * @return string|null The 4 or 16 packed bytes, or null when the text is not an address.
	 */
	private static function packed(string $address): ?string {
		if ($address === '' || filter_var($address, FILTER_VALIDATE_IP) === false) {
			return null;
		}

		$packed = inet_pton($address);
		if ($packed === false) {
			return null;
		}

		if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
			return substr($packed, 12);
		}

		return $packed;
	}//end packed()

	/**
	 * Whether a packed address lies in a packed network of the given prefix length.
	 *
	 * @param string $packed  The packed address.
	 * @param string $network The packed network.
	 * @param int    $bits    The prefix length.
	 *
	 * @return bool True when the leading bits agree and both are of one family.
	 */
	private static function inRange(string $packed, string $network, int $bits): bool {
		if (strlen($packed) !== strlen($network)) {
			return false;
		}

		$whole = intdiv($bits, 8);
		if (substr($packed, 0, $whole) !== substr($network, 0, $whole)) {
			return false;
		}

		$rest = ($bits % 8);
		if ($rest === 0) {
			return true;
		}

		$mask = ((0xff << (8 - $rest)) & 0xff);
		return (ord($packed[$whole]) & $mask) === (ord($network[$whole]) & $mask);
	}//end inRange()
}//end class
