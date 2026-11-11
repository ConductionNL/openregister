<?php

/**
 * OpenRegister Form Submit Keeping
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

/**
 * What a submit keeps beside the object: the per-property findings of a refusal, the idempotency keys and the upload tokens.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */
final readonly class FormSubmitKeeping {

	/**
	 * Bundle the collaborators.
	 *
	 * @param FormPayloadFindings $findings Turns a refusal into per-property findings.
	 * @param FormIdempotencyStore $keys Repeats the first answer for a retried key.
	 * @param FormUploadStore $uploads Holds and releases upload tokens.
	 */
	public function __construct(
		public FormPayloadFindings $findings,
		public FormIdempotencyStore $keys,
		public FormUploadStore $uploads,
	) {
	}//end __construct()
}//end class
