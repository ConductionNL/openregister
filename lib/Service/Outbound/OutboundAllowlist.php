<?php

/**
 * OpenRegister OutboundAllowlist
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-its-own-rate-limit-and-outbound-destinations-are-allowlisted-req-sat-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Outbound;

use OCP\IAppConfig;
use Throwable;

/**
 * The administered list of hosts an outbound destination may name, checked
 * when the destination is saved rather than when it is first delivered to.
 *
 * The list is the app setting `outboundAllowlist`: a JSON array of host names,
 * or one host per line or comma. An entry `*.example.nl` admits every host
 * under `example.nl` but not `example.nl` itself; any other entry admits
 * exactly that host. An unset or empty list admits every destination, which is
 * how every instance behaves until an administrator sets one.
 *
 * Fails closed: a list that is set but cannot be read refuses the destination,
 * because an allowlist nobody can read is not one that admits everything.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-its-own-rate-limit-and-outbound-destinations-are-allowlisted-req-sat-005
 */
class OutboundAllowlist {

	/**
	 * The app setting the list is administered in.
	 *
	 * @var string
	 */
	public const KEY = 'outboundAllowlist';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Reads the administered list.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Why a destination may not be saved, or null when it may.
	 *
	 * @param string $url The destination URL.
	 *
	 * @return string|null The refusal, naming the allowlist; null when admitted.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-its-own-rate-limit-and-outbound-destinations-are-allowlisted-req-sat-005
	 */
	public function refusal(string $url): ?string {
		try {
			$entries = $this->entries();
		} catch (Throwable $e) {
			return 'the outbound allowlist (' . self::KEY . ') could not be read, so no destination is admitted';
		}

		if ($entries === []) {
			return null;
		}

		$host = strtolower(trim((string)parse_url(trim($url), PHP_URL_HOST), '[]'));
		if ($host === '') {
			return 'the destination names no host, so it is not on the outbound allowlist (' . self::KEY . ')';
		}

		foreach ($entries as $entry) {
			if (self::admits(entry: $entry, host: $host) === true) {
				return null;
			}
		}

		return 'the destination host "' . $host . '" is not on the outbound allowlist (' . self::KEY . ')';
	}//end refusal()

	/**
	 * The administered entries, lower-cased, without blanks.
	 *
	 * @return array<int, string> The entries; empty when no list is set.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-its-own-rate-limit-and-outbound-destinations-are-allowlisted-req-sat-005
	 */
	public function entries(): array {
		$raw = trim($this->appConfig->getValueString('openregister', self::KEY, ''));
		if ($raw === '') {
			return [];
		}

		$list = json_decode($raw, true);
		if (is_array($list) === false) {
			$list = preg_split('/[\s,]+/', $raw);
		}

		$entries = [];
		foreach ((array)$list as $entry) {
			if (is_string($entry) === true && trim($entry) !== '') {
				$entries[] = strtolower(trim($entry));
			}
		}

		return array_values(array_unique($entries));
	}//end entries()

	/**
	 * Whether one entry admits a host.
	 *
	 * @param string $entry The entry, lower-cased.
	 * @param string $host  The host, lower-cased.
	 *
	 * @return bool
	 */
	private static function admits(string $entry, string $host): bool {
		if (str_starts_with($entry, '*.') === true) {
			$suffix = substr($entry, 1);

			return strlen($host) > strlen($suffix) && str_ends_with($host, $suffix) === true;
		}

		return $entry === $host;
	}//end admits()
}//end class
