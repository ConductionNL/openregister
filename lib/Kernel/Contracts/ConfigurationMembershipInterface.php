<?php
/**
 * ConfigurationMembershipInterface: which entities a configuration lists as its own.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The member lists of a configuration, as the entities it manages read them.
 *
 * Implemented by \OCA\OpenRegister\Db\Configuration. The entities answer
 * getManagedByConfiguration() through this read, so they name no
 * Configuration class.
 */
interface ConfigurationMembershipInterface {

	/**
	 * Whether the member list of one kind holds an id, compared strictly.
	 *
	 * @param string     $kind The member list: objects, schemas, registers, views, agents, sources or applications.
	 * @param int|string $id   The entity id.
	 *
	 * @return bool True when the list holds the id; false for an empty or unknown list.
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	public function listsMember(string $kind, int|string $id): bool;
}//end interface
