<?php

/**
 * OpenRegister Extending Form Validator Interface
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
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

namespace OCA\OpenRegister\Service\Schemas;

/**
 * Checks the `x-openregister-extends-form` declarations a schema save carries.
 *
 * The schema save path in the Db layer refuses a declaration the vocabulary
 * cannot honour; it finds the declarations and checks each one through this
 * contract, never through the class that implements it.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
interface ExtendingFormValidatorInterface extends ExtendingFormDeclarationInterface {

	/**
	 * Check one declaration against the vocabulary.
	 *
	 * @param mixed  $annotation The declaration as stored.
	 * @param string $path       Where the declaration sits, for the error message.
	 *
	 * @return array<int, array{code: string, key: string, path: string, message: string}> The refusals, empty when valid.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function validate(mixed $annotation, string $path = self::ANNOTATION): array;
}//end interface
