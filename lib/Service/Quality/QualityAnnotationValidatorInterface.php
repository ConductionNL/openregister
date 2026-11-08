<?php

/**
 * QualityAnnotationValidatorInterface: what callers outside the component use of QualityAnnotationValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archive/2026-06-23-mdm-foundation/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Quality;

/**
 * Declares only what callers outside the component use of QualityAnnotationValidator; the rest
 * of the class stays its own.
 *
 * @spec openspec/changes/archive/2026-06-23-mdm-foundation/tasks.md#task-3
 */
interface QualityAnnotationValidatorInterface {
	/**
	 * Validate the `x-openregister-quality` annotation in a schema shape.
	 *
	 * @param array<string, mixed> $schema Shape with `properties` and `x-openregister-quality`.
	 *
	 * @return array<int, array{code: string, message: string}> Errors; empty when valid.
	 *
	 * @spec openspec/changes/archive/2026-06-23-mdm-foundation/tasks.md#task-3
	 */
	public function validate(array $schema): array;
}//end interface
