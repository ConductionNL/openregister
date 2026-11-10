<?php

/**
 * OpenRegister operations acting request
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCP\IRequest;
use OCP\IUserSession;

/**
 * The acting administrator and the parameter readers the operations
 * controllers share (console, consistency, maintenance).
 *
 * @spec openspec/specs/operations-console/spec.md
 */
final class OperationsActingRequest {
	/**
	 * Wrap the request and the session.
	 *
	 * @param IRequest     $request     The HTTP request.
	 * @param IUserSession $userSession Names the administrator acting.
	 *
	 * @spec openspec/specs/operations-console/spec.md
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * The uid acting.
	 *
	 * Every write here is an administrator's act and is recorded as theirs, so
	 * an unresolvable session is an empty string the services refuse rather
	 * than a system identity the record would blame.
	 *
	 * @return string The uid, or the empty string.
	 *
	 * @spec openspec/specs/operations-console/spec.md
	 */
	public function actor(): string {
		return (string)($this->userSession->getUser()?->getUID() ?? '');
	}//end actor()

	/**
	 * Read a non-empty string request parameter.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return string|null The value, or null when absent or empty.
	 *
	 * @spec openspec/specs/operations-console/spec.md
	 */
	public function stringParam(string $name): ?string {
		$value = $this->request->getParam($name);

		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end stringParam()

	/**
	 * Read a whole-number request parameter, or nothing.
	 *
	 * Distinct from {@see intParam()}: an absent setting must stay absent so
	 * administering one field does not silently reset the others.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return int|null The value, or null when absent or malformed.
	 *
	 * @spec openspec/specs/operations-console/spec.md
	 */
	public function nullableIntParam(string $name): ?int {
		$value = $this->request->getParam($name);

		if (is_numeric($value) === false) {
			return null;
		}

		return (int)$value;
	}//end nullableIntParam()

	/**
	 * Read a whole-number request parameter, or fall back.
	 *
	 * A parameter that is not a number falls back rather than reading as
	 * zero, because zero is a window this service would then bound to one
	 * hour and report as if it had been asked for.
	 *
	 * @param string $name     The parameter name.
	 * @param int    $fallback The value to use when it is absent or malformed.
	 *
	 * @return int The value.
	 *
	 * @spec openspec/specs/operations-console/spec.md
	 */
	public function intParam(string $name, int $fallback): int {
		return ($this->nullableIntParam(name: $name) ?? $fallback);
	}//end intParam()
}//end class
