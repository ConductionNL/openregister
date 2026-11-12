<?php

/**
 * OpenRegister AuditTrailSealedForm
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\AuditTrail
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

namespace OCA\OpenRegister\Db\AuditTrail;

use DateTimeInterface;

/**
 * The serialised form of an audit trail row, which is also the canonical form
 * the hash chain seals: the id, then AuditTrailFieldTypes::SEALED_FIELDS in
 * that order, a date-time as ISO 8601.
 *
 * @spec exclude audit trail plumbing moved out of the entity, not a capability of its own
 */
final class AuditTrailSealedForm {

	/**
	 * The row's sealed form.
	 *
	 * @param array<string, mixed> $values The row's field values by name.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec exclude audit trail plumbing moved out of the entity, not a capability of its own
	 */
	public function serialise(array $values): array {
		$form = ['id' => $values['id']];
		foreach (AuditTrailFieldTypes::SEALED_FIELDS as $field) {
			$form[$field] = $this->value(value: $values[$field]);
		}

		return $form;
	}//end serialise()

	/**
	 * One field value, a date-time formatted.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return mixed
	 */
	private function value(mixed $value): mixed {
		if ($value instanceof DateTimeInterface) {
			return $value->format('c');
		}

		return $value;
	}//end value()
}//end class
