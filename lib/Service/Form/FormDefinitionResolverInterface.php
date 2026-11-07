<?php

/**
 * FormDefinitionResolverInterface: what callers outside the component use of FormDefinitionResolver.
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
 * Resolve a published form's writes and audience.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface FormDefinitionResolverInterface {

	/**
	 * The form's id, writes and audience.
	 *
	 * Unknown, unpublished and destination-less forms answer the same 404, so
	 * the endpoint is not an oracle for which ids exist.
	 *
	 * @param string $formId The form object's uuid.
	 *
	 * @return array{id: string, writes: array<int, array<string, mixed>>, audience: string} The form.
	 *
	 * @throws FormSubmitRefusedException 404.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function resolve(string $formId): array;
}//end interface
