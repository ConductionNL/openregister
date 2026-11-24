<?php
/**
 * ReadableFileHitsInterface: the search hits on files the caller may read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * Which file hits of a search the caller may read.
 *
 * Search results filtering (Core) drops a file hit the caller cannot open; Files keeps
 * the file read rules and binds its answer in its own wiring.
 *
 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md
 */
interface ReadableFileHitsInterface {

	/**
	 * Keep only the file hits the caller may read, in their original order.
	 *
	 * @param array<int, array<string, mixed>> $results Hits carrying `entity_type` and `entity_id`.
	 *
	 * @return array<int, array<string, mixed>> The readable hits.
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md
	 */
	public function readableResults(array $results): array;
}//end interface
