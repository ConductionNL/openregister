<?php

/**
 * The administered half of the instance's security posture, and the floors it
 * declared for itself.
 *
 * Two things live here, and they are deliberately in one class because they
 * are read together on every path:
 *
 *  - **The administered values.** The inbound authentication ceiling and the
 *    origin allowlist. Both are read by the code that enforces them
 *    ({@see \OCA\OpenRegister\Service\SecurityService} and
 *    {@see \OCA\OpenRegister\Middleware\PublicApiCorsMiddleware}), so the
 *    number an administrator reads out of the hardening report and the number
 *    that locks a client out are the same number.
 *  - **The declared floors.** A floor is the weakest an administrator has
 *    agreed this instance may get. It is not a default and not a suggestion:
 *    {@see HardeningFloorGuard} refuses the write that would cross it.
 *
 * 🔴 A FLOOR MAY BE RAISED AND NEVER LOWERED PAST THE BASELINE. Lowering a
 * floor is the cheapest way to make a failing control pass, so the baseline in
 * `CONTROLS` is a hard bound on the floor itself. Without that, "declare a
 * floor" is a synonym for "silence the report".
 *
 * 🔑 AN UNREADABLE VALUE IS NOT A PASSING VALUE (ADR-005). Every read here
 * falls back to the baseline rather than to whatever the caller hoped for, and
 * a value that cannot be read at all is reported as null, which fails its
 * floor.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hardening;

use OCP\IAppConfig;
use Throwable;

/**
 * Reads the administered controls and the declared floors.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 */
class HardeningPolicy {

	/**
	 * The app the administered controls live under.
	 *
	 * @var string
	 */
	public const APP_ID = 'openregister';

	/**
	 * Where the declared floors are stored, as a JSON map of control to floor.
	 *
	 * @var string
	 */
	public const FLOORS_KEY = 'hardening_floors';

	/**
	 * Where the origin allowlist is stored, as a comma-separated list.
	 *
	 * @var string
	 */
	public const ORIGINS_KEY = 'hardening_allowed_origins';

	/**
	 * The controls an administrator may change through OpenRegister.
	 *
	 * Each entry is `control id => [config key, baseline, comparator]`. The
	 * baseline is both the shipped default and the strongest floor the guard
	 * will let an administrator declare away from.
	 *
	 * @var array<string, array{0: string, 1: int, 2: string}>
	 */
	public const CONTROLS = [
		'auth.rateLimit.attemptsPerIdentity' => ['hardening_auth_attempts_identity', 20, 'atMost'],
		'auth.rateLimit.attemptsPerAddress' => ['hardening_auth_attempts_address', 100, 'atMost'],
		'auth.rateLimit.windowSeconds' => ['hardening_auth_window_seconds', 900, 'atLeast'],
		'auth.rateLimit.lockoutSeconds' => ['hardening_auth_lockout_seconds', 900, 'atLeast'],
		'origins.allowlistEntries' => [self::ORIGINS_KEY, 0, 'atLeast'],
	];

	/**
	 * The controls this instance reports but does not administer.
	 *
	 * Each entry is `control id => [baseline floor, comparator]`. Nextcloud
	 * owns the password policy, the session policy and the brute-force
	 * throttler, and PHP owns the upload ceiling. Building a second password
	 * policy beside Nextcloud's would give a gemeente two answers to one
	 * question, so these are read, judged against a floor, and reported. The
	 * administrator fixes a failing one where it actually lives.
	 *
	 * @var array<string, array{0: int, 1: string}>
	 */
	public const REPORTED_CONTROLS = [
		'password.minimumLength' => [10, 'atLeast'],
		'password.blocksCommonPasswords' => [1, 'atLeast'],
		'password.checksBreachDatabase' => [1, 'atLeast'],
		'session.lifetimeSeconds' => [86400, 'atMost'],
		'session.rememberLoginSeconds' => [1296000, 'atMost'],
		'session.secondFactorEnforced' => [0, 'atLeast'],
		'bruteForce.platformThrottlerEnabled' => [1, 'atLeast'],
		'bruteForce.throttledSurfaces' => [6, 'atLeast'],
		'login.rateLimit.attempts' => [5, 'atMost'],
		'login.rateLimit.windowSeconds' => [900, 'atLeast'],
		'login.lockoutSeconds' => [3600, 'atLeast'],
		'upload.ceilingBytes' => [1073741824, 'atMost'],
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Reads the administered controls and the floors.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * The inbound authentication ceiling this instance enforces.
	 *
	 * The shape {@see \OCA\OpenRegister\Service\SecurityService} applies and
	 * {@see \OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesService}
	 * publishes. One resolution, three readers.
	 *
	 * @return array{attemptsPerIdentity: int, attemptsPerAddress: int, windowSeconds: int, lockoutSeconds: int} The ceiling.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function authRateLimit(): array {
		return [
			'attemptsPerIdentity' => $this->administered(control: 'auth.rateLimit.attemptsPerIdentity'),
			'attemptsPerAddress' => $this->administered(control: 'auth.rateLimit.attemptsPerAddress'),
			'windowSeconds' => $this->administered(control: 'auth.rateLimit.windowSeconds'),
			'lockoutSeconds' => $this->administered(control: 'auth.rateLimit.lockoutSeconds'),
		];

	}//end authRateLimit()

	/**
	 * The origins a browser on another site may read a public answer from.
	 *
	 * An empty list means this instance has not bound its public surface, and
	 * the middleware keeps reflecting whatever origin asks, as it did before
	 * this control existed. That is the backwards-compatible reading, and the
	 * report says so in as many words rather than letting an empty list pass
	 * for a configured one.
	 *
	 * @return array<int, string> The allowlisted origins, lowercased, in declaration order.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function allowedOrigins(): array {
		try {
			$raw = $this->appConfig->getValueString(self::APP_ID, self::ORIGINS_KEY, '');
		} catch (Throwable) {
			$raw = '';
		}

		return self::parseOrigins(raw: $raw);

	}//end allowedOrigins()

	/**
	 * Split a stored allowlist into its entries.
	 *
	 * @param string $raw The comma-separated stored value.
	 *
	 * @return array<int, string> The entries, trimmed, lowercased and de-duplicated.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public static function parseOrigins(string $raw): array {
		$entries = [];
		foreach (explode(',', $raw) as $candidate) {
			$origin = strtolower(trim($candidate));
			if ($origin !== '' && in_array($origin, $entries, true) === false) {
				$entries[] = $origin;
			}
		}

		return $entries;

	}//end parseOrigins()

	/**
	 * The value in force for one administered control.
	 *
	 * @param string $control The control identifier.
	 *
	 * @return int The administered value, or the baseline when nothing is stored.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function administered(string $control): int {
		if (isset(self::CONTROLS[$control]) === false) {
			return 0;
		}

		[$key, $baseline] = self::CONTROLS[$control];

		if ($control === 'origins.allowlistEntries') {
			return count($this->allowedOrigins());
		}

		try {
			$value = $this->appConfig->getValueInt(self::APP_ID, $key, $baseline);
		} catch (Throwable) {
			return $baseline;
		}

		if ($value <= 0) {
			return $baseline;
		}

		return $value;

	}//end administered()

	/**
	 * The floor in force for one control.
	 *
	 * @param string $control The control identifier.
	 *
	 * @return int The declared floor, or the baseline when none was declared.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function floor(string $control): int {
		$declared = $this->declaredFloors();
		if (isset($declared[$control]) === true) {
			return $declared[$control];
		}

		return self::baseline(control: $control);

	}//end floor()

	/**
	 * The shipped baseline for one control.
	 *
	 * @param string $control The control identifier.
	 *
	 * @return int The baseline, or 0 for a control with no declaration.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public static function baseline(string $control): int {
		if (isset(self::CONTROLS[$control]) === true) {
			return self::CONTROLS[$control][1];
		}

		if (isset(self::REPORTED_CONTROLS[$control]) === true) {
			return self::REPORTED_CONTROLS[$control][0];
		}

		return 0;

	}//end baseline()

	/**
	 * How a control's value is compared with its floor.
	 *
	 * @param string $control The control identifier.
	 *
	 * @return string `atLeast` or `atMost`.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public static function comparator(string $control): string {
		if (isset(self::CONTROLS[$control]) === true) {
			return self::CONTROLS[$control][2];
		}

		if (isset(self::REPORTED_CONTROLS[$control]) === true) {
			return self::REPORTED_CONTROLS[$control][1];
		}

		return 'atLeast';

	}//end comparator()

	/**
	 * Every floor this instance declared, read from storage.
	 *
	 * A stored map that cannot be decoded resolves to no declarations at all,
	 * which leaves every control on its baseline. Failing to the baseline is
	 * the closed direction: a corrupt floor map must not read as permission.
	 *
	 * @return array<string, int> Control identifier to declared floor.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function declaredFloors(): array {
		try {
			$raw = $this->appConfig->getValueString(self::APP_ID, self::FLOORS_KEY, '');
		} catch (Throwable) {
			return [];
		}

		if (trim($raw) === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		$floors = [];
		foreach ($decoded as $control => $floor) {
			if (is_string($control) === true && is_int($floor) === true) {
				$floors[$control] = $floor;
			}
		}

		return $floors;

	}//end declaredFloors()

	/**
	 * Every floor in force, declared or baseline.
	 *
	 * @return array<string, int> Control identifier to floor.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function floors(): array {
		$controls = array_merge(array_keys(self::CONTROLS), array_keys(self::REPORTED_CONTROLS));

		$floors = [];
		foreach ($controls as $control) {
			$floors[$control] = $this->floor(control: $control);
		}

		return $floors;

	}//end floors()

	/**
	 * Whether a control identifier is one this instance knows.
	 *
	 * An unknown identifier is refused rather than stored, because a floor on
	 * a control nothing reports is a floor nothing ever checks.
	 *
	 * @param string $control The control identifier.
	 *
	 * @return bool True when the control is declared.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public static function isKnown(string $control): bool {
		return (isset(self::CONTROLS[$control]) === true || isset(self::REPORTED_CONTROLS[$control]) === true);

	}//end isKnown()
}//end class
