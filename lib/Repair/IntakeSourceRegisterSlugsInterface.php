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

use OCA\OpenRegister\Kernel\Contracts\Core\IntakeSourceRegisterAddressInterface;

/**
 * The register and schema slugs the intake-source register is seeded under, for
 * callers outside Repair. SeedIntakeSourceRegister implements this, so its own
 * REGISTER_SLUG and SCHEMA_SLUG still resolve to the same values. The values are
 * the Core address the intake-source registry reads, inherited from
 * IntakeSourceRegisterAddressInterface.
 */
interface IntakeSourceRegisterSlugsInterface extends IntakeSourceRegisterAddressInterface {
}//end interface
