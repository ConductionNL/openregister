<?php

/**
 * The terms one grant is made on: its permission mask, its extension verbs, when it ends and the share time it records.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTime;

/**
 * What a grant allows and for how long, beside whom it is for.
 *
 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
 */
final class GrantTerms {

	/**
	 * Constructor.
	 *
	 * @param int           $permissions Core permission bitmask.
	 * @param string[]      $verbs       Extension verbs (e.g. `use`, `run`) carried in the share's attribute bag (ADR-010).
	 * @param DateTime|null $expiresAt   When the grant ends; null for no end.
	 * @param DateTime|null $sharedAt    The share time to record; null for now.
	 */
	public function __construct(
		public readonly int $permissions = 1,
		public readonly array $verbs = [],
		public readonly ?DateTime $expiresAt = null,
		public readonly ?DateTime $sharedAt = null,
	) {
	}//end __construct()

	/**
	 * These terms, ending and dated at the moments a declaration names.
	 *
	 * A moment is a non-blank date string; anything else is no moment.
	 *
	 * @param mixed $expiresAt The declared end, or anything else for no end.
	 * @param mixed $sharedAt  The declared share time, or anything else for now.
	 *
	 * @return self The dated terms.
	 *
	 * @throws \Exception When a declared moment is not a date.
	 */
	public function dated(mixed $expiresAt, mixed $sharedAt): self {
		return new self(
			permissions: $this->permissions,
			verbs: $this->verbs,
			expiresAt: $this->moment(value: $expiresAt),
			sharedAt: $this->moment(value: $sharedAt)
		);
	}//end dated()

	/**
	 * A declared moment, or null when none is declared.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return DateTime|null The moment.
	 *
	 * @throws \Exception When the value is not a date.
	 */
	private function moment(mixed $value): ?DateTime {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return new DateTime($value);
	}//end moment()
}//end class
