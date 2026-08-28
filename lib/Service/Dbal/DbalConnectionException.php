<?php

/**
 * DbalConnectionException — typed failure raised by the DBAL connection factory
 * when a virtual-register connection cannot be opened.
 *
 * The factory FAILS CLOSED: an unresolvable credential, an unsupported driver, a
 * missing driver extension, or a DBAL-level connection error surfaces as this
 * exception rather than an unauthenticated or partially-configured connection.
 * The message NEVER contains a secret value (design D2 / spec "fails closed").
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dbal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dbal;

use RuntimeException;

/**
 * Raised when a DBAL virtual-register connection cannot be established.
 */
class DbalConnectionException extends RuntimeException {
}//end class
