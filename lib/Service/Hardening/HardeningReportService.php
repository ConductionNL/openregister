<?php

/**
 * The hardening report: what is on, what is not, and what is below the line
 * this instance drew for itself.
 *
 * WHY THIS EXISTS. A chief information security officer asks four questions
 * before an instance goes live: how long may a session last, how many times may
 * somebody guess, who may read this from another website, and how large a file
 * may somebody push in. Today each answer lives somewhere else. Two live in
 * Nextcloud's configuration, one lives in a PHP directive, and one lives in a
 * constant in this repository. The report puts all four on one page, with the
 * floor beside each one, so the answer to "are we compliant?" is a list of
 * failing control ids rather than an afternoon.
 *
 * 🔴 EVERY NUMBER IS READ FROM THE THING THAT ENFORCES IT. The authentication
 * ceiling comes from {@see HardeningPolicy}, which is the same object
 * {@see \OCA\OpenRegister\Service\SecurityService} locks people out with. The
 * password policy comes from Nextcloud's own app. The upload ceiling comes from
 * the running PHP configuration. A report assembled from its own copies is the
 * failure this class exists to prevent: it is read once, believed, and never
 * checked again.
 *
 * 🔑 A CONTROL THAT CANNOT BE READ FAILS ITS FLOOR (ADR-005). `unknown` is
 * never `fine`.
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

use DateTimeImmutable;
use OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesService;
use OCA\OpenRegister\Service\SecurityService;

/**
 * Builds the hardening report.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 */
class HardeningReportService {

	/**
	 * Constructor.
	 *
	 * @param HardeningPolicy $policy The administered controls and the declared floors.
	 * @param PlatformSecurityReader $platform Nextcloud's own password, session and brute-force posture.
	 * @param ApiCapabilitiesService $capabilities Reads the upload ceiling the upload actually runs under.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly HardeningPolicy $policy,
		private readonly PlatformSecurityReader $platform,
		private readonly ApiCapabilitiesService $capabilities,
	) {

	}//end __construct()

	/**
	 * The report, ready to serve.
	 *
	 * @return array<string, mixed> The report.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function report(): array {
		$controls = $this->controls();

		$failing = [];
		foreach ($controls as $control) {
			if ($control->meetsFloor() === false) {
				$failing[] = $control->id;
			}
		}

		$origins = $this->policy->allowedOrigins();

		return [
			'generated' => (new DateTimeImmutable())->format(DATE_ATOM),
			'meetsAllFloors' => ($failing === []),
			'failing' => $failing,
			'controls' => array_map(
				static fn (HardeningControl $control): array => $control->jsonSerialize(),
				$controls
			),
			'observed' => [
				'bruteForce' => $this->platform->bruteForceState(),
				'throttledSurfaces' => ThrottledSurfaces::ALL,
				'allowedOrigins' => $origins,
				'reflectsAnyOrigin' => ($origins === []),
				'passwordPolicyRuns' => $this->platform->passwordPolicyRuns(),
			],
		];

	}//end report()

	/**
	 * Every control, with the value in force and the floor beside it.
	 *
	 * @return array<int, HardeningControl> The rows.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function controls(): array {
		$password = $this->platform->passwordPolicy();
		$session = $this->platform->sessionPolicy();
		$bruteForce = $this->platform->bruteForceState();
		$authLimit = $this->policy->authRateLimit();

		$unreadable = 'Nextcloud owns this control. It reads as unknown when the policy app is not running, and an unknown control fails its floor.';

		return [
			$this->row(
				id: 'password.minimumLength',
				title: 'A password is at least this many characters',
				category: 'password',
				source: 'platform',
				value: $password['minimumLength'],
				unit: 'characters',
				note: $unreadable,
			),
			$this->row(
				id: 'password.blocksCommonPasswords',
				title: 'A password from the common list is refused',
				category: 'password',
				source: 'platform',
				value: $password['blocksCommonPasswords'],
				unit: 'switch',
			),
			$this->row(
				id: 'password.checksBreachDatabase',
				title: 'A password found in a known breach is refused',
				category: 'password',
				source: 'platform',
				value: $password['checksBreachDatabase'],
				unit: 'switch',
			),
			$this->row(
				id: 'session.lifetimeSeconds',
				title: 'A session lasts no longer than this',
				category: 'session',
				source: 'platform',
				value: $session['lifetimeSeconds'],
				unit: 'seconds',
			),
			$this->row(
				id: 'session.rememberLoginSeconds',
				title: 'A remembered login lasts no longer than this',
				category: 'session',
				source: 'platform',
				value: $session['rememberLoginSeconds'],
				unit: 'seconds',
			),
			$this->row(
				id: 'session.secondFactorEnforced',
				title: 'Everybody signs in with a second factor',
				category: 'session',
				source: 'platform',
				value: $session['secondFactorEnforced'],
				unit: 'switch',
				note: 'Nextcloud enforces the factor instance-wide. Declaring it per register is REQ-IHC-003 and is not built yet.',
			),
			$this->row(
				id: 'auth.rateLimit.attemptsPerIdentity',
				title: 'One identity may fail this many times before it is locked out',
				category: 'rateLimit',
				source: 'administered',
				value: $authLimit['attemptsPerIdentity'],
				unit: 'attempts',
			),
			$this->row(
				id: 'auth.rateLimit.attemptsPerAddress',
				title: 'One address may fail this many times before it is locked out',
				category: 'rateLimit',
				source: 'administered',
				value: $authLimit['attemptsPerAddress'],
				unit: 'attempts',
			),
			$this->row(
				id: 'auth.rateLimit.windowSeconds',
				title: 'Failures are counted over this window',
				category: 'rateLimit',
				source: 'administered',
				value: $authLimit['windowSeconds'],
				unit: 'seconds',
			),
			$this->row(
				id: 'auth.rateLimit.lockoutSeconds',
				title: 'A lockout lasts this long',
				category: 'rateLimit',
				source: 'administered',
				value: $authLimit['lockoutSeconds'],
				unit: 'seconds',
			),
			$this->row(
				id: 'login.rateLimit.attempts',
				title: 'A sign-in may fail this many times before it is locked out',
				category: 'rateLimit',
				source: 'code',
				value: SecurityService::LOGIN_RATE_LIMIT_ATTEMPTS,
				unit: 'attempts',
			),
			$this->row(
				id: 'login.rateLimit.windowSeconds',
				title: 'Sign-in failures are counted over this window',
				category: 'rateLimit',
				source: 'code',
				value: SecurityService::LOGIN_RATE_LIMIT_WINDOW,
				unit: 'seconds',
			),
			$this->row(
				id: 'login.lockoutSeconds',
				title: 'A sign-in lockout lasts this long',
				category: 'rateLimit',
				source: 'code',
				value: SecurityService::LOGIN_LOCKOUT_DURATION,
				unit: 'seconds',
			),
			$this->row(
				id: 'bruteForce.platformThrottlerEnabled',
				title: 'Nextcloud delays a client that keeps guessing',
				category: 'bruteForce',
				source: 'platform',
				value: $bruteForce['throttlerEnabled'],
				unit: 'switch',
			),
			$this->row(
				id: 'bruteForce.throttledSurfaces',
				title: 'Anonymous surfaces that register a failed attempt',
				category: 'bruteForce',
				source: 'code',
				value: ThrottledSurfaces::count(),
				unit: 'surfaces',
			),
			$this->row(
				id: 'origins.allowlistEntries',
				title: 'Websites that may read a public answer from a browser',
				category: 'origins',
				source: 'administered',
				value: count($this->policy->allowedOrigins()),
				unit: 'entries',
				note: 'An empty allowlist reflects whichever origin asks, which is how this instance '
					. 'behaved before the control existed. Declare a floor of 1 to require a list.',
			),
			$this->row(
				id: 'upload.ceilingBytes',
				title: 'The largest file somebody may push in',
				category: 'upload',
				source: 'platform',
				value: $this->capabilities->uploadCeilingBytes(),
				unit: 'bytes',
				note: 'The smaller of upload_max_filesize and post_max_size, read from the running PHP configuration. Raise the floor, or lower the directive.',
			),
		];

	}//end controls()

	/**
	 * Build one row, taking the floor and the comparator from the policy.
	 *
	 * @param string $id The control identifier.
	 * @param string $title What the control does.
	 * @param string $category The group it is reported under.
	 * @param string $source Who enforces it.
	 * @param int|null $value The value in force.
	 * @param string $unit What the value counts.
	 * @param string $note Why the value reads as it does.
	 *
	 * @return HardeningControl The row.
	 */
	private function row(
		string $id,
		string $title,
		string $category,
		string $source,
		?int $value,
		string $unit,
		string $note = '',
	): HardeningControl {
		return new HardeningControl(
			id: $id,
			title: $title,
			category: $category,
			source: $source,
			value: $value,
			floor: $this->policy->floor(control: $id),
			comparator: HardeningPolicy::comparator(control: $id),
			unit: $unit,
			note: $note,
		);

	}//end row()
}//end class
