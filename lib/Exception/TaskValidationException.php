<?php

/**
 * A task write carried a value the task vocabulary refuses.
 *
 * Thrown when a legacy status has no mapping, a priority is on no known
 * scale, `expires_at` is set earlier than `due_at`, or a performer type is
 * unknown. The message ALWAYS names the refused value: a coercing default
 * would have silently absorbed the live fleet defects this change exists to
 * surface (procest writing `status:'open'`, pipelinq defaulting priority to
 * `"normaal"`).
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
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use InvalidArgumentException;

/**
 * A refused task value, named in the message.
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
 */
class TaskValidationException extends InvalidArgumentException {
}//end class
