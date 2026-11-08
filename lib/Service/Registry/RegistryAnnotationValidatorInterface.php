<?php

/**
 * RegistryAnnotationValidatorInterface: what callers outside the component use of RegistryAnnotationValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Registry
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

namespace OCA\OpenRegister\Service\Registry;

/**
 * Declares only what callers outside the component use of RegistryAnnotationValidator; the rest
 * of the class stays its own.
 */
interface RegistryAnnotationValidatorInterface {

	/**
	 * Validate the registry annotation.
	 *
	 * @param array<string, mixed> $schema Full schema definition, including
	 *                                     `properties` and
	 *                                     `x-openregister-registry`.
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-a-schema-declares-which-registry-owns-which-properties
	 */
	public function validate(array $schema): array;
}//end interface
