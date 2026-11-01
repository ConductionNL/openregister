<?php

/**
 * Decides whether an outbound address is internal, in any notation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Security
 *
 * @author    Conduction.nl <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Security;

/**
 * Resolves a host to every address it reaches and classifies those addresses.
 *
 * Every address is normalised before it is classified: an IPv4 address embedded
 * in IPv6 (mapped `::ffff:a.b.c.d`, compatible `::a.b.c.d`, NAT64 `64:ff9b::/96`
 * and 6to4 `2002::/16`) is checked as the IPv4 address it reaches.
 *
 * @spec exclude address classification shared by the outbound URL checks.
 */
class OutboundAddressGuard {
	/**
	 * Ranges that are not routable on the open internet.
	 *
	 * The IPv4 documentation ranges (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24)
	 * are not listed: nothing internal lives there.
	 *
	 * @var array<string>
	 */
	private const INTERNAL_RANGES = [
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.168.0.0/16',
		'198.18.0.0/15',
		'224.0.0.0/4',
		'240.0.0.0/4',
		'::/128',
		'::1/128',
		'2001:db8::/32',
		'64:ff9b:1::/48',
		'100::/64',
		'fc00::/7',
		'fe80::/10',
		'fec0::/10',
		'ff00::/8',
	];

	/**
	 * Every address a host reaches: the host itself when it is a literal,
	 * otherwise all of its A and AAAA records.
	 *
	 * @param string $host The host, with or without IPv6 brackets.
	 *
	 * @return array<string> The addresses as resolved; empty when the host does not resolve.
	 *
	 * @spec exclude address classification shared by the outbound URL checks.
	 */
	public function addressesOf(string $host): array {
		$lookupHost = trim(string: strtolower(string: $host), characters: '[]');
		if (filter_var(value: $lookupHost, filter: FILTER_VALIDATE_IP) !== false) {
			return [$lookupHost];
		}

		return $this->resolve(host: $lookupHost);
	}//end addressesOf()


	/**
	 * Whether an address is not routable on the open internet.
	 *
	 * @param string $ipAddress The address to classify.
	 *
	 * @return boolean True for private, loopback, link-local, reserved and unparseable addresses.
	 *
	 * @spec exclude address classification shared by the outbound URL checks.
	 */
	public function isInternal(string $ipAddress): bool {
		$address = $this->normalise(ipAddress: $ipAddress);
		$isPublic = filter_var(
			value: $address,
			filter: FILTER_VALIDATE_IP,
			options: (FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
		);
		if ($isPublic === false) {
			return true;
		}

		return $this->inAnyRange(ipAddress: $address, ranges: self::INTERNAL_RANGES);
	}//end isInternal()


	/**
	 * Rewrite an IPv6 address that embeds an IPv4 address to that IPv4 address.
	 *
	 * Covers IPv4-mapped (`::ffff:0:0/96`), IPv4-compatible (`::/96`), NAT64
	 * (`64:ff9b::/96`) and 6to4 (`2002::/16`). Other addresses come back in their
	 * canonical form; an unparseable value comes back unchanged.
	 *
	 * @param string $ipAddress The address to normalise.
	 *
	 * @return string The address to classify.
	 *
	 * @spec exclude address classification shared by the outbound URL checks.
	 */
	public function normalise(string $ipAddress): string {
		if (filter_var(value: $ipAddress, filter: FILTER_VALIDATE_IP, options: FILTER_FLAG_IPV6) === false) {
			return $ipAddress;
		}

		$packed = (string) inet_pton(ip: $ipAddress);
		$prefix = substr(string: $packed, offset: 0, length: 12);
		if ($prefix === str_repeat(string: "\x00", times: 10) . "\xff\xff"
			|| $prefix === str_repeat(string: "\x00", times: 12)
			|| $prefix === "\x00\x64\xff\x9b" . str_repeat(string: "\x00", times: 8)
		) {
			return (string) inet_ntop(ip: substr(string: $packed, offset: 12));
		}

		if (str_starts_with(haystack: $packed, needle: "\x20\x02") === true) {
			return (string) inet_ntop(ip: substr(string: $packed, offset: 2, length: 4));
		}

		return (string) inet_ntop(ip: $packed);
	}//end normalise()


	/**
	 * Resolve a host name to all of its A and AAAA addresses.
	 *
	 * @param string $host The host name, without brackets.
	 *
	 * @return array<string> Every address found; empty when the name does not resolve.
	 *
	 * @spec exclude address classification shared by the outbound URL checks.
	 */
	protected function resolve(string $host): array {
		$addresses = gethostbynamel(hostname: $host);
		if ($addresses === false) {
			$addresses = [];
		}

		// A failed AAAA lookup warns; that is not an error here.
		set_error_handler(callback: static fn (): bool => true);
		try {
			$records = dns_get_record(hostname: $host, type: DNS_AAAA);
		} finally {
			restore_error_handler();
		}

		if ($records === false) {
			$records = [];
		}

		foreach ($records as $record) {
			if (isset($record['ipv6']) === true) {
				$addresses[] = (string) $record['ipv6'];
			}
		}

		return $addresses;
	}//end resolve()


	/**
	 * Whether an address falls in any of the given CIDR ranges.
	 *
	 * @param string        $ipAddress A normalised, valid address.
	 * @param array<string> $ranges    CIDR ranges of either family.
	 *
	 * @return boolean True when one range contains the address.
	 */
	private function inAnyRange(string $ipAddress, array $ranges): bool {
		$packed = (string) inet_pton(ip: $ipAddress);
		foreach ($ranges as $range) {
			[$subnet, $bits] = explode(separator: '/', string: $range);
			$subnetPacked    = (string) inet_pton(ip: $subnet);
			if (strlen(string: $subnetPacked) !== strlen(string: $packed)) {
				continue;
			}

			$mask = str_pad(
				string: str_repeat(string: "\xff", times: intdiv(num1: (int) $bits, num2: 8)),
				length: strlen(string: $packed),
				pad_string: "\x00"
			);
			if (((int) $bits % 8) !== 0) {
				$mask[intdiv(num1: (int) $bits, num2: 8)] = chr(codepoint: 0xff & (0xff << (8 - ((int) $bits % 8))));
			}

			if (($packed & $mask) === ($subnetPacked & $mask)) {
				return true;
			}
		}//end foreach

		return false;
	}//end inAnyRange()
}//end class
