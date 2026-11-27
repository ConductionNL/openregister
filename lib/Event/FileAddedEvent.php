<?php

/**
 * OpenRegister FileAddedEvent
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-file-added-event/specs/file-actions/spec.md#requirement-adding-a-file-to-an-object-dispatches-an-event-req-ofae-001
 */

namespace OCA\OpenRegister\Event;

/**
 * Dispatched after a file was added to an object through the files API (create, create from URL, multipart upload).
 *
 * The data carries `fileName`, `register` and `schema`, so a listener can tell
 * whose object it is without loading it. Dispatched after the file is stored;
 * a listener that throws does not undo the add.
 *
 * @spec openspec/changes/object-file-added-event/specs/file-actions/spec.md#requirement-adding-a-file-to-an-object-dispatches-an-event-req-ofae-001
 */
class FileAddedEvent extends AbstractObjectFileEvent {
}//end class
