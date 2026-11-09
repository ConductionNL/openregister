<?php

/**
 * RegisterObjectEntityInterface: the OpenRegister object as a Flow entity.
 *
 * Nextcloud Flow identifies an entity by its class name (IEntity id), and the
 * registration listener resolves the entity from the container by that id.
 * ENTITY_ID carries it, so code outside the WorkflowEngine component names
 * only this interface and never the concrete RegisterObjectEntity.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category WorkflowEngine
 * @package  OCA\OpenRegister\WorkflowEngine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\WorkflowEngine;

use OCP\WorkflowEngine\IEntity;

/**
 * The OpenRegister object entity of the Nextcloud Flow rule builder.
 */
interface RegisterObjectEntityInterface extends IEntity {

	/**
	 * The Flow entity id, which is also the container service id.
	 */
	public const ENTITY_ID = RegisterObjectEntity::class;
}//end interface
