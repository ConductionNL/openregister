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
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCP\IAppConfig;
use Throwable;

/**
 * Remembers which plan items still lack their statutory expiry timer.
 *
 * One app-config key per item (`case_unarmed_<item uuid>`), so two processes that mark or clear different
 * items never rewrite each other's marker: there is no shared map to read, modify and write back.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
 */
class UnarmedTimerMarkers {

	/**
	 * The app-config key prefix of one marker (lazy values: they are read by one job, not on a request).
	 * App-config keys are capped at 64 characters, so the prefix plus a 36 character uuid fits.
	 */
	public const KEY_PREFIX = 'case_unarmed_';

	/**
	 * The longest key an app-config accepts.
	 */
	private const MAX_KEY_LENGTH = 64;

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
		$key = $this->keyFor(itemUuid: $itemUuid);
		if ($key === null) {
			return false;
		}

		try {
			if ($this->appConfig->getValueString(AppIdentity::APP_ID, $key, '', true) !== '') {
				return true;
			}

			$moment = DateTimeImmutable::createFromInterface($now ?? new DateTimeImmutable());

			return $this->appConfig->setValueString(AppIdentity::APP_ID, $key, $moment->format(DATE_ATOM), true);
		} catch (Throwable) {
			return false;
		}
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
		$key = $this->keyFor(itemUuid: $itemUuid);
		if ($key === null) {
			return;
		}

		try {
			$this->appConfig->deleteKey(AppIdentity::APP_ID, $key);
		} catch (Throwable) {
			// A marker that cannot be cleared is looked at again by the next pass.
			return;
		}
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
			$keys = $this->appConfig->getKeys(AppIdentity::APP_ID);
		} catch (Throwable) {
			return [];
		}

		$markers = [];
		foreach ($keys as $key) {
			if (str_starts_with($key, self::KEY_PREFIX) === false || strlen($key) === strlen(self::KEY_PREFIX)) {
				continue;
			}

			try {
				$moment = $this->appConfig->getValueString(AppIdentity::APP_ID, $key, '', true);
			} catch (Throwable) {
				continue;
			}

			if ($moment !== '') {
				$markers[substr($key, strlen(self::KEY_PREFIX))] = $moment;
			}
		}

		return $markers;
	}//end all()

	/**
	 * The app-config key of an item's marker, or null when the uuid cannot make a valid one.
	 *
	 * @param string $itemUuid The plan item.
	 *
	 * @return string|null
	 */
	private function keyFor(string $itemUuid): ?string {
		$key = self::KEY_PREFIX . $itemUuid;
		if ($itemUuid === '' || strlen($key) > self::MAX_KEY_LENGTH) {
			return null;
		}

		return $key;
	}//end keyFor()
}//end class
