<?php

/**
 * ReviewAnnotationValidatorInterface: what callers outside the component use of ReviewAnnotationValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Decision
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

namespace OCA\OpenRegister\Service\Decision;

/**
 * Declares only what callers outside the component use of ReviewAnnotationValidator; the rest
 * of the class stays its own.
 */
interface ReviewAnnotationValidatorInterface {

	/**
	 * The refusals for a schema configuration; empty when it is sound or absent.
	 *
	 * @param array<string, mixed> $configuration The schema configuration.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function validate(array $configuration): array;
}//end interface
