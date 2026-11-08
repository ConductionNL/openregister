<?php

/**
 * The `x-openregister-hierarchy` annotation check, as the schema save check uses it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Only the methods the schema save check uses.
 */
interface HierarchyAnnotationValidatorInterface {

	/**
	 * Validate the annotation against the schema it sits on.
	 *
	 * @param array<string, mixed> $schema `properties`, `slug` and the annotation.
	 *
	 * @return array<int, array{code: string, message: string, severity: string}> The findings.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function validate(array $schema): array;

	/**
	 * Split findings into the errors that refuse the save and the warnings that only log.
	 *
	 * @param array<int, array{code: string, message: string, severity: string}> $findings The findings.
	 *
	 * @return array{errors: array<int, array<string, string>>, warnings: array<int, array<string, string>>} The split.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public static function partition(array $findings): array;
}//end interface
