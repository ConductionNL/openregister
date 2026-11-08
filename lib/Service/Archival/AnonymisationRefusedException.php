<?php

/**
 * An anonymisation that was refused, or stopped, with the reason in the message.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Exception
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use RuntimeException;

/**
 * Thrown when an anonymisation may not run, or could not finish.
 */
class AnonymisationRefusedException extends RuntimeException {
}//end class
