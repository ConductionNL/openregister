<?php

/**
 * ArchivalAnnotationValidatorInterface: what callers outside the component use of ArchivalAnnotationValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
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

namespace OCA\OpenRegister\Service\Archival;

/**
 * Declares only what callers outside the component use of ArchivalAnnotationValidator; the rest
 * of the class stays its own.
 */
interface ArchivalAnnotationValidatorInterface {

	/**
	 * Validate the `x-openregister-archival` annotation block on a schema definition.
	 *
	 * @param array<string, mixed> $schema Full schema definition. The validator only
	 *                                     reads `x-openregister-archival`; it does
	 *                                     not consult `properties` because the
	 *                                     condition grammar is field-agnostic at
	 *                                     save time (fields are resolved by the
	 *                                     runtime evaluator against actual rows).
	 *
	 * @return array<int, array{code: string, message: string, severity?: string}> List of findings
	 *         (empty = valid). A finding carrying `severity: warning` does NOT refuse the schema;
	 *         see {@see partition()} and {@see SEVERITY_WARNING}.
	 *
	 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-2
	 */
	public function validate(array $schema): array;

	/**
	 * Split validator findings into the ones that refuse a schema and the ones
	 * that only deserve a log line.
	 *
	 * Callers save the schema when `errors` is empty, whatever `warnings`
	 * holds. Kept here rather than at each call site so "which findings are
	 * fatal" is answered in the file that decides it.
	 *
	 * @param array<int, array{code: string, message: string, severity?: string}> $findings Findings from validate().
	 *
	 * @return array{
	 *     errors: array<int, array{code: string, message: string, severity?: string}>,
	 *     warnings: array<int, array{code: string, message: string, severity?: string}>
	 * }
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public static function partition(array $findings): array;
}//end interface
