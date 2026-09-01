<?php

/**
 * The subject object refused the write a task completion carried.
 *
 * Distinct from {@see TaskFormRefusedException} on purpose: that one is a
 * malformed payload (400), this one is a payload the form accepted and the
 * SUBJECT then refused, on the ordinary save path, because the schema said the
 * value was illegal or the lifecycle said the transition was not allowed from
 * the current state (422). The task is completed in neither case, but a client
 * repairs the two differently: the first by fixing a field, the second by
 * asking why the object would not take it.
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
 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * The subject object's own validation or lifecycle refused the completion's write.
 *
 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
 */
class TaskSubjectWriteRefusedException extends RuntimeException {
}//end class
