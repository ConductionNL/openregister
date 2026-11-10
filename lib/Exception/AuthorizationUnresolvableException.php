<?php

/**
 * OpenRegister AuthorizationUnresolvableException.
 *
 * Thrown when an authorization resolver cannot determine the effective
 * permissions for a register/schema — the register lookup failed, the
 * mapper was unreachable, or the stored authorization could not be read.
 *
 * This exception exists so that "I could not determine the permissions"
 * is a DISTINCT signal from "no authorization is configured" (`null` /
 * `[]`, which legitimately means open). Collapsing the two into a single
 * nullable return is what produced the CWE-863 fail-open this type
 * replaces: the caller's `empty($authorization)` check treated a resolver
 * error as "no rules configured" and granted full permissions.
 *
 * Callers MUST treat this exception as DENY.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * Raised when effective authorization cannot be resolved. Always means DENY.
 */
class AuthorizationUnresolvableException extends Exception {
}//end class
