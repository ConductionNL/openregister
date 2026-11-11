<?php

/**
 * MergeAnnotationValidatorInterface: what callers outside the component use of MergeAnnotationValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Merge
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archive/2026-07-03-mdm-merge-engine/tasks.md#1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Merge;

/**
 * Declares only what callers outside the component use of MergeAnnotationValidator; the rest
 * of the class stays its own.
 *
 * @spec openspec/changes/archive/2026-07-03-mdm-merge-engine/tasks.md#1.2
 */
interface MergeAnnotationValidatorInterface {
	/**
	 * Validate the `x-openregister-merge` annotation in a schema shape.
	 *
	 * @param array<string, mixed> $schema Shape with `properties` and `x-openregister-merge`.
	 *
	 * @return array<int, array{code: string, message: string}> Errors; empty when valid.
	 *
	 * @spec openspec/changes/archive/2026-07-03-mdm-merge-engine/tasks.md#1.2
	 */
	public function validate(array $schema): array;
}//end interface
