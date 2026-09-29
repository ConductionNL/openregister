<?php

/**
 * OpenRegister InvalidAuthorizationRuleException
 *
 * A schema's authorization block is malformed: a rule without a group, a match
 * operator the evaluators do not know, an `$in` operand that is not a list.
 * Controllers answer it with 400 and its message, which names the action, the
 * property and the operator, so the author knows what to change (#4162).
 *
 * It extends InvalidArgumentException, which every one of these refusals was
 * before, so a caller that catches that keeps working. The schema controller
 * used to read the MESSAGE of a plain exception to guess the status, and a
 * message without one of its words fell through to a bare 500.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/rbac-zaaktype/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use InvalidArgumentException;

/**
 * Thrown when a schema's authorization block is malformed.
 */
class InvalidAuthorizationRuleException extends InvalidArgumentException {

	/**
	 * The HTTP status controllers answer this refusal with.
	 *
	 * @var integer
	 */
	public const HTTP_STATUS = 400;
}//end class
