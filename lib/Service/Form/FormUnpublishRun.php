<?php

/**
 * One unpublish of a form a schema change broke.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

/**
 * The form, the schema that broke it and why, while FormDependentsCheck unpublishes it.
 *
 * Built by FormDependentsCheck::apply() and passed only between its own helpers.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
 */
final class FormUnpublishRun {
	/**
	 * Constructor.
	 *
	 * @param FormDependent                    $form     The form.
	 * @param string                           $schema   The schema slug.
	 * @param array<int, array<string, mixed>> $findings Why the form broke.
	 */
	public function __construct(
		public readonly FormDependent $form,
		public readonly string $schema,
		public readonly array $findings,
	) {
	}//end __construct()
}//end class
