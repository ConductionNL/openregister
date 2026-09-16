<?php

/**
 * What one caller is allowed: how often, and from where.
 *
 * WHY A CALLER AND NOT A USER. A gemeente hands a token to a leverancier and
 * today gets no way to bound it: the token carries the issuing handler's whole
 * desk, and if that leverancier's cron job goes into a loop at three in the
 * morning the first anyone knows is the database. Taiga bounds it per
 * application, osticket binds a key to an address, and this instance could do
 * neither.
 *
 * 🔑 THE POLICY IS ADMINISTERED ON THE PRINCIPAL, NOT ON THE TOKEN, AND THAT
 * IS A DELIBERATE WAYPOINT. A first-class token entity with its own grant is
 * `scoped-api-tokens`, a separate change that has not landed. Waiting for it
 * would leave a gemeente with no ceiling at all in the meantime, and the
 * mechanism is the same either way: resolve a caller, look up its policy,
 * refuse over the limit or off the address list. When the token entity lands,
 * the lookup moves onto it and everything below is unchanged.
 *
 * 🔴 NO CEILING UNLESS ONE IS ADMINISTERED. An unconfigured instance limits
 * nothing, because a default ceiling on an API that has never had one turns
 * every existing integration into an incident on upgrade day. The wildcard
 * entry is how an administrator says "everyone, unless named otherwise".
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
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ApiCaller;

use OCP\IAppConfig;
use Throwable;

/**
 * Resolves the administered ceiling and address binding for one caller.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiCaller
 */
class CallerPolicy {

	/**
	 * The app the configuration lives under.
	 *
	 * @var string
	 */
	private const APP_ID = 'openregister';

	/**
	 * Configuration key holding the per-caller ceilings, a JSON map.
	 *
	 * @var string
	 */
	public const LIMITS_KEY = 'api_caller_limits';

	/**
	 * Configuration key holding the per-caller address bindings, a JSON map.
	 *
	 * @var string
	 */
	public const ADDRESSES_KEY = 'api_caller_addresses';

	/**
	 * The key standing for every caller that is not named.
	 *
	 * @var string
	 */
	public const WILDCARD = '*';

	/**
	 * The key standing for a caller with no session.
	 *
	 * @var string
	 */
	public const ANONYMOUS = 'anonymous';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Reads the administered policy.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * The ceiling for one caller, or null when none is administered.
	 *
	 * A named entry wins over the wildcard, so an administrator can bound
	 * everybody loosely and one runaway leverancier tightly.
	 *
	 * @param string $principal The caller, or the empty string for anonymous.
	 *
	 * @return array{limit: int, windowSeconds: int}|null The ceiling, or null.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public function limitFor(string $principal): ?array {
		$limits = $this->read(key: self::LIMITS_KEY);
		if ($limits === []) {
			return null;
		}

		$declared = ($limits[$this->key(principal: $principal)] ?? $limits[self::WILDCARD] ?? null);

		return $this->normaliseLimit(declared: $declared);

	}//end limitFor()

	/**
	 * The addresses one caller may call from, or null when it is not bound.
	 *
	 * @param string $principal The caller.
	 *
	 * @return array<int, string>|null The allowed addresses and ranges, or null.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public function addressesFor(string $principal): ?array {
		$bindings = $this->read(key: self::ADDRESSES_KEY);
		if ($bindings === []) {
			return null;
		}

		// No wildcard fallback here, on purpose. A ceiling applied to everyone
		// is a sensible default posture; an address binding applied to everyone
		// locks out every caller an administrator forgot to list, including
		// themselves, from a JSON blob with no confirmation step.
		$declared = ($bindings[$this->key(principal: $principal)] ?? null);
		if (is_array($declared) === false) {
			return null;
		}

		$allowed = [];
		foreach ($declared as $entry) {
			if (is_string($entry) === true && trim($entry) !== '') {
				$allowed[] = trim($entry);
			}
		}

		if ($allowed === []) {
			return null;
		}

		return $allowed;

	}//end addressesFor()

	/**
	 * Whether a caller bound to addresses may call from this one.
	 *
	 * @param string $principal The caller.
	 * @param string $address The address the call came from.
	 *
	 * @return bool True when the call is allowed from this address.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public function allowsAddress(string $principal, string $address): bool {
		$allowed = $this->addressesFor(principal: $principal);
		if ($allowed === null) {
			return true;
		}

		foreach ($allowed as $candidate) {
			if (IpRange::matches(address: $address, range: $candidate) === true) {
				return true;
			}
		}

		return false;

	}//end allowsAddress()

	/**
	 * The key a principal is looked up under.
	 *
	 * @param string $principal The caller, or the empty string.
	 *
	 * @return string The lookup key.
	 */
	private function key(string $principal): string {
		$trimmed = trim($principal);
		if ($trimmed === '') {
			return self::ANONYMOUS;
		}

		return $trimmed;

	}//end key()

	/**
	 * Read and decode one administered map.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return array<string, mixed> The map, or an empty one.
	 */
	private function read(string $key): array {
		try {
			$raw = trim((string)$this->appConfig->getValueString(self::APP_ID, $key, ''));
		} catch (Throwable) {
			return [];
		}

		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;

	}//end read()

	/**
	 * Turn a declared ceiling into one that can be applied, or null.
	 *
	 * A malformed entry means no ceiling rather than a guessed one. Guessing a
	 * limit out of a typo is how an administrator ends up refusing traffic they
	 * never meant to bound.
	 *
	 * @param mixed $declared The declared ceiling.
	 *
	 * @return array{limit: int, windowSeconds: int}|null The ceiling, or null.
	 */
	private function normaliseLimit(mixed $declared): ?array {
		if (is_array($declared) === false) {
			return null;
		}

		$limit = (int)($declared['limit'] ?? 0);
		$window = (int)($declared['windowSeconds'] ?? 60);

		if ($limit < 1 || $window < 1) {
			return null;
		}

		return [
			'limit' => $limit,
			'windowSeconds' => $window,
		];

	}//end normaliseLimit()
}//end class
