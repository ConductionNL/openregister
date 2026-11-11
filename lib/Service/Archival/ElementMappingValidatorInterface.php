<?php

/**
 * ElementMappingValidatorInterface: what callers outside the component use of ElementMappingValidator.
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
 * Declares only what callers outside the component use of ElementMappingValidator; the rest
 * of the class stays its own.
 */
interface ElementMappingValidatorInterface {

	/**
	 * The schema configuration key the mapping lives under.
	 */
	public const ANNOTATION_KEY = 'x-openregister-mdto-mapping';

	/**
	 * Check one schema's mapping against the MDTO catalogue and its own properties.
	 *
	 * Each rule's message names which element and which key was wrong; an
	 * administrator reading "invalid mapping" learns nothing.
	 *
	 * @param array<string, mixed> $mapping    The annotation, as written.
	 * @param array<string, mixed> $properties The schema's declared properties.
	 *
	 * @return array<int, array{code: string, message: string}> The errors, empty when the mapping is sound.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function validate(array $mapping, array $properties): array;
}//end interface
