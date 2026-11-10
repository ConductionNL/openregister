<?php

/**
 * SurvivorshipAnnotationValidatorInterface: what callers outside lib/Service use of SurvivorshipAnnotationValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survivorship
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

namespace OCA\OpenRegister\Service\Survivorship;

/**
 * Validates the `x-openregister-survivorship` annotation of a schema.
 */
interface SurvivorshipAnnotationValidatorInterface {

	/**
	 * Validate the `x-openregister-survivorship` annotation in a schema shape.
	 *
	 * @param array<string, mixed> $schema Shape with `properties` and `x-openregister-survivorship`.
	 *
	 * @return array<int, array{code: string, message: string}> Errors; empty when valid or absent.
	 */
	public function validate(array $schema): array;
}//end interface
