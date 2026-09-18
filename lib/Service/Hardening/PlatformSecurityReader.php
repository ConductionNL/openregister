<?php

/**
 * What Nextcloud already enforces, read from Nextcloud.
 *
 * 🔴 THERE IS NO SECOND PASSWORD POLICY HERE, AND THERE MUST NEVER BE ONE.
 * Nextcloud's `password_policy` app owns the minimum length, the common-password
 * refusal and the breach-database check, and Nextcloud's session configuration
 * owns the session lifetime and the remembered login. An application that keeps
 * its own copy gives a gemeente two answers to one question, and the copy is
 * the one that is wrong: it does not run when somebody changes their password.
 * This class reads the platform's numbers and reports them. It sets nothing.
 *
 * WHAT AN UNREADABLE CONTROL MEANS. `password_policy` is an app, and an app can
 * be disabled. When it is, there is no minimum length, so the value reported is
 * null, the report says `unknown`, and the floor fails (ADR-005). Reporting a
 * shipped default for a policy that is not running is the failure mode that
 * makes a hardening report dangerous: it would say "10 characters" on an
 * instance that accepts `a`.
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

use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Throwable;

/**
 * Reads the platform's own security posture.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Reading the platform's posture means
 * touching the platform's configuration, app manager, request and throttler. Splitting
 * this would scatter one question across four classes.
 */
class PlatformSecurityReader {

	/**
	 * The Nextcloud app that owns the password policy.
	 *
	 * @var string
	 */
	private const POLICY_APP = 'password_policy';

	/**
	 * Constructor.
	 *
	 * @param IConfig $config Reads the session and brute-force system configuration.
	 * @param IAppConfig $appConfig Reads the password policy app's own values.
	 * @param IAppManager $appManager Answers whether the password policy is running.
	 * @param IThrottler $throttler Reports the brute-force state for the calling address.
	 * @param IRequest $request Names the address the state is reported for.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
		private readonly IThrottler $throttler,
		private readonly IRequest $request,
	) {

	}//end __construct()

	/**
	 * Whether the password policy app is running on this instance.
	 *
	 * @return bool True when the policy applies.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function passwordPolicyRuns(): bool {
		try {
			return $this->appManager->isEnabledForAnyone(self::POLICY_APP);
		} catch (Throwable) {
			return false;
		}

	}//end passwordPolicyRuns()

	/**
	 * The password policy Nextcloud enforces.
	 *
	 * Every value is null when the policy app is not running, because there is
	 * then no policy to report.
	 *
	 * @return array{minimumLength: int|null, blocksCommonPasswords: int|null, checksBreachDatabase: int|null} The policy.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function passwordPolicy(): array {
		if ($this->passwordPolicyRuns() === false) {
			return [
				'minimumLength' => null,
				'blocksCommonPasswords' => null,
				'checksBreachDatabase' => null,
			];
		}

		return [
			'minimumLength' => $this->policyInt(key: 'minLength', default: 10),
			'blocksCommonPasswords' => $this->policyFlag(key: 'enforceNonCommonPassword'),
			'checksBreachDatabase' => $this->policyFlag(key: 'enforceHaveIBeenPwned'),
		];

	}//end passwordPolicy()

	/**
	 * The session policy Nextcloud enforces.
	 *
	 * @return array{lifetimeSeconds: int|null, rememberLoginSeconds: int|null, secondFactorEnforced: int|null} The policy.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function sessionPolicy(): array {
		return [
			'lifetimeSeconds' => $this->systemInt(key: 'session_lifetime', default: 86400),
			'rememberLoginSeconds' => $this->systemInt(key: 'remember_login_cookie_lifetime', default: 1296000),
			'secondFactorEnforced' => $this->systemFlag(key: 'twofactor_enforced'),
		];

	}//end sessionPolicy()

	/**
	 * The brute-force state, as the platform holds it right now.
	 *
	 * The delay and the attempt count are for the address the request came
	 * from, which is the address an administrator asking "am I locked out?"
	 * means. Both read as zero when the throttler cannot answer, and the
	 * `throttlerEnabled` flag is what the floor is judged on.
	 *
	 * @return array{throttlerEnabled: int|null, delayMilliseconds: int, attempts: int, bypassListed: bool} The state.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function bruteForceState(): array {
		$enabled = null;
		try {
			$raw = $this->config->getSystemValue('auth.bruteforce.protection.enabled', true);
			$enabled = 0;
			if ($raw === true || $raw === 'true' || $raw === 1 || $raw === '1') {
				$enabled = 1;
			}
		} catch (Throwable) {
			$enabled = null;
		}

		$delay = 0;
		$attempts = 0;
		$bypass = false;
		try {
			$address = $this->request->getRemoteAddress();
			if ($address !== '') {
				$delay = $this->throttler->getDelay($address);
				$attempts = $this->throttler->getAttempts($address);
				$bypass = $this->throttler->isBypassListed($address);
			}
		} catch (Throwable) {
			$delay = 0;
			$attempts = 0;
			$bypass = false;
		}

		return [
			'throttlerEnabled' => $enabled,
			'delayMilliseconds' => $delay,
			'attempts' => $attempts,
			'bypassListed' => $bypass,
		];

	}//end bruteForceState()

	/**
	 * Read one integer from the password policy app.
	 *
	 * @param string $key The policy key.
	 * @param int $default The value to fall back to.
	 *
	 * @return int|null The value, or null when it cannot be read.
	 */
	private function policyInt(string $key, int $default): ?int {
		try {
			return $this->appConfig->getValueInt(self::POLICY_APP, $key, $default);
		} catch (Throwable) {
			return null;
		}

	}//end policyInt()

	/**
	 * Read one policy switch as a 1 or a 0.
	 *
	 * @param string $key The policy key.
	 *
	 * @return int|null 1 when on, 0 when off, null when it cannot be read.
	 */
	private function policyFlag(string $key): ?int {
		try {
			if ($this->appConfig->getValueBool(self::POLICY_APP, $key, true) === true) {
				return 1;
			}

			return 0;
		} catch (Throwable) {
			return null;
		}

	}//end policyFlag()

	/**
	 * Read one integer from the system configuration.
	 *
	 * @param string $key The system key.
	 * @param int $default The value to fall back to.
	 *
	 * @return int|null The value, or null when it cannot be read.
	 */
	private function systemInt(string $key, int $default): ?int {
		try {
			return (int)$this->config->getSystemValue($key, $default);
		} catch (Throwable) {
			return null;
		}

	}//end systemInt()

	/**
	 * Read one system switch as a 1 or a 0.
	 *
	 * Nextcloud stores `twofactor_enforced` as the string `'true'` or
	 * `'false'`, not as a boolean, which is exactly the kind of detail a
	 * separately-maintained copy of this reader would get wrong.
	 *
	 * @param string $key The system key.
	 *
	 * @return int|null 1 when on, 0 when off, null when it cannot be read.
	 */
	private function systemFlag(string $key): ?int {
		try {
			$raw = $this->config->getSystemValue($key, 'false');
			if ($raw === true || $raw === 'true' || $raw === 1 || $raw === '1') {
				return 1;
			}

			return 0;
		} catch (Throwable) {
			return null;
		}

	}//end systemFlag()
}//end class
