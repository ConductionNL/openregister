<?php

/**
 * SemanticProvidersInterface: the semantic providers the current user can see.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Kernel
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/cross-app-semantic-references/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The installed schemas that provide a semantic type, as the integrations
 * capability advertises them. Implemented in Core by the cached catalogue,
 * so a Platform caller names no Core class.
 *
 * @spec openspec/changes/cross-app-semantic-references/tasks.md#task-4.1
 */
interface SemanticProvidersInterface {

	/**
	 * The providers the current user can see.
	 *
	 * @return list<array<string, mixed>> One row per (semantic type, schema), ordered by uri then schema slug.
	 *
	 * @spec openspec/changes/cross-app-semantic-references/tasks.md#task-4.1
	 */
	public function list(): array;
}//end interface
