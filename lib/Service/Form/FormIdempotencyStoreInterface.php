<?php

/**
 * FormIdempotencyStoreInterface: what callers outside the component use of FormIdempotencyStore.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
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

namespace OCA\OpenRegister\Service\Form;

/**
 * Keep the answers of idempotent form submits until they expire.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface FormIdempotencyStoreInterface {

	/**
	 * Delete every expired answer.
	 *
	 * @return int How many were deleted, so a zero is a counted zero.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function purge(): int;
}//end interface
