<?php
/**
 * FollowTableInterface: the table that records which user follows which object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * The follow table's name, owned where the object query's personal lens joins on it.
 *
 * The object store (Core) narrows a list to the objects a user follows by joining
 * this table. Notifications keeps the rows and defines its mapper's table as this
 * name, so the name is written once. It holds only a constant; nothing is bound.
 *
 * @spec openspec/specs/object-interactions/spec.md#requirement-favourites-and-recent-are-lenses-on-the-object-query
 */
interface FollowTableInterface {

	/**
	 * The follow table, named once for the query lenses that join on it.
	 *
	 * @var string
	 */
	public const TABLE = 'openregister_watchers';
}//end interface
