<?php

/**
 * OpenRegister UserFormat
 *
 * Validates that a string is the id of a Nextcloud user that actually exists.
 *
 * A property declared `format: user` names a person, and the only useful thing
 * to check about such a value is whether that person is still there. A user id
 * is syntactically just a string, so a pattern can say nothing — the backend is
 * the only authority. Without this check a schema could carry the id of a
 * deleted account indefinitely and every consumer would resolve it to nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Format
 * @package  OCA\OpenRegister\Formats
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

namespace OCA\OpenRegister\Formats;

use Closure;
use OCP\IUserManager;
use Opis\JsonSchema\Format;

/**
 * Format resolver asserting that a user id resolves to a real account.
 */
class UserFormat implements Format {
	/**
	 * Constructor.
	 *
	 * @param IUserManager                $userManager The backend consulted for existence.
	 * @param (Closure(string): bool)|null $planned     Says whether a caller declared that an account with
	 *                                                 this id is written before the object; null: none is.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly ?Closure $planned=null,
	) {
	}//end __construct()

	/**
	 * Validate that the value names an existing Nextcloud user.
	 *
	 * @param mixed $data The value to validate.
	 *
	 * @inheritDoc
	 *
	 * @return bool True when the user exists, or when the caller declared it is written first.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	public function validate(mixed $data): bool {
		if (is_string($data) === false) {
			return false;
		}

		$uid = trim($data);
		if ($uid === '') {
			return false;
		}

		// Existence is the cheaper question and the only one being asked —
		// userExists() rather than get(), since nothing here needs the object.
		if ($this->userManager->userExists($uid) === true) {
			return true;
		}

		// An account the caller is about to write, such as an example set's
		// cast member during its check; never any other id.
		return $this->planned !== null && ($this->planned)($uid) === true;
	}//end validate()
}//end class
