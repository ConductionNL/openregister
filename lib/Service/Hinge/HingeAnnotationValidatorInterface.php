<?php

/**
 * HingeAnnotationValidatorInterface: what callers outside lib/Service use of HingeAnnotationValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hinge
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hinge;

/**
 * Validates the lens, list and geographic-inheritance annotations of a schema.
 */
interface HingeAnnotationValidatorInterface {

	/**
	 * Validate the lens, list and geographic-inheritance blocks of one schema.
	 *
	 * @param array $schema The schema shape: `properties` plus the three annotations.
	 *
	 * @return array<int, array{code: string, message: string}> The findings, empty when all is well.
	 */
	public function validate(array $schema): array;
}//end interface
