<?php

/**
 * OpenRegister TypedFieldsJsonTrait
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

/**
 * Serialises the using entity through TypedFieldsJson: every typed field in
 * registration order, a date-time as ISO 8601, then the values the entity
 * serialises differently from how it stores them (jsonOverrides()), each in
 * its own field's place.
 *
 * @spec exclude shared entity plumbing, not a capability of its own
 */
trait TypedFieldsJsonTrait {

	/**
	 * Convert the entity to a JSON serialisable array.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	public function jsonSerialize(): array {
		$typed = (new TypedFieldsJson())->serialise(fieldTypes: $this->getFieldTypes(), values: get_object_vars($this));

		return array_merge($typed, $this->jsonOverrides());
	}//end jsonSerialize()

	/**
	 * The serialised values that differ from the stored ones, by field name.
	 *
	 * @return array<string, mixed>
	 */
	protected function jsonOverrides(): array {
		return [];
	}//end jsonOverrides()
}//end trait
