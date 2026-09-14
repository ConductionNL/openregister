<?php

/**
 * OpenRegister AuthorizationBlockException
 *
 * A refusal to STORE an authorization block, as opposed to a refusal to act
 * under one. It carries HTTP 422: the request was understood and well formed,
 * and the rules inside it contradict each other.
 *
 * It exists as its own type because the alternative is what the schema and
 * register controllers do today — read the exception MESSAGE and guess the
 * status from the words in it. A rule that contradicts another is not a bad
 * request, and it is certainly not a 500, and neither status tells the author
 * which two rules to look at.
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
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * Thrown when an authorization block cannot be stored as written.
 */
class AuthorizationBlockException extends Exception {

	/**
	 * The HTTP status a controller should answer with.
	 *
	 * @var integer
	 */
	private const HTTP_UNPROCESSABLE_ENTITY = 422;

	/**
	 * The status this refusal maps to.
	 *
	 * @return integer Always 422.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function getHttpStatusCode(): int {
		return self::HTTP_UNPROCESSABLE_ENTITY;
	}//end getHttpStatusCode()
}//end class
