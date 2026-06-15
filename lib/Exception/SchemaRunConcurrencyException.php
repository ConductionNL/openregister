<?php

/**
 * SchemaRunConcurrencyException.
 *
 * Thrown when a second revalidation/migration run is requested for a schema
 * that already has an active run; the controller maps it to HTTP 409.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * Signals a concurrent-run conflict on a schema.
 */
class SchemaRunConcurrencyException extends RuntimeException
{

}//end class
