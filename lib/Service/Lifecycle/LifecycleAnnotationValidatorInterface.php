<?php

/**
 * LifecycleAnnotationValidatorInterface: what callers outside the component use of LifecycleAnnotationValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
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

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * Declares only what callers outside the component use of LifecycleAnnotationValidator; the rest
 * of the class stays its own.
 */
interface LifecycleAnnotationValidatorInterface {

	/**
	 * Validate the annotation block on a schema definition.
	 *
	 * @param array<string, mixed> $schema Full schema definition (top-level shape — must include `properties`).
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function validate(array $schema): array;
}//end interface
