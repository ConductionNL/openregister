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
class HardeningReportService implements HardeningReportServiceInterface {

	/**
	 * What every control reports beside its value: id => [title, category, source, unit, note].
	 *
	 * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
	 */
	private const CONTROLS = [
		'password.minimumLength' => [
			'A password is at least this many characters',
			'password',
			'platform',
			'characters',
			'Nextcloud owns this control. It reads as unknown when the policy app '
				. 'is not running, and an unknown control fails its floor.',
		],
		'password.blocksCommonPasswords' => ['A password from the common list is refused', 'password', 'platform', 'switch', ''],
		'password.checksBreachDatabase' => ['A password found in a known breach is refused', 'password', 'platform', 'switch', ''],
		'session.lifetimeSeconds' => ['A session lasts no longer than this', 'session', 'platform', 'seconds', ''],
		'session.rememberLoginSeconds' => ['A remembered login lasts no longer than this', 'session', 'platform', 'seconds', ''],
		'session.secondFactorEnforced' => [
			'Everybody signs in with a second factor',
			'session',
			'platform',
			'switch',
			'Nextcloud enforces the factor instance-wide. Declaring it per register is REQ-IHC-003 and is not built yet.',
		],
		'auth.rateLimit.attemptsPerIdentity' => [
			'One identity may fail this many times before it is locked out',
			'rateLimit',
			'administered',
			'attempts',
			'',
		],
		'auth.rateLimit.attemptsPerAddress' => [
			'One address may fail this many times before it is locked out',
			'rateLimit',
			'administered',
			'attempts',
			'',
		],
		'auth.rateLimit.windowSeconds' => ['Failures are counted over this window', 'rateLimit', 'administered', 'seconds', ''],
		'auth.rateLimit.lockoutSeconds' => ['A lockout lasts this long', 'rateLimit', 'administered', 'seconds', ''],
		'login.rateLimit.attempts' => ['A sign-in may fail this many times before it is locked out', 'rateLimit', 'code', 'attempts', ''],
		'login.rateLimit.windowSeconds' => ['Sign-in failures are counted over this window', 'rateLimit', 'code', 'seconds', ''],
		'login.lockoutSeconds' => ['A sign-in lockout lasts this long', 'rateLimit', 'code', 'seconds', ''],
		'bruteForce.platformThrottlerEnabled' => ['Nextcloud delays a client that keeps guessing', 'bruteForce', 'platform', 'switch', ''],
		'bruteForce.throttledSurfaces' => ['Anonymous surfaces that register a failed attempt', 'bruteForce', 'code', 'surfaces', ''],
		'origins.allowlistEntries' => [
			'Websites that may read a public answer from a browser',
			'origins',
			'administered',
			'entries',
			'An empty allowlist reflects whichever origin asks, which is how this instance '
				. 'behaved before the control existed. Declare a floor of 1 to require a list.',
		],
		'upload.ceilingBytes' => [
			'The largest file somebody may push in',
			'upload',
			'platform',
			'bytes',
			'The smaller of upload_max_filesize and post_max_size, read from the running PHP configuration. Raise the floor, or lower the directive.',
		],
	];

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
		return array_merge(
			$this->passwordRows(),
			$this->sessionRows(),
			$this->rateLimitRows(),
			$this->bruteForceRows(),
			$this->originRows(),
			$this->uploadRows(),
		);

	}//end controls()

	/**
	 * Nextcloud's password policy, as the policy app enforces it.
	 *
	 * @return array<int, HardeningControl> The rows.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	private function passwordRows(): array {
		$password = $this->platform->passwordPolicy();

		return $this->rows(
			values: [
				'password.minimumLength' => $password['minimumLength'],
				'password.blocksCommonPasswords' => $password['blocksCommonPasswords'],
				'password.checksBreachDatabase' => $password['checksBreachDatabase'],
			]
		);

	}//end passwordRows()

	/**
	 * Nextcloud's session policy, as the system configuration sets it.
	 *
	 * @return array<int, HardeningControl> The rows.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	private function sessionRows(): array {
		$session = $this->platform->sessionPolicy();

		return $this->rows(
			values: [
				'session.lifetimeSeconds' => $session['lifetimeSeconds'],
				'session.rememberLoginSeconds' => $session['rememberLoginSeconds'],
				'session.secondFactorEnforced' => $session['secondFactorEnforced'],
			]
		);

	}//end sessionRows()

	/**
	 * The inbound and interactive ceilings, read from what applies them.
	 *
	 * @return array<int, HardeningControl> The rows.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	private function rateLimitRows(): array {
		$authLimit = $this->policy->authRateLimit();

		return $this->rows(
			values: [
				'auth.rateLimit.attemptsPerIdentity' => $authLimit['attemptsPerIdentity'],
				'auth.rateLimit.attemptsPerAddress' => $authLimit['attemptsPerAddress'],
				'auth.rateLimit.windowSeconds' => $authLimit['windowSeconds'],
				'auth.rateLimit.lockoutSeconds' => $authLimit['lockoutSeconds'],
				'login.rateLimit.attempts' => SecurityService::LOGIN_RATE_LIMIT_ATTEMPTS,
				'login.rateLimit.windowSeconds' => SecurityService::LOGIN_RATE_LIMIT_WINDOW,
				'login.lockoutSeconds' => SecurityService::LOGIN_LOCKOUT_DURATION,
			]
		);

	}//end rateLimitRows()

	/**
	 * The platform throttler, and the surfaces that feed it.
	 *
	 * @return array<int, HardeningControl> The rows.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The catalogue is a constant and its readers are
	 * pure functions over it. Injecting a stateless lookup would add a constructor argument
	 * to every caller and change nothing about what the lookup can answer.
	 */
	private function bruteForceRows(): array {
		$bruteForce = $this->platform->bruteForceState();

		return $this->rows(
			values: [
				'bruteForce.platformThrottlerEnabled' => $bruteForce['throttlerEnabled'],
				'bruteForce.throttledSurfaces' => ThrottledSurfaces::count(),
			]
		);

	}//end bruteForceRows()

	/**
	 * Who may read a public answer from a browser on another website.
	 *
	 * @return array<int, HardeningControl> The rows.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	private function originRows(): array {
		return $this->rows(values: ['origins.allowlistEntries' => count($this->policy->allowedOrigins())]);

	}//end originRows()

	/**
	 * The largest file the running configuration accepts.
	 *
	 * @return array<int, HardeningControl> The rows.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	private function uploadRows(): array {
		return $this->rows(values: ['upload.ceilingBytes' => $this->capabilities->uploadCeilingBytes()]);

	}//end uploadRows()

	/**
	 * Build the rows for the given values, in their order.
	 *
	 * @param array<string, int|null> $values The value in force per control id in CONTROLS.
	 *
	 * @return array<int, HardeningControl> The rows.
	 */
	private function rows(array $values): array {
		$rows = [];
		foreach ($values as $id => $value) {
			$rows[] = $this->control(id: $id, value: $value);
		}

		return $rows;

	}//end rows()

	/**
	 * Build one row from CONTROLS, taking the floor and the comparator from the policy.
	 *
	 * @param string   $id    The control identifier.
	 * @param int|null $value The value in force.
	 *
	 * @return HardeningControl The row.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The catalogue is a constant and its readers are
	 * pure functions over it. Injecting a stateless lookup would add a constructor argument
	 * to every caller and change nothing about what the lookup can answer.
	 */
	private function control(string $id, ?int $value): HardeningControl {
		[$title, $category, $source, $unit, $note] = self::CONTROLS[$id];
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

	}//end control()
}//end class
