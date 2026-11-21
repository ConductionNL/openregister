<?php

/**
 * The form, property and draft an upload token is claimed for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

/**
 * Who would hold the file a token named: one form, one destination property, one draft.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
 */
final class FormUploadClaim {
	/**
	 * Constructor.
	 *
	 * @param string      $formId   The form submitting the token.
	 * @param string      $property The destination property the payload put it on.
	 * @param string|null $draftId  The draft being saved or left; null (a final submit of no draft) claims nothing.
	 */
	public function __construct(
		public readonly string $formId,
		public readonly string $property,
		public readonly ?string $draftId,
	) {
	}//end __construct()

	/**
	 * Whether a claim record names exactly this form, property and draft.
	 *
	 * @param array<mixed> $record The claim record.
	 *
	 * @return bool True when all three match.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	public function isNamedBy(array $record): bool {
		return ($record['formId'] ?? null) === $this->formId
			&& ($record['property'] ?? null) === $this->property
			&& ($record['draftId'] ?? null) === $this->draftId;
	}//end isNamedBy()
}//end class
