<?php

/**
 * OpenRegister ObjectLeavingEvent
 *
 * This file contains the event class dispatched when an object is about to
 * move out of its register and schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

/**
 * Event dispatched before an object moves out of its register and schema.
 *
 * A move takes the row out of the source table without a delete, so a guard
 * that protects a value other records need at that address listens here as
 * well. It is a deleting event to such a guard, while listeners of the deleting
 * event itself, webhooks among them, do not hear about an object that still
 * exists.
 */
class ObjectLeavingEvent extends ObjectDeletingEvent {
}//end class
