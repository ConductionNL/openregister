<?php

/**
 * Class RegisterNotFoundException
 *
 * Exception thrown when a register cannot be found by slug or ID.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Exception
 * @package   OCA\OpenRegister\Exception
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * Exception thrown when a register cannot be found by slug or ID
 *
 * Thrown when attempting to access a register that doesn't exist or the user
 * doesn't have permission to access. Used for error handling in register operations.
 * Uses HTTP 404 Not Found status code.
 *
 * @spec openspec/specs/data-import-export/spec.md
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @phpstan-consistent-constructor
 */
class RegisterNotFoundException extends Exception {
	/**
	 * RegisterNotFoundException constructor
	 *
	 * Initializes exception with register identifier that was not found.
	 * Creates user-friendly error message including the register slug or ID.
	 *
	 * @param string $registerSlugOrId The register slug or ID that was not found
	 * @param int $code The exception code (default: 404 Not Found)
	 * @param Exception|null $previous The previous exception that caused this one
	 * @param string|null $remedies Optional additional guidance appended to the message
	 *                              (e.g. actionable next steps). Existing callers that omit
	 *                              this keep the original message unchanged.
	 *
	 * @return void
	 *
	 * @phpstan-param string $registerSlugOrId
	 * @phpstan-param int $code
	 * @phpstan-param Exception|null $previous
	 * @phpstan-param string|null $remedies
	 *
	 * @spec openspec/changes/register-import-auto-create/specs/data-import-export/spec.md#requirement-clear-failure-when-auto-create-is-impossible-req-imp-ac-02
	 */
	public function __construct(string $registerSlugOrId, int $code = 404, ?Exception $previous = null, ?string $remedies = null) {
		parent::__construct(message: self::messageFor(registerSlugOrId: $registerSlugOrId, remedies: $remedies), code: $code, previous: $previous);
	}//end __construct()

	/**
	 * The two remedies an import names when its target register is missing.
	 *
	 * @var string
	 */
	public const IMPORT_REMEDIES = 'Create the register first, or supply a full register bundle '
		. '(a register definition together with its schemas) to auto-create it.';

	/**
	 * The refusal for an import whose register does not exist and cannot be created
	 * from the payload: names the register and both remedies, answers 404.
	 *
	 * @param string $registerSlugOrId The register slug or ID the import asked for.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/register-import-auto-create/specs/data-import-export/spec.md#requirement-clear-failure-when-auto-create-is-impossible-req-imp-ac-02
	 */
	public static function forImport(string $registerSlugOrId): self {
		return new self(registerSlugOrId: $registerSlugOrId, code: 404, remedies: self::IMPORT_REMEDIES);
	}//end forImport()

	/**
	 * The message naming the register, with the remedies appended when given.
	 *
	 * @param string      $registerSlugOrId The register slug or ID that was not found.
	 * @param string|null $remedies         Optional guidance appended to the message.
	 *
	 * @return string
	 */
	private static function messageFor(string $registerSlugOrId, ?string $remedies): string {
		$message = "Register not found: '" . $registerSlugOrId . "'";
		if ($remedies !== null && $remedies !== '') {
			$message .= ' ' . $remedies;
		}

		return $message;
	}//end messageFor()
}//end class
