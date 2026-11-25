<?php

/**
 * UnavailableServiceExceptionInterface: a failure every API area answers with 503.
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

use Throwable;

/**
 * Marks a failure that means a service the request needs is not available
 * (ADR-049 fail-closed), at the Kernel level.
 *
 * The shared controller exception mapping (HandlesExceptionsTrait) is used by
 * controllers of every area, so it matches this Kernel marker rather than an
 * area's own; Kernel\Contracts\Core\ServiceUnavailableExceptionInterface
 * extends it, so its implementors keep answering 503.
 *
 * @spec openspec/changes/apphost-settings-plane/specs/apphost-settings-plane/spec.md
 */
interface UnavailableServiceExceptionInterface extends Throwable {
}//end interface
