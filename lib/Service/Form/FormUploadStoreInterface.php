<?php

/**
 * FormUploadStoreInterface: what callers outside the component use of FormUploadStore.
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

use OCA\OpenRegister\Exception\FormSubmitRefusedException;

/**
 * Hold uploaded form files behind expiring tokens.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface FormUploadStoreInterface {

	/**
	 * Hold one uploaded file and return its token.
	 *
	 * @param string                                                             $formId   The form the token belongs to.
	 * @param string                                                             $property The destination property the file is for.
	 * @param array<string, mixed>                                               $rule     That property's schema definition.
	 * @param array{name?: mixed, type?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed} $file The PHP upload array.
	 *
	 * @return array{token: string, expiresAt: int} The token and its expiry (unix seconds).
	 *
	 * @throws FormSubmitRefusedException 422 when the file breaks the property's rules or did not arrive.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function issue(string $formId, string $property, array $rule, array $file): array;

	/**
	 * Delete every expired, unclaimed token, and the bytes of an upload whose record was never written.
	 *
	 * @return int How many tokens were deleted, so a zero is a counted zero.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function purge(): int;
}//end interface
