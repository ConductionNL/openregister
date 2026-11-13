<?php

/**
 * SourceFetcherLookupInterface: what callers outside the Service component ask of SourceFetcherRegistry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
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

namespace OCA\OpenRegister\Service\Sync;

/**
 * Looking up the fetcher for a source type, as the sync job does, and listing the types, as the sources API does.
 */
interface SourceFetcherLookupInterface {

	/**
	 * Get a fetcher for the given source type.
	 *
	 * @param string $type The source type
	 *
	 * @return SourceFetcherInterface|null The first supporting fetcher, or null
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function get(string $type): ?SourceFetcherInterface;

	/**
	 * Every registered source type, for a source form's type list.
	 *
	 * @return list<array{type: string, displayName: string, application: string, configSchema: array<string, mixed>}>
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function types(): array;
}//end interface
