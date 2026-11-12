<?php

/**
 * CacheHandlerInterface: the public surface of CacheHandler, for callers to type against.
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

use OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface;

/**
 * Every public method of CacheHandler, as the union of its role interfaces.
 *
 * Callers that need one role type against that role; callers that need several keep
 * depending on this interface.
 */
interface CacheHandlerInterface extends
	ObjectCacheInvalidationInterface,
	ObjectCacheMaintenanceInterface,
	ObjectEntityCacheInterface,
	ObjectNameCacheInterface,
	ObjectNameWriterInterface
{
}//end interface
