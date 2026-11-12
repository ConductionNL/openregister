<?php

/**
 * The form and destination property an upload is issued for.
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
 * Where an uploaded file is headed: one form, one destination property and that property's rules.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
 */
final class FormUploadTarget {
	/**
	 * Constructor.
	 *
	 * @param string               $formId   The form the token belongs to.
	 * @param string               $property The destination property the file is for.
	 * @param array<string, mixed> $rule     That property's schema definition.
	 */
	public function __construct(
		public readonly string $formId,
		public readonly string $property,
		public readonly array $rule,
	) {
	}//end __construct()
}//end class
