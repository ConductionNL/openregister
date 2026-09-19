<?php

/**
 * OpenRegister TaskSubjectNotFoundException
 *
 * Raised when the object a task names is not there FOR THIS CALLER: either
 * it does not exist, or the caller may not read it. The two cases share one
 * exception on purpose, because they share one answer.
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
 * @spec openspec/changes/flow-task-subject-authorization/specs/flow-tasks/spec.md#requirement-a-task-may-only-be-created-on-an-object-its-creator-may-read
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * The subject object a task names is absent or unreadable for the caller.
 *
 * Distinct from {@see TaskAccessDeniedException}, which is about the TASK and
 * answers 403 on the verbs. This one is about the OBJECT, and answers 404
 * with the same words `GET /api/objects/.../{id}` answers that same caller,
 * so creating a task cannot become the existence oracle the read refused to
 * be. A 403 here would be that oracle: it would separate "this object is not
 * yours" from "this object is not there".
 *
 * @spec openspec/changes/flow-task-subject-authorization/specs/flow-tasks/spec.md#requirement-a-task-may-only-be-created-on-an-object-its-creator-may-read
 */
class TaskSubjectNotFoundException extends RuntimeException {
}//end class
