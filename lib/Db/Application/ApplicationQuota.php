<?php

/**
 * OpenRegister ApplicationQuota
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\Application
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\Application;

use OCA\OpenRegister\Db\JsonArrayColumn;

/**
 * Turns a quota payload into the allocation setters to call on an application.
 *
 * A JSON string is decoded; an undecodable one is a no-op, so a malformed
 * payload cannot wipe what is stored. An explicit null clears every allocation
 * (NULL = unlimited). Only the allocations the caller sent are touched, so a
 * partial quota object cannot wipe the ones it left out. A non-numeric
 * allocation means unlimited, and a negative one floors at zero.
 *
 * @spec exclude application plumbing moved out of the entity, not a capability of its own
 */
final class ApplicationQuota {

	/**
	 * Quota key to the application's allocation setter.
	 *
	 * @var array<string, string>
	 */
	private const SETTERS = [
		'storage' => 'setStorageQuota',
		'bandwidth' => 'setBandwidthQuota',
		'requests' => 'setRequestQuota',
		'users' => 'setUserQuota',
		'groups' => 'setGroupQuota',
	];

	/**
	 * The setter to call for each allocation the payload sets, with its value.
	 *
	 * @param array|string|null $quota The quota payload.
	 *
	 * @return array<string, int|null>
	 *
	 * @spec exclude application plumbing moved out of the entity, not a capability of its own
	 */
	public function allocations(array|string|null $quota): array {
		$quota = $this->normalised(quota: $quota);
		if ($quota === null) {
			return [];
		}

		$allocations = [];
		foreach (self::SETTERS as $key => $setter) {
			if (array_key_exists($key, $quota) === true) {
				$allocations[$setter] = $this->allocation(value: $quota[$key]);
			}
		}

		return $allocations;
	}//end allocations()

	/**
	 * The payload as an array, every key null for an explicit null, or null for a no-op.
	 *
	 * @param array|string|null $quota The quota payload.
	 *
	 * @return array<mixed>|null
	 */
	private function normalised(array|string|null $quota): ?array {
		if (is_string($quota) === true) {
			return (new JsonArrayColumn())->decoded(json: $quota);
		}

		if ($quota === null) {
			return array_fill_keys(array_keys(self::SETTERS), null);
		}

		return $quota;
	}//end normalised()

	/**
	 * One allocation: a non-negative integer, or null for unlimited.
	 *
	 * @param mixed $value The sent allocation.
	 *
	 * @return int|null
	 */
	private function allocation(mixed $value): ?int {
		if (is_numeric($value) === true) {
			return max(0, (int)$value);
		}

		return null;
	}//end allocation()
}//end class
