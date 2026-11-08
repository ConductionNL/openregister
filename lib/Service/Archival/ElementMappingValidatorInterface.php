<?php

/**
 * The MDTO element-mapping check, as the schema save check uses it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
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
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * Only the method the schema save check uses.
 */
interface ElementMappingValidatorInterface {

	/**
	 * Check one schema's mapping against the MDTO catalogue and its own properties.
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
