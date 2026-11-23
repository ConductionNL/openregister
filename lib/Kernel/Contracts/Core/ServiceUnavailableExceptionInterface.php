<?php

/**
 * ServiceUnavailableExceptionInterface: a failure an API answers with 503.
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

use OCA\OpenRegister\Kernel\Contracts\UnavailableServiceExceptionInterface;

/**
 * Marks a failure that means a service the request needs is not available:
 * the foundation is missing or its configuration is empty (ADR-049 fail-closed).
 *
 * The controllers' exception mapping (Core) answers it with 503; the areas whose
 * services can be unavailable implement it on their own exceptions.
 *
 * @spec openspec/changes/apphost-settings-plane/specs/apphost-settings-plane/spec.md
 */
interface ServiceUnavailableExceptionInterface extends UnavailableServiceExceptionInterface {
}//end interface
