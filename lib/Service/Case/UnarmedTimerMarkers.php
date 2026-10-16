<?php

/**
 * The plan items whose statutory expiry timer could not be armed.
 *
 * The arm of a timer runs from an event listener, after the transition that
 * made the item active has committed, and Nextcloud's dispatcher logs and
 * swallows what a listener throws, so a refused statutory arm cannot fail that
 * transition. What it can do is leave a durable marker, which
 * {@see CasePlanTimerRearm} reads on a schedule and arms again (or enforces,
 * when the term has passed meanwhile). The marker lives in app config, so no
 * table of the case layer changes; it is a map of item uuid to the moment the
 * arm failed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\AppInfo\Application;
use OCP\IAppConfig;
use Throwable;

/**
 * Remembers which plan items still lack their statutory expiry timer.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
 */
class UnarmedTimerMarkers {

	/**
	 * The app-config key of the marker map (a lazy value: it is read by one job, not on a request).
	 */
	public const KEY = 'case_unarmed_statutory_timers';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig OpenRegister's app config.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * Mark an item: its statutory expiry timer could not be armed.
	 *
	 * The first marker of an item keeps its moment, so how long an item has
	 * been without its timer stays readable.
	 *
	 * @param string $itemUuid The plan item.
	 * @param DateTimeInterface|null $now The clock; null is the real clock.
	 *
	 * @return bool True when the marker is stored.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	public function add(string $itemUuid, ?DateTimeInterface $now = null): bool {
		$markers = $this->all();
		if (isset($markers[$itemUuid]) === true) {
			return true;
		}

		$moment = DateTimeImmutable::createFromInterface($now ?? new DateTimeImmutable());
		$markers[$itemUuid] = $moment->format(DATE_ATOM);

		return $this->store(markers: $markers);
	}//end add()

	/**
	 * Clear an item's marker.
	 *
	 * @param string $itemUuid The plan item.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	public function remove(string $itemUuid): void {
		$markers = $this->all();
		if (isset($markers[$itemUuid]) === false) {
			return;
		}

		unset($markers[$itemUuid]);
		$this->store(markers: $markers);
	}//end remove()

	/**
	 * Every marked item, with the moment its arm first failed.
	 *
	 * @return array<string, string> Item uuid to an ISO 8601 moment.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	public function all(): array {
		try {
			$decoded = json_decode($this->appConfig->getValueString(Application::APP_ID, self::KEY, '', true), true);
		} catch (Throwable) {
			return [];
		}

		if (is_array($decoded) === false) {
			return [];
		}

		$markers = [];
		foreach ($decoded as $uuid => $moment) {
			if (is_string($uuid) === true && $uuid !== '' && is_string($moment) === true) {
				$markers[$uuid] = $moment;
			}
		}

		return $markers;
	}//end all()

	/**
	 * Store the map, or drop the key when it is empty.
	 *
	 * @param array<string, string> $markers The map.
	 *
	 * @return bool True when stored.
	 */
	private function store(array $markers): bool {
		try {
			if ($markers === []) {
				$this->appConfig->deleteKey(Application::APP_ID, self::KEY);

				return true;
			}

			return $this->appConfig->setValueString(Application::APP_ID, self::KEY, (string)json_encode($markers), true);
		} catch (Throwable) {
			return false;
		}
	}//end store()
}//end class
