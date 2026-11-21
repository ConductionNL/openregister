<?php
/**
 * SearchBackendSettingsInterface: the search backend settings, as the search settings API asks them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Search;

use InvalidArgumentException;

/**
 * The stored search backend settings behind the search settings endpoint.
 *
 * The search settings API (Search) reads and changes them; Configuration keeps the
 * settings and binds its handler in its own registrar.
 *
 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md#requirement-accent-folding-is-visible-to-administrators-and-can-be-bypassed
 */
interface SearchBackendSettingsInterface {

	/**
	 * The active backend, the available backends and the accent folding state.
	 *
	 * @return array Backend configuration with 'active', 'available' and 'accentInsensitive' keys.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-backend-agnostic-search-architecture
	 */
	public function getSearchBackendConfig(): array;

	/**
	 * Switch accent-insensitive search on or off; switching it on where the database cannot is refused.
	 *
	 * @param bool $enabled Whether object search is to ignore accents.
	 *
	 * @return array{enabled: bool, available: bool, reason: string} The state afterwards.
	 *
	 * @throws InvalidArgumentException When enabling it where it is unavailable, or without app config.
	 *
	 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md#requirement-accent-folding-is-visible-to-administrators-and-can-be-bypassed
	 */
	public function setAccentInsensitive(bool $enabled): array;
}//end interface
