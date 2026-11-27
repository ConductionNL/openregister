<?php

/**
 * ReindexSelectionServiceInterface: start a reindex of a selection, and run it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

/**
 * The reindex an administrator starts from the operations console.
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md#requirement-an-administrator-reindexes-a-selection-and-watches-it-run-req-sqf-007
 */
interface ReindexSelectionServiceInterface {

	/**
	 * The most objects one selection may reindex; a larger one is a whole-index rebuild.
	 *
	 * @var integer
	 */
	public const MAX_OBJECTS = 50000;

	/**
	 * Resolve the selection, refuse it when it is empty or too large, and queue its run.
	 *
	 * @param array<string, mixed> $params The request parameters: `register`, `schema`, `filters`, `ids`.
	 * @param string               $actor  The administrator starting it.
	 *
	 * @return array{run: string, kind: string, total: int} The queued run's key, the selection kind and the objects it holds.
	 *
	 * @throws ReindexRefusedException When the request is not exactly one selection, selects nothing or selects too much.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md#requirement-an-administrator-reindexes-a-selection-and-watches-it-run-req-sqf-007
	 */
	public function start(array $params, string $actor): array;

	/**
	 * Reindex the selection a queued run stored, as the administrator who started it.
	 *
	 * @param string   $runKey     The key start() returned.
	 * @param string   $actor      The administrator who started it.
	 * @param callable $onProgress Called with the progress details at the start, every 100 objects and at the end.
	 *
	 * @return ReindexProgress The counts at the end.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md#requirement-an-administrator-reindexes-a-selection-and-watches-it-run-req-sqf-007
	 */
	public function run(string $runKey, string $actor, callable $onProgress): ReindexProgress;
}//end interface
