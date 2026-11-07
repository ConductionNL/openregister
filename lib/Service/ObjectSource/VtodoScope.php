<?php

/**
 * VtodoScope: which VTODOs a sourced schema surfaces.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
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
 *
 * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

/**
 * The register/schema link filter of a VTODO-sourced schema.
 *
 * By default a sourced schema only surfaces VTODOs whose X-OPENREGISTER link
 * points at the bound register AND schema, so each projection shows only its
 * own tasks (not every VTODO the user owns). Config escape hatches:
 *  - `unscoped: true` — surface all of the user's VTODOs (no link filter);
 *  - `register` (int) / `schema` (int) — scope to a different register/schema
 *    than the bound one;
 *  - `schemas` (int[]) — scope to VTODOs linked to ANY of these schema ids.
 *
 * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
 */
final class VtodoScope {

	/**
	 * Whether every VTODO is in scope.
	 *
	 * @var bool
	 */
	private readonly bool $unscoped;

	/**
	 * The register a VTODO must link to.
	 *
	 * @var int
	 */
	private readonly int $registerId;

	/**
	 * The schemas a VTODO may link to.
	 *
	 * @var list<int>
	 */
	private readonly array $schemaIds;

	/**
	 * The scope of a binding under its object-source config block.
	 *
	 * @param SourcedObjectBinding $binding The bound register and schema.
	 * @param array<string, mixed> $config  The object-source config block.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
	 */
	public function __construct(SourcedObjectBinding $binding, array $config) {
		$this->unscoped   = ($config['unscoped'] ?? false) === true;
		$this->registerId = (int)($config['register'] ?? $binding->register->getId());
		$this->schemaIds  = self::schemaIds(binding: $binding, config: $config);
	}//end __construct()

	/**
	 * Whether a VTODO is in scope.
	 *
	 * @param array<string, mixed> $task The VTODO task array.
	 *
	 * @return bool True when the task is in scope.
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
	 */
	public function admits(array $task): bool {
		if ($this->unscoped === true) {
			return true;
		}

		return (int)($task['registerId'] ?? 0) === $this->registerId
			&& in_array((int)($task['schemaId'] ?? 0), $this->schemaIds, true);
	}//end admits()

	/**
	 * The configured `schemas` list, or else the single `schema` / bound schema.
	 *
	 * @param SourcedObjectBinding $binding The bound register and schema.
	 * @param array<string, mixed> $config  The object-source config block.
	 *
	 * @return list<int> The schema ids.
	 */
	private static function schemaIds(SourcedObjectBinding $binding, array $config): array {
		$schemas = $config['schemas'] ?? null;
		if (is_array($schemas) === false || empty($schemas) === true) {
			$schemas = [$config['schema'] ?? $binding->schema->getId()];
		}

		return array_values(array_map('intval', $schemas));
	}//end schemaIds()
}//end class
