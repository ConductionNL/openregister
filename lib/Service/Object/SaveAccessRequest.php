<?php

/**
 * OpenRegister SaveAccessRequest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec exclude Value object of the ObjectService save checks; access behaviour owned by PermissionHandler.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * What one save asks permission for.
 */
final class SaveAccessRequest
{
    /**
     * Constructor.
     *
     * @param string|null  $uuid         The object uuid, null for a create without one.
     * @param bool         $rbac         Whether to apply RBAC checks.
     * @param ObjectEntity $incoming     The incoming data, for create rules that carry a match.
     * @param bool         $multitenancy Whether to apply multitenancy filtering.
     *
     * @spec exclude Value object of the ObjectService save checks; access behaviour owned by PermissionHandler.
     */
    public function __construct(
        public readonly ?string $uuid,
        public readonly bool $rbac,
        public readonly ObjectEntity $incoming,
        public readonly bool $multitenancy,
    ) {
    }//end __construct()
}//end class
