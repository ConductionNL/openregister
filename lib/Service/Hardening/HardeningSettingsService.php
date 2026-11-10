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
use OCA\OpenRegister\Db\AuditTrail\HardeningChange as HardeningAuditChange;
use Throwable;

/**
 * Applies a change to the administered controls and to the declared floors.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 */
class HardeningSettingsService implements HardeningSettingsServiceInterface {

	/**
	 * Constructor.
	 *
	 * @param HardeningFloorGuard $guard   Refuses a change that would weaken a control.
	 * @param HardeningPosture    $posture What is in force now, the stored settings, their audit trail and the log.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly HardeningFloorGuard $guard,
		private readonly HardeningPosture $posture,
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

		$change = new HardeningChange(control: $control, before: $this->posture->policy->administered(control: $control), after: $value);

		try {
			$this->guard->assertValue(control: $control, proposed: $value);
		} catch (HardeningFloorException $refusal) {
			$this->record(change: $change, refusal: $refusal->getMessage());
			throw $refusal;
		}

		[$key] = HardeningPolicy::CONTROLS[$control];
		$this->posture->store->appConfig->setValueInt(HardeningPolicy::APP_ID, $key, $value);
		$this->record(change: $change);

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

		$change = new HardeningChange(control: 'origins.allowlistEntries', before: $this->posture->policy->allowedOrigins(), after: $cleaned);

		try {
			$this->guard->assertValue(control: 'origins.allowlistEntries', proposed: count($cleaned));
		} catch (HardeningFloorException $refusal) {
			$this->record(change: $change, refusal: $refusal->getMessage());
			throw $refusal;
		}

		$this->posture->store->appConfig->setValueString(HardeningPolicy::APP_ID, HardeningPolicy::ORIGINS_KEY, implode(',', $cleaned));
		$this->record(change: $change);

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

		$change = new HardeningChange(control: ($control . '.floor'), before: $this->posture->policy->floor(control: $control), after: $floor);

		try {
			$this->guard->assertFloor(control: $control, proposed: $floor);
		} catch (HardeningFloorException $refusal) {
			$this->record(change: $change, refusal: $refusal->getMessage());
			throw $refusal;
		}

		$declared = $this->posture->policy->declaredFloors();
		$declared[$control] = $floor;
		$this->posture->store->appConfig->setValueString(
			HardeningPolicy::APP_ID,
			HardeningPolicy::FLOORS_KEY,
			(string)json_encode($declared)
		);
		$this->record(change: $change);

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
	 * @param HardeningChange $change  The control and its value before and asked for.
	 * @param string|null     $refusal The refusal, or null when the change was made.
	 *
	 * @return void
	 */
	private function record(HardeningChange $change, ?string $refusal = null): void {
		try {
			$this->posture->store->auditTrailMapper->recordHardeningChange(
				change: new HardeningAuditChange(control: $change->control, before: $change->before, after: $change->after, refusal: ($refusal ?? '')),
				accepted: $refusal === null
			);
		} catch (Throwable $failure) {
			$this->posture->store->logger->error(
				'HardeningSettingsService: the audit entry for ' . $change->control . ' could not be written: ' . $failure->getMessage()
			);
		}

	}//end record()
}//end class
