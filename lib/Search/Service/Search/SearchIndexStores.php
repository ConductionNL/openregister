<?php

/**
 * OpenRegister search index stores
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Search
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

namespace OCA\OpenRegister\Search\Service\Search;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCP\IDBConnection;

/**
 * The stores the search index maintenance reads and rebuilds.
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */
final class SearchIndexStores {
	/**
	 * Bundle the services.
	 *
	 * @param IDBConnection           $db             Database connection.
	 * @param RegisterMapperInterface $registerMapper Register mapper.
	 * @param MagicMapperInterface    $magicMapper    Magic table mapper.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		public readonly IDBConnection $db,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly MagicMapperInterface $magicMapper,
	) {
	}//end __construct()
}//end class
