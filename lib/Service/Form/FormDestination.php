<?php

/**
 * FormDestination: the schema a form writes into, and who may submit the form.
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
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Db\Schema;

/**
 * The destination a form's mapping is judged against: the schema, and the
 * form's audience (`public` or `authenticated`; null for an internal form).
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
 */
final class FormDestination {

	/**
	 * Constructor.
	 *
	 * @param Schema      $schema   The destination schema.
	 * @param string|null $audience `public` or `authenticated` when the form is not internal.
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly ?string $audience = null,
	) {
	}//end __construct()
}//end class
