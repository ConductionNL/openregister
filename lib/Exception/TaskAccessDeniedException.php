<?php

/**
 * A task lifecycle verb was refused by authorization.
 *
 * Fail-closed by construction: this is ALSO thrown when the answer could not
 * be determined — an unresolvable role, an unavailable group backend, an
 * unknown performer type. "Cannot decide" is a denial, never a skipped check
 * (ADR-005; the counter-example is measured at
 * `lib/Controller/FlowRunController.php:423-436`, where knowing a run uuid
 * was enough to answer someone else's approval).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * A denied (or undeterminable) task authorization decision.
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
class TaskAccessDeniedException extends RuntimeException {
}//end class
