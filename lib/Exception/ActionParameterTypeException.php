<?php

/**
 * A route action parameter has a type its action cannot take.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec exclude ADR-011 request reading of route actions, not a capability of its own
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use InvalidArgumentException;

/**
 * A route action parameter has a type its action cannot take.
 *
 * The action answers it with a 400, the status Nextcloud gives an
 * argument it cannot bind.
 *
 * @spec exclude ADR-011 request reading of route actions, not a capability of its own
 */
class ActionParameterTypeException extends InvalidArgumentException {
}//end class
