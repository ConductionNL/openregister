<?php

/**
 * TablesCatalogueInterface: what callers outside the Service component use of TablesTableReader.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

/**
 * Says whether Tables can be read and lists the tables to reconcile.
 *
 * @spec openspec/specs/tables-virtual-register/spec.md
 */
interface TablesCatalogueInterface {

	/**
	 * Whether the Tables app is enabled and its service classes are loadable.
	 *
	 * @return bool True when Tables reads can be served.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function isAvailable(): bool;

	/**
	 * Collect the descriptors of every table the given users can see.
	 *
	 * @param array<int, string> $userIds The acting user ids to enumerate for.
	 *
	 * @return array<int, array{id: int, title: string, columns: array<int, array<string, mixed>>}> The descriptors.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function collectTableDescriptors(array $userIds): array;
}//end interface
