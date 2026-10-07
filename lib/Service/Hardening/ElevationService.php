<?php

/**
 * The fresh sign-in an administration write asks for, and how long it lasts.
 *
 * WHY A SECOND AUTHENTICATION AT ALL (design D-2). A Nextcloud session lives
 * for a day by default, and an administrator leaves it open. Everything in the
 * hardening surface is a control somebody could weaken from that open session:
 * a borrowed laptop, a tab left on a shared machine, a stolen session cookie.
 * Asking for the password again is the one check that a stolen SESSION does not
 * satisfy, because the attacker holds the session and not the secret.
 *
 * 🔑 THE PERIOD IS ADMINISTERED, AND IT IS A CONTROL LIKE THE OTHERS. It is
 * declared in `HardeningPolicy::CONTROLS` as `admin.elevationSeconds` with an
 * `atMost` floor, so lengthening it past the floor is refused by the same guard
 * that refuses a weakened rate limit. A longer window is a weaker instance, and
 * that is why the comparator is `atMost` rather than `atLeast`.
 *
 * 🔴 IT FAILS CLOSED, INCLUDING ON A CLOCK IT CANNOT READ. No stored moment, an
 * unreadable one, a moment in the future and a moment older than the period all
 * mean the same thing here: not elevated. The alternative reading, "we cannot
 * tell, so let it through", is how a guard becomes decoration.
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
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-administration-requires-a-fresh-expiring-authentication-req-ihc-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hardening;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ISession;
use OCP\IUserManager;
use OCP\IUserSession;
use Throwable;

/**
 * Grants, checks and expires the elevated administration session.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 */
class ElevationService {

	/**
	 * The control that says how long an elevated session lasts.
	 *
	 * @var string
	 */
	public const PERIOD_CONTROL = 'admin.elevationSeconds';

	/**
	 * Where the moment of the fresh sign-in is kept.
	 *
	 * @var string
	 */
	public const SESSION_KEY = 'openregister_hardening_elevated_at';

	/**
	 * Constructor.
	 *
	 * @param ISession             $session     Holds the moment, and dies with the session.
	 * @param IUserSession         $userSession Names the account that is elevating.
	 * @param IUserManager         $users       Confirms the password.
	 * @param ITimeFactory         $time        The clock, so a test can move it.
	 * @param HardeningPolicy      $policy      Reads the administered period.
	 * @param HardeningAuditWriter $audit       Records the grant and the refusal.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ISession $session,
		private readonly IUserSession $userSession,
		private readonly IUserManager $users,
		private readonly ITimeFactory $time,
		private readonly HardeningPolicy $policy,
		private readonly HardeningAuditWriter $audit,
	) {

	}//end __construct()

	/**
	 * How long an elevated session lasts on this instance.
	 *
	 * @return int The period, in seconds.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-administration-requires-a-fresh-expiring-authentication-req-ihc-002
	 */
	public function periodSeconds(): int {
		return $this->policy->administered(control: self::PERIOD_CONTROL);

	}//end periodSeconds()

	/**
	 * Confirm the password of the signed-in account, and start the period.
	 *
	 * The account is taken from the session and never from the request: an
	 * elevation request naming a user id would let anybody elevate anybody by
	 * guessing one password, and the whole point is that the session and the
	 * secret are held by the same person.
	 *
	 * @param string $password The password, as the person typed it.
	 *
	 * @return bool True when the session is now elevated.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-administration-requires-a-fresh-expiring-authentication-req-ihc-002
	 */
	public function elevate(string $password): bool {
		$user = $this->userSession->getUser();
		if ($user === null || $password === '') {
			$this->audit->record(
				fact: 'elevation.refused',
				before: '',
				after: '',
				accepted: false,
				refusal: 'No signed-in account, or no password given.',
			);

			return false;
		}

		$uid = $user->getUID();
		if ($this->users->checkPassword($uid, $password) === false) {
			$this->audit->record(
				fact: 'elevation.refused',
				before: '',
				after: $uid,
				accepted: false,
				refusal: 'The password was not confirmed.',
			);

			return false;
		}

		$now = $this->time->getTime();
		$this->session->set(self::SESSION_KEY, $now);
		$this->audit->record(
			fact: 'elevation.granted',
			before: '',
			after: ['user' => $uid, 'periodSeconds' => $this->periodSeconds()],
			accepted: true,
		);

		return true;

	}//end elevate()

	/**
	 * End the elevated period without ending the session.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-administration-requires-a-fresh-expiring-authentication-req-ihc-002
	 */
	public function drop(): void {
		$this->session->remove(self::SESSION_KEY);

	}//end drop()

	/**
	 * Whether this session may administer right now.
	 *
	 * @return bool True while the period is running.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-administration-requires-a-fresh-expiring-authentication-req-ihc-002
	 */
	public function isElevated(): bool {
		return $this->remainingSeconds() > 0;

	}//end isElevated()

	/**
	 * How much of the elevated period is left, in seconds.
	 *
	 * @return int The seconds left, and zero when the session is not elevated.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-administration-requires-a-fresh-expiring-authentication-req-ihc-002
	 */
	public function remainingSeconds(): int {
		try {
			$since = $this->session->get(self::SESSION_KEY);
		} catch (Throwable) {
			return 0;
		}

		if (is_int($since) === false && is_string($since) === false) {
			return 0;
		}

		$since = (int)$since;
		if ($since <= 0) {
			return 0;
		}

		$elapsed = ($this->time->getTime() - $since);
		if ($elapsed < 0) {
			// A moment in the future is a clock nobody can trust, so it counts
			// as no elevation rather than as an endless one.
			return 0;
		}

		$left = ($this->periodSeconds() - $elapsed);
		if ($left <= 0) {
			return 0;
		}

		return $left;

	}//end remainingSeconds()

	/**
	 * Refuse an administration write unless the period is running.
	 *
	 * @return void
	 *
	 * @throws ElevationRequiredException When the session is not elevated.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-administration-requires-a-fresh-expiring-authentication-req-ihc-002
	 */
	public function requireElevated(): void {
		if ($this->isElevated() === true) {
			return;
		}

		$this->audit->record(
			fact: 'elevation.lapsed',
			before: '',
			after: ($this->userSession->getUser()?->getUID() ?? ''),
			accepted: false,
			refusal: 'The administration write was refused: the elevated period had lapsed.',
		);

		throw new ElevationRequiredException(periodSeconds: $this->periodSeconds());

	}//end requireElevated()
}//end class
