<?php

/**
 * OpenRegister ObjectsRouteScope
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


/**
 * The registers and schemas a request to the global objects route names.
 *
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 */
final class ObjectsRouteScope {
	/**
	 * The single register named, by `register` or `_register`.
	 *
	 * @var mixed
	 */
	public readonly mixed $register;

	/**
	 * The single schema named, by `schema` or `_schema`.
	 *
	 * @var mixed
	 */
	public readonly mixed $schema;

	/**
	 * Every register named.
	 *
	 * @var array<int, mixed>
	 */
	public readonly array $registers;

	/**
	 * Every schema named.
	 *
	 * @var array<int, mixed>
	 */
	public readonly array $schemas;

	/**
	 * Read the scope from the request parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function __construct(array $params) {
		$this->register = ($params['register'] ?? $params['_register'] ?? null);
		$this->schema = ($params['schema'] ?? $params['_schema'] ?? null);
		$this->registers = $this->named(many: ($params['registers'] ?? null), one: $this->register);
		$this->schemas = $this->named(many: ($params['schemas'] ?? null), one: $this->schema);
	}//end __construct()

	/**
	 * Whether the request spans more than one table.
	 *
	 * @return bool True for several registers or several schemas.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function spansTables(): bool {
		return count($this->schemas) > 1 || count($this->registers) > 1;
	}//end spansTables()

	/**
	 * Whether the request names one register and one schema.
	 *
	 * @return bool True when both are named.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function namesOneTable(): bool {
		return $this->register !== null && $this->schema !== null;
	}//end namesOneTable()

	/**
	 * The list a parameter names.
	 *
	 * @param mixed $many The plural parameter.
	 * @param mixed $one  The singular parameter.
	 *
	 * @return array<int, mixed> The named values.
	 */
	private function named(mixed $many, mixed $one): array {
		if ($many !== null) {
			return (new ObjectRequestParams())->multiValue(param: $many, defaultValue: ($one ?? ''));
		}

		if ($one !== null) {
			return [$one];
		}

		return [];
	}//end named()
}//end class
