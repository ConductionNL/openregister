<?php

/**
 * The database connection and event dispatcher the agent mapper writes through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mapper
 * @package  OCA\OpenRegister\Mcp\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Db;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;

/**
 * The database connection and event dispatcher of the agent mapper.
 *
 * The access collaborators (organisation mapper, user session, group manager) are
 * not in this bundle: they stay direct constructor parameters of the mapper.
 *
 * @spec exclude Container wiring for AgentMapper; no behaviour of its own.
 */
final class AgentMapperPlatform {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IDBConnection    $db              Database connection.
	 * @param IEventDispatcher $eventDispatcher Dispatches the agent created, updated and deleted events.
	 *
	 * @spec exclude Container wiring for AgentMapper; no behaviour of its own.
	 */
	public function __construct(
		public readonly IDBConnection $db,
		public readonly IEventDispatcher $eventDispatcher,
	) {
	}//end __construct()
}//end class
