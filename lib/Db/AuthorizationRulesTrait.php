<?php

/**
 * AuthorizationRulesTrait: set authorization rules from an array or JSON.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Set an entity's authorization rules; the entity supplies getDefaultAuthorization().
 */
trait AuthorizationRulesTrait {

	/**
	 * Set the authorization rules, from an array or a JSON string.
	 *
	 * Invalid JSON, a JSON value that is not an object and null all give the
	 * entity's default rules.
	 *
	 * @param array|string|null $authorization Authorization rules structure or JSON string
	 *
	 * @return static This entity.
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	public function setAuthorization(array|string|null $authorization): static {
		// Handle JSON string from database (type safety).
		if (is_string($authorization) === true) {
			try {
				$decoded = json_decode($authorization, true);
				// Invalid JSON, use default.
				$authorization = null;
				if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) === true) {
					$authorization = $decoded;
				}
			} catch (\Exception $e) {
				// If decoding fails, use default.
				$authorization = null;
			}
		}

		$this->authorization = $authorization ?? $this->getDefaultAuthorization();
		$this->markFieldUpdated(attribute: 'authorization');
		return $this;
	}//end setAuthorization()
}//end trait
