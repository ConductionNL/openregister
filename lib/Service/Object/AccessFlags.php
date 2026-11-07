<?php

/**
 * OpenRegister AccessFlags
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
 * @spec exclude Value object of the ObjectService facade; access behaviour owned by the handlers it forwards to.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Whether RBAC and multitenancy apply to one object operation.
 */
final class AccessFlags
{
    /**
     * Constructor.
     *
     * @param bool $rbac         Whether to apply RBAC checks.
     * @param bool $multitenancy Whether to apply multitenancy filtering.
     *
     * @spec exclude Value object of the ObjectService facade; access behaviour owned by the handlers it forwards to.
     */
    public function __construct(
        public readonly bool $rbac,
        public readonly bool $multitenancy,
    ) {
    }//end __construct()
}//end class
