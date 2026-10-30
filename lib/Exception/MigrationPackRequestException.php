<?php

/**
 * OpenRegister MigrationPackRequestException
 *
 * Raised when a migration pack request is malformed before any pack is read:
 * no uploaded file, a body that is not a JSON object, or a preview with too
 * many or too large sample rows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
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

namespace OCA\OpenRegister\Exception;

/**
 * A malformed migration pack request, answered with HTTP 400.
 *
 * Carries the machine-readable error code and the translated sentence the
 * caller is shown. Both are chosen where the request is read, from static
 * text, so the response never carries anything a downstream library wrote.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
 */
class MigrationPackRequestException extends MigrationPackRefusedException {
	/**
	 * Constructor.
	 *
	 * @param string $errorCode   Kebab-case code: `invalid-request` or `too-many-rows`.
	 * @param string $userMessage The translated reason, shown to the caller.
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
	 */
	public function __construct(
		private readonly string $errorCode,
		private readonly string $userMessage,
	) {
		parent::__construct(message: $errorCode);
	}//end __construct()

	/**
	 * The machine-readable error code.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
	 */
	public function getErrorCode(): string {
		return $this->errorCode;
	}//end getErrorCode()

	/**
	 * The translated reason the caller is shown.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
	 */
	public function getUserMessage(): string {
		return $this->userMessage;
	}//end getUserMessage()
}//end class
