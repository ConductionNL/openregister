<?php

/**
 * A published form that submits into a schema, as its owning app announces it.
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

use Closure;

/**
 * What the schema-save check needs of a form: its mapping, its author and how to unpublish it.
 *
 * The form lives in its owning app (buildiq, portaliq, pipelinq, ...), so
 * the owning app unpublishes it; OpenRegister only calls the closure.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
 */
final class FormDependent {

	/**
	 * Constructor.
	 *
	 * @param string               $id        The form's id in its owning app.
	 * @param string               $app       The owning app's id; its `formDestinationBreak` setting decides.
	 * @param string               $title     The form's title, for the response and the notification.
	 * @param string|null          $author    The user id to notify, when known.
	 * @param array<string, mixed> $mapping   The form's mapping into the schema.
	 * @param Closure|null         $unpublish Unpublishes the form in its owning app.
	 * @param string|null          $audience  `public` or `authenticated`, when the form is not internal.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $app,
		public readonly string $title,
		public readonly ?string $author,
		public readonly array $mapping,
		public readonly ?Closure $unpublish = null,
		public readonly ?string $audience = null,
	) {

	}//end __construct()
}//end class
