<?php

/**
 * HierarchyAnnotationValidatorInterface: what callers outside the component use of HierarchyAnnotationValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
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

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Declares only what callers outside the component use of HierarchyAnnotationValidator; the rest
 * of the class stays its own.
 */
interface HierarchyAnnotationValidatorInterface {

	/**
	 * Findings for one schema shape.
	 *
	 * @param array<string, mixed> $schema `properties`, `slug` and the annotation.
	 *
	 * @return array<int, array{code: string, message: string, severity: string}> The findings.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function validate(array $schema): array;

	/**
	 * Split findings into the fatal ones and the rest.
	 *
	 * @param array<int, array{code: string, message: string, severity: string}> $findings The findings.
	 *
	 * @return array{errors: array<int, array<string, string>>, warnings: array<int, array<string, string>>} The split.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public static function partition(array $findings): array;
}//end interface
