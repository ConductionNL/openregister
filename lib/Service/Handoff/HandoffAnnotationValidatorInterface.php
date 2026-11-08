<?php

/**
 * HandoffAnnotationValidatorInterface: what callers outside the component use of HandoffAnnotationValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handoff
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

namespace OCA\OpenRegister\Service\Handoff;

/**
 * Declares only what callers outside the component use of HandoffAnnotationValidator; the rest
 * of the class stays its own.
 */
interface HandoffAnnotationValidatorInterface {

	/**
	 * Validate the annotation.
	 *
	 * Expects the annotation at top-level alongside `properties` (the shape
	 * SchemaMapper hands every annotation validator):
	 * `['properties' => [...], 'x-openregister-handoff' => [...entries],
	 *   'x-openregister-lifecycle' => [...]?]`.
	 *
	 * @param array<string, mixed> $schema The schema shape to validate.
	 *
	 * @return array<int, array{code: string, message: string}> Aggregated errors (empty = valid).
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: `x-openregister-handoff` declarative dialect)
	 */
	public function validate(array $schema): array;
}//end interface
