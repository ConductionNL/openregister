<?php

/**
 * A published form as its owning app names it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * Which form, in which app, under which title, and for whom it is published.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
 */
final readonly class PublishedForm {

	/**
	 * Name the form.
	 *
	 * @param string      $id       The form's id in its owning app.
	 * @param string      $app      The owning app's id; its `formDestinationBreak` setting decides.
	 * @param string      $title    The form's title, for the response and the notification.
	 * @param string|null $audience `public` or `authenticated`, when the form is not internal.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
	 */
	public function __construct(
		public string $id,
		public string $app,
		public string $title,
		public ?string $audience = null,
	) {
	}//end __construct()
}//end class
