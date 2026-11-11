<?php

/**
 * DependentValueValidatorInterface: what callers outside the component use of DependentValueValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
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

namespace OCA\OpenRegister\Service\Rules;

/**
 * Declares only what callers outside the component use of DependentValueValidator; the rest
 * of the class stays its own.
 */
interface DependentValueValidatorInterface {

	/**
	 * Validate every dependent value table a schema declares.
	 *
	 * @param array<string, mixed> $schema The schema shape, with its `properties` block.
	 *
	 * @return array<int, array{code: string, message: string}> The errors, empty when every table is sound.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function validate(array $schema): array;
}//end interface
