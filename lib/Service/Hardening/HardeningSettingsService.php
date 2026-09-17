<?php

/**
 * The write path for the controls an administrator may change, and the floors
 * they declared.
 *
 * Every write goes through the guard first and the audit trail afterwards, in
 * that order, whichever way the guard decides. A refused weakening is recorded
 * too: "somebody tried to take the lockout down to sixty seconds on Friday
 * afternoon" is the row a security officer most wants and the one a
 * success-only trail cannot hold.
 *
 * 🔴 AN UNKNOWN CONTROL IS REFUSED, NEVER STORED. Storing a floor for a control
 * nothing reports gives an administrator a floor that is never checked, and it
 * reads on the settings page exactly like one that is.
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

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies a change to the administered controls and to the declared floors.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 */
class HardeningSettingsService {

	/**
	 * Constructor.
	 *
	 * @param HardeningPolicy $policy Reads what is in force now.
	 * @param HardeningFloorGuard $guard Refuses a change that would weaken a control.
	 * @param IAppConfig $appConfig Stores the administered values and the floors.
	 * @param AuditTrailMapper $auditTrailMapper Records the change, and the refusal.
	 * @param LoggerInterface $logger Records an audit write that could not be made.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly HardeningPolicy $policy,
		private readonly HardeningFloorGuard $guard,
		private readonly IAppConfig $appConfig,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Change one administered control.
	 *
	 * @param string $control The control identifier.
	 * @param int $value The value asked for.
	 *
	 * @return int The value now in force.
	 *
	 * @throws InvalidArgumentException When the control is not one this instance administers.
	 * @throws HardeningFloorException When the value would weaken the control below its floor.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function setControl(string $control, int $value): int {
		if (isset(HardeningPolicy::CONTROLS[$control]) === false || $control === 'origins.allowlistEntries') {
			throw new InvalidArgumentException('This instance does not administer ' . $control . '.');
		}

		$before = $this->policy->administered(control: $control);

		try {
			$this->guard->assertValue(control: $control, proposed: $value);
		} catch (HardeningFloorException $refusal) {
			$this->record(control: $control, before: $before, after: $value, accepted: false, refusal: $refusal->getMessage());
			throw $refusal;
		}

		[$key] = HardeningPolicy::CONTROLS[$control];
		$this->appConfig->setValueInt(HardeningPolicy::APP_ID, $key, $value);
		$this->record(control: $control, before: $before, after: $value, accepted: true);

		return $value;

	}//end setControl()

	/**
	 * Replace the origin allowlist.
	 *
	 * @param array<int, string> $origins The origins a browser may read a public answer from.
	 *
	 * @return array<int, string> The allowlist now in force.
	 *
	 * @throws InvalidArgumentException When an entry is not a bare scheme, host and port.
	 * @throws HardeningFloorException When emptying the list would cross the declared floor.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function setAllowedOrigins(array $origins): array {
		$cleaned = [];
		foreach ($origins as $candidate) {
			if (is_string($candidate) === false) {
				throw new InvalidArgumentException('An origin must be written out as text.');
			}

			$origin = self::normaliseOrigin(origin: $candidate);
			if ($origin !== '' && in_array($origin, $cleaned, true) === false) {
				$cleaned[] = $origin;
			}
		}

		$before = $this->policy->allowedOrigins();

		try {
			$this->guard->assertValue(control: 'origins.allowlistEntries', proposed: count($cleaned));
		} catch (HardeningFloorException $refusal) {
			$this->record(
				control: 'origins.allowlistEntries',
				before: $before,
				after: $cleaned,
				accepted: false,
				refusal: $refusal->getMessage(),
			);
			throw $refusal;
		}

		$this->appConfig->setValueString(HardeningPolicy::APP_ID, HardeningPolicy::ORIGINS_KEY, implode(',', $cleaned));
		$this->record(control: 'origins.allowlistEntries', before: $before, after: $cleaned, accepted: true);

		return $cleaned;

	}//end setAllowedOrigins()

	/**
	 * Declare a floor for one control.
	 *
	 * @param string $control The control identifier.
	 * @param int $floor The floor asked for.
	 *
	 * @return int The floor now in force.
	 *
	 * @throws InvalidArgumentException When the control is not one this instance reports.
	 * @throws HardeningFloorException When the floor would be weaker than the shipped baseline.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The catalogue is a constant and its readers are
	 * pure functions over it. Injecting a stateless lookup would add a constructor argument
	 * to every caller and change nothing about what the lookup can answer.
	 */
	public function setFloor(string $control, int $floor): int {
		if (HardeningPolicy::isKnown(control: $control) === false) {
			throw new InvalidArgumentException('This instance does not report ' . $control . '.');
		}

		$before = $this->policy->floor(control: $control);

		try {
			$this->guard->assertFloor(control: $control, proposed: $floor);
		} catch (HardeningFloorException $refusal) {
			$this->record(
				control: ($control . '.floor'),
				before: $before,
				after: $floor,
				accepted: false,
				refusal: $refusal->getMessage(),
			);
			throw $refusal;
		}

		$declared = $this->policy->declaredFloors();
		$declared[$control] = $floor;
		$this->appConfig->setValueString(
			HardeningPolicy::APP_ID,
			HardeningPolicy::FLOORS_KEY,
			(string)json_encode($declared)
		);
		$this->record(control: ($control . '.floor'), before: $before, after: $floor, accepted: true);

		return $floor;

	}//end setFloor()

	/**
	 * Reduce an origin to its scheme, host and port.
	 *
	 * A path, a query or a fragment is a sign the administrator pasted a page
	 * rather than an origin, and a browser never sends one in `Origin`. Refusing
	 * it is kinder than storing a list entry that can never match.
	 *
	 * @param string $origin The entry as it was written.
	 *
	 * @return string The normalised origin, or an empty string when the entry was blank.
	 *
	 * @throws InvalidArgumentException When the entry is not a bare origin.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public static function normaliseOrigin(string $origin): string {
		$trimmed = strtolower(trim($origin));
		if ($trimmed === '') {
			return '';
		}

		$parts = parse_url($trimmed);
		if ($parts === false || isset($parts['scheme']) === false || isset($parts['host']) === false) {
			throw new InvalidArgumentException('An origin reads as https://example.nl, with no path.');
		}

		if (in_array($parts['scheme'], ['http', 'https'], true) === false) {
			throw new InvalidArgumentException('An origin is http or https.');
		}

		$extras = array_intersect_key($parts, array_flip(['path', 'query', 'fragment', 'user', 'pass']));
		if (array_filter($extras, static fn ($value): bool => ($value !== '' && $value !== null)) !== []) {
			throw new InvalidArgumentException('An origin carries no path, query or credentials.');
		}

		$normalised = ($parts['scheme'] . '://' . $parts['host']);
		if (isset($parts['port']) === true) {
			$normalised .= (':' . $parts['port']);
		}

		return $normalised;

	}//end normaliseOrigin()

	/**
	 * Write one audit row, whichever way the guard decided.
	 *
	 * Fails open on the write itself: a broken audit chain must not turn a
	 * refusal into a 500 that an administrator reads as "the control changed".
	 * The refusal has already happened by the time this runs.
	 *
	 * @param string $control The control identifier.
	 * @param int|string|array $before The value before.
	 * @param int|string|array $after The value asked for.
	 * @param bool $accepted Whether the change was made.
	 * @param string $refusal The refusal, when there was one.
	 *
	 * @return void
	 */
	private function record(string $control, int|string|array $before, int|string|array $after, bool $accepted, string $refusal = ''): void {
		try {
			$this->auditTrailMapper->createHardeningChangeEntry(
				control: $control,
				before: $before,
				after: $after,
				accepted: $accepted,
				refusal: $refusal,
			);
		} catch (Throwable $failure) {
			$this->logger->error(
				'HardeningSettingsService: the audit entry for ' . $control . ' could not be written: ' . $failure->getMessage()
			);
		}

	}//end record()
}//end class
