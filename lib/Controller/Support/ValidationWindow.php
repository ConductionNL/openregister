<?php

/**
 * OpenRegister validation window
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

/**
 * The register, schema and chunk one bulk validation request asks for.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class ValidationWindow {

	/**
	 * The register, as sent.
	 *
	 * @var mixed
	 */
	public readonly mixed $register;

	/**
	 * The schema, as sent.
	 *
	 * @var mixed
	 */
	public readonly mixed $schema;

	/**
	 * The number of objects to validate, or null for all.
	 *
	 * @var integer|null
	 */
	public readonly ?int $limit;

	/**
	 * The number of objects to skip.
	 *
	 * @var integer
	 */
	public readonly int $offset;

	/**
	 * Read the window from a request.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return void
	 */
	public function __construct(IRequest $request) {
		$this->register = $request->getParam(key: 'register');
		$this->schema   = $request->getParam(key: 'schema');
		$limit          = $request->getParam(key: 'limit');
		$this->offset   = (int)($request->getParam(key: 'offset') ?? 0);
		$this->limit    = $this->optionalInt(value: $limit);
	}//end __construct()

	/**
	 * A value as an integer, keeping null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int|null The integer, or null.
	 */
	private function optionalInt(mixed $value): ?int {
		if ($value === null) {
			return null;
		}

		return (int)$value;
	}//end optionalInt()

	/**
	 * Whether the request names a register and a schema.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function isComplete(): bool {
		return $this->register !== null && $this->schema !== null;
	}//end isComplete()
}//end class
