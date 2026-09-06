<?php

/**
 * An authorization decision on a plan item denied, or could not be determined (which is a denial).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * An authorization decision on a plan item denied, or could not be determined (which is a denial).
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
 */
class CaseAccessDeniedException extends RuntimeException {
}//end class
