<?php

/**
 * ExternalLinkAnnotationValidatorInterface: what callers outside the component use of ExternalLinkAnnotationValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExternalLink
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

namespace OCA\OpenRegister\Service\ExternalLink;

/**
 * Declares only what callers outside the component use of ExternalLinkAnnotationValidator; the rest
 * of the class stays its own.
 */
interface ExternalLinkAnnotationValidatorInterface {

	/**
	 * Validate the annotation on a schema definition.
	 *
	 * @param array<string, mixed> $shape The schema shape: `properties` and the annotation.
	 *
	 * @return array<int, string> The errors; an empty list means valid.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-a-schema-declares-links-out-of-its-objects-req-avs-001
	 */
	public function validate(array $shape): array;
}//end interface
