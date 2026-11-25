<?php

/**
 * SearchCapability — say through `/ocs/v2.php/cloud/capabilities` whether search stems.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Capabilities
 * @package  OCA\OpenRegister\Capabilities
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Capabilities;

use OCA\OpenRegister\Service\Search\TextSearchConfiguration;
use OCP\Capabilities\ICapability;

/**
 * Adds `openregister.search.stemming` beside the scopes SearchScopesCapability lists.
 *
 * Nextcloud merges capabilities recursively, so this key joins the
 * `openregister.search` block without touching its other keys. The value is
 * the text search configuration on PostgreSQL (`"dutch"` by default) and
 * `false` on MySQL and MariaDB, where `_search` stays a substring match.
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
 */
class SearchCapability implements ICapability {
	/**
	 * Constructor.
	 *
	 * @param TextSearchConfiguration $textSearch The search configuration on this database.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TextSearchConfiguration $textSearch,
	) {
	}//end __construct()

	/**
	 * Whether `_search` stems here, and with which configuration.
	 *
	 * @inheritDoc
	 *
	 * @return array{openregister: array{search: array{stemming: string|false}}}
	 *
	 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
	 */
	public function getCapabilities(): array {
		return ['openregister' => ['search' => ['stemming' => $this->textSearch->stemming()]]];
	}//end getCapabilities()
}//end class
