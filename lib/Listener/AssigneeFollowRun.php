<?php

/**
 * OpenRegister AssigneeFollowRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-interactions/spec.md#requirement-being-assigned-an-object-follows-it-with-notifications-on
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * One save being checked for a new assignee to follow it.
 *
 * Built by {@see AssigneeFollowListener} once the schema resolved; it never
 * leaves that class (ADR-011 Rule 3, modelled on CascadeRun).
 *
 * @spec openspec/specs/object-interactions/spec.md#requirement-being-assigned-an-object-follows-it-with-notifications-on
 */
final class AssigneeFollowRun {

	/**
	 * The object's schema.
	 *
	 * @var Schema|null
	 */
	public ?Schema $schema = null;

	/**
	 * The object as saved.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $object = null;

	/**
	 * The object before the save, on an update.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $old = null;
}//end class
