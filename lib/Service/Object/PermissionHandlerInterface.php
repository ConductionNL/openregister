<?php

/**
 * PermissionHandlerInterface: the public surface of PermissionHandler, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Every public method of PermissionHandler, as the union of its role interfaces.
 *
 * Callers that need one role type against that role; callers that need several, or pass
 * the handler on, keep depending on this interface.
 */
interface PermissionHandlerInterface extends
	AuthorizationResolutionInterface,
	ObjectReadersInterface,
	PermissionCacheInterface,
	PermissionCheckInterface,
	PermissionEnforcementInterface,
	PermissionExplanationInterface,
	PermissionScopeInterface,
	TransitionAuthorizationInterface
{
}//end interface
