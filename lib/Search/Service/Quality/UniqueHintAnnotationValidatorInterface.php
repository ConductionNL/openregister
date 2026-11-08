<?php

/**
 * UniqueHintAnnotationValidatorInterface: what callers outside the Search component use of UniqueHintAnnotationValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Quality;

/**
 * Validates `x-openregister-unique-hint` and reads the properties a schema nominates through it.
 */
interface UniqueHintAnnotationValidatorInterface {

	/**
	 * Validate the `x-openregister-unique-hint` annotation against the
	 * properties the schema declares.
	 *
	 * @param array<string, mixed> $schema Shape with `properties` and `x-openregister-unique-hint`.
	 *
	 * @return array<int, array{code: string, property: string, message: string}> Errors; empty when valid.
	 */
	public function validate(array $schema): array;

	/**
	 * The property names a schema configuration nominates, cleaned.
	 *
	 * @param array<string, mixed> $configuration The schema configuration block.
	 *
	 * @return array<int, string> The nominated property names.
	 */
	public function nominated(array $configuration): array;
}//end interface
