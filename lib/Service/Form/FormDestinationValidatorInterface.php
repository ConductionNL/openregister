<?php

/**
 * FormDestinationValidatorInterface: what callers outside the component use of FormDestinationValidator.
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

use OCA\OpenRegister\Db\Schema;

/**
 * Judge a form's mapping against its destination schema.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface FormDestinationValidatorInterface {

	/**
	 * Every problem with a mapping into a destination schema.
	 *
	 * @param array<string, mixed> $mapping The mapping: `fields` and `fixed`.
	 * @param Schema               $schema  The destination schema.
	 * @param array<string, mixed> $options `audience`: `public` or `authenticated` when the form is not internal.
	 *
	 * @return array<int, array{field?: string, property: string, code: string, message: string}> The findings; empty when valid.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
	 */
	public function validate(array $mapping, Schema $schema, array $options = []): array;
}//end interface
