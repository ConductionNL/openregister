<?php
/**
 * AdminQueryRunnerInterface: runs an administrator's read-only GraphQL query under the console's caps.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The admin query console's runner, for the controller outside the search area.
 *
 * Bound to \OCA\OpenRegister\Service\GraphQL\AdminQueryRunner.
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 */
interface AdminQueryRunnerInterface {

	/**
	 * Run a read-only query under the row cap and the deadline.
	 *
	 * @param string                    $query         The GraphQL document.
	 * @param array<string, mixed>|null $variables     Its variables.
	 * @param string|null               $operationName The operation to run.
	 *
	 * @return array<string, mixed> The execution result, with `extensions.rows`, `extensions.truncated` and `extensions.durationMs`.
	 *
	 * @throws \OCA\OpenRegister\Exception\ReadOnlyQueryException When the document holds a mutation or subscription, or does not parse.
	 */
	public function run(string $query, ?array $variables = null, ?string $operationName = null): array;
}//end interface
