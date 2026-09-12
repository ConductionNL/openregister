<?php

/**
 * OpenRegister RegistryOwnedPropertyGuard
 *
 * Pure decision logic for `registry-subscriptions` REQ 3: an inbound
 * registry update may only touch properties the schema's
 * `x-openregister-registry.owned` list names. Deliberately framework-free
 * (no Nextcloud/DB classes) so the 422-vs-apply decision is unit-testable
 * without booting a Nextcloud container.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Registry
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Registry;

/**
 * Decides whether a supplied set of inbound properties may be applied.
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-inbound-registry-update-writes-owned-properties-only
 */
final class RegistryOwnedPropertyGuard {

	/**
	 * Check a supplied property set against the schema's owned list.
	 *
	 * @param array<int, string>   $owned    Property names the registry may write.
	 * @param array<string, mixed> $supplied The properties the inbound update carries.
	 *
	 * @return array{allowed: bool, rejected: array<int, string>} `allowed`
	 *         is true only when every key of `$supplied` is in `$owned`.
	 *         `rejected` names every offending key, so the 422 response can
	 *         name all of them at once rather than only the first.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-inbound-registry-update-writes-owned-properties-only
	 */
	public function check(array $owned, array $supplied): array {
		$ownedSet = array_flip($owned);

		$rejected = [];
		foreach (array_keys($supplied) as $property) {
			if (isset($ownedSet[$property]) === false) {
				$rejected[] = (string)$property;
			}
		}

		return [
			'allowed' => (count($rejected) === 0),
			'rejected' => $rejected,
		];
	}//end check()
}//end class
