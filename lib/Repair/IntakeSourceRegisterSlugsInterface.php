<?php

/**
 * IntakeSourceRegisterSlugsInterface: the intake-source register and schema slugs callers outside Repair read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
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

namespace OCA\OpenRegister\Repair;

/**
 * The register and schema slugs the intake-source register is seeded under, for
 * callers outside Repair. SeedIntakeSourceRegister implements this, so its own
 * REGISTER_SLUG and SCHEMA_SLUG still resolve to the same values.
 */
interface IntakeSourceRegisterSlugsInterface {

	/**
	 * The register slug this step materialises.
	 *
	 * @var string
	 */
	public const REGISTER_SLUG = 'intake-sources';

	/**
	 * The schema slug an intake source is an object of.
	 *
	 * @var string
	 */
	public const SCHEMA_SLUG = 'intake-source';
}//end interface
