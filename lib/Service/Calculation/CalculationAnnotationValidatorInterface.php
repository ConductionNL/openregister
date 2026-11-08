<?php

/**
 * CalculationAnnotationValidatorInterface: what callers outside the component use of CalculationAnnotationValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
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

namespace OCA\OpenRegister\Service\Calculation;

/**
 * Declares only what callers outside the component use of CalculationAnnotationValidator; the rest
 * of the class stays its own.
 */
interface CalculationAnnotationValidatorInterface {

	/**
	 * Validate a schema's `x-openregister-calculations` annotation.
	 *
	 * @param array<string, mixed> $schema Full schema (must include `properties`).
	 *
	 * @return array<int, array{code: string, message: string}> Validation error list.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-2
	 */
	public function validate(array $schema): array;
}//end interface
