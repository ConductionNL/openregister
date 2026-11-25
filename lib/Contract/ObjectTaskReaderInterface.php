<?php

/**
 * ObjectTaskReaderInterface: the published read of the tasks on one object.
 *
 * An app that keeps approval steps or other work on OpenRegister's task store
 * (an approval chain on a purchase order, for one) needs who decided each
 * step, on whose behalf and under which mandate, for its own audit export.
 * It cannot reach into the task store, so the read is published here, next to
 * {@see ObjectServiceInterface}, and owned by the app that implements it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Contract;

/**
 * Reads the tasks on one object.
 *
 * @spec openspec/changes/object-task-reader/specs/flow-tasks/spec.md#requirement-a-consuming-app-reads-the-tasks-on-its-object
 */
interface ObjectTaskReaderInterface {

	/**
	 * The tasks on an object, open and finished, oldest first.
	 *
	 * The caller checks that its user may see the object before it asks: the
	 * read itself is by object uuid and applies no task rights, like
	 * {@see ObjectFileReaderInterface::getObjectFiles()}. An object without
	 * tasks answers an empty list.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return array<int,ObjectTaskRecord> The tasks.
	 *
	 * @spec openspec/changes/object-task-reader/specs/flow-tasks/spec.md#requirement-a-consuming-app-reads-the-tasks-on-its-object
	 */
	public function tasksOnObject(string $objectUuid): array;
}//end interface
