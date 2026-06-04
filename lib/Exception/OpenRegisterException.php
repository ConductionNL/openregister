<?php

/**
 * OpenRegister Base Exception
 *
 * Base exception class for the OpenRegister application.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/register-resolver-service/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * Base exception class for all OpenRegister domain exceptions.
 *
 * All application-specific exceptions extend this class so callers can
 * catch OCA\OpenRegister\Exception\OpenRegisterException to handle any
 * OR-domain error without grepping message strings.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */
class OpenRegisterException extends Exception
{
}//end class
