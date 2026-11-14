<?php

/**
 * OpenRegister Outbound URL Guard
 *
 * The address rules every server-side request to a user-supplied URL passes:
 * webhook deliveries and harvest fetches. Moved out of WebhookService so both
 * apply one policy, with the same refusal messages.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Http
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Http;

use OCA\OpenRegister\Service\Security\OutboundAddressGuard;
use RuntimeException;

/**
 * Refuses an outbound URL whose scheme is not http(s) or whose host reaches an internal address.
 *
 * Rules:
 *  - Only http/https schemes are permitted, and a host is required.
 *  - Every A and AAAA record of the host is checked; a host that does not
 *    resolve is refused (the client resolves again when it connects).
 *  - IPv4: loopback, RFC-1918 and link-local (which holds the cloud metadata
 *    address) are refused with their own label.
 *  - IPv6: loopback, unspecified, unique-local, link-local, documentation and
 *    IPv4-mapped are refused with their own label.
 *  - Anything else {@see OutboundAddressGuard::isInternal()} calls internal is
 *    refused as `reserved`.
 *
 * The caller checks every redirect hop through the same method.
 */
class OutboundUrlGuard {
	/**
	 * Constructor.
	 *
	 * @param OutboundAddressGuard $addresses Resolves hosts and classifies addresses.
	 * @param string               $label     How refusal messages name the URL ("Webhook target URL").
	 */
	public function __construct(
		private readonly OutboundAddressGuard $addresses = new OutboundAddressGuard(),
		private readonly string $label = 'Target URL',
	) {
	}//end __construct()

	/**
	 * Refuse a URI that breaks the rules above.
	 *
	 * @param string $uri          Full request URI.
	 * @param bool   $allowPrivate Skip the address checks (scheme and host stay enforced).
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the URI is refused.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is the per-hook allowPrivateTargets opt-in.
	 */
	public function assertSafe(string $uri, bool $allowPrivate = false): void {
		$parsed = parse_url($uri);
		if ($parsed === false) {
			throw new RuntimeException($this->label . ' is not parseable');
		}

		$scheme = strtolower($parsed['scheme'] ?? '');
		if (in_array($scheme, ['http', 'https'], true) === false) {
			throw new RuntimeException(
				$this->label . ' must use http or https scheme; got "' . $scheme . '"'
			);
		}

		$host = strtolower($parsed['host'] ?? '');
		if ($host === '') {
			throw new RuntimeException($this->label . ' is missing a host');
		}

		if ($allowPrivate === true) {
			return;
		}

		$addresses = $this->addresses->addressesOf(host: $host);
		if (empty($addresses) === true) {
			throw new RuntimeException($this->label . ' host could not be resolved');
		}

		foreach ($addresses as $address) {
			$reason = $this->blockedAddressReason(address: $address);
			if ($reason !== null) {
				throw new RuntimeException(
					$this->label . ' resolves to a blocked IP range (' . $reason . ')'
				);
			}
		}
	}//end assertSafe()

	/**
	 * Whether a host resolves to an internal address.
	 *
	 * @param string $host The host.
	 *
	 * @return bool True when any of its addresses is refused.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function isPrivateHost(string $host): bool {
		if ($host === '') {
			return false;
		}

		foreach ($this->addresses->addressesOf(host: $host) as $address) {
			if ($this->blockedAddressReason(address: $address) !== null) {
				return true;
			}
		}

		return false;
	}//end isPrivateHost()

	/**
	 * The refusal label for one resolved address, or null when it is allowed.
	 *
	 * @param string $address An IPv4 or IPv6 address.
	 *
	 * @return string|null The label, or null.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function blockedAddressReason(string $address): ?string {
		$reason = $this->blockedIpv4Reason(ip: $address);
		if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
			$reason = $this->blockedIpv6Reason(ip: $address);
		}

		if ($reason === null && $this->addresses->isInternal(ipAddress: $address) === true) {
			$reason = 'reserved';
		}

		return $reason;
	}//end blockedAddressReason()

	/**
	 * The refusal label for an IPv6 address, or null when it is not in a listed range.
	 *
	 * @param string $ip IPv6 address (no brackets).
	 *
	 * @return string|null The label, or null.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function blockedIpv6Reason(string $ip): ?string {
		$bin = @inet_pton($ip);
		if ($bin === false || strlen($bin) !== 16) {
			return null;
		}

		$fixed = [
			"\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01" => 'loopback',
			"\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" => 'unspecified',
		];
		if (isset($fixed[$bin]) === true) {
			return $fixed[$bin];
		}

		if ((ord($bin[0]) & 0xFE) === 0xFC) {
			return 'unique-local';
		}

		if (ord($bin[0]) === 0xFE && (ord($bin[1]) & 0xC0) === 0x80) {
			return 'link-local';
		}

		if (substr($bin, 0, 4) === "\x20\x01\x0d\xb8") {
			return 'documentation';
		}

		if (substr($bin, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
			return $this->mappedIpv4Reason(embedded: substr($bin, 12, 4));
		}

		return null;
	}//end blockedIpv6Reason()

	/**
	 * The label for an IPv4-mapped IPv6 address: the mapped form is always refused.
	 *
	 * @param string $embedded The four bytes of the embedded IPv4 address.
	 *
	 * @return string The label.
	 */
	private function mappedIpv4Reason(string $embedded): string {
		$embeddedIpv4 = inet_ntop($embedded);
		$reason       = null;
		if ($embeddedIpv4 !== false) {
			$reason = $this->blockedIpv4Reason(ip: $embeddedIpv4);
		}

		$labels = [
			'loopback' => 'IPv4-mapped loopback',
			'RFC-1918' => 'IPv4-mapped RFC-1918',
			'link-local/metadata' => 'IPv4-mapped link-local/metadata',
		];

		return ($labels[$reason] ?? 'IPv4-mapped');
	}//end mappedIpv4Reason()

	/**
	 * The refusal label for an IPv4 address, or null when it is not in a listed range.
	 *
	 * @param string $ip IPv4 address.
	 *
	 * @return string|null `loopback`, `RFC-1918` or `link-local/metadata`, or null.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function blockedIpv4Reason(string $ip): ?string {
		$longIp = ip2long($ip);
		if ($longIp === false) {
			return null;
		}

		$ranges = [
			['loopback', 0xFF000000, 0x7F000000],
			['RFC-1918', 0xFF000000, 0x0A000000],
			['RFC-1918', 0xFFF00000, 0xAC100000],
			['RFC-1918', 0xFFFF0000, 0xC0A80000],
			['link-local/metadata', 0xFFFF0000, 0xA9FE0000],
		];
		foreach ($ranges as [$label, $mask, $network]) {
			if (($longIp & $mask) === $network) {
				return $label;
			}
		}

		return null;
	}//end blockedIpv4Reason()
}//end class
