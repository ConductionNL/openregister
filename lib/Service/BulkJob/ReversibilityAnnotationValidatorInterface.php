<?php

/**
 * ReversibilityAnnotationValidatorInterface: what callers outside the component use of ReversibilityAnnotationValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\BulkJob
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

namespace OCA\OpenRegister\Service\BulkJob;

/**
 * Declares only what callers outside the component use of ReversibilityAnnotationValidator; the rest
 * of the class stays its own.
 */
interface ReversibilityAnnotationValidatorInterface {

	/**
	 * Validate the declared actions of one schema.
	 *
	 * @param array<string, mixed> $configuration The schema's configuration.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals, empty when the declaration holds.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function validate(array $configuration): array;
}//end interface
