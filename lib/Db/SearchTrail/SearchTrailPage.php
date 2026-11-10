<?php

/**
 * SearchTrailPage: the order and the window of one search trail listing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SearchTrail
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SearchTrail;

/**
 * The order and the window of one listing. A null limit or offset is not applied.
 *
 * @spec openspec/specs/search-trail-recording/spec.md
 */
final class SearchTrailPage {

	/**
	 * Constructor.
	 *
	 * @param int|null             $limit  Maximum rows, or null for no limit.
	 * @param int|null             $offset Rows to skip, or null for none.
	 * @param array<string, mixed> $sort   Field to direction; empty for newest first.
	 */
	public function __construct(
		public readonly ?int $limit = null,
		public readonly ?int $offset = null,
		public readonly array $sort = [],
	) {
	}//end __construct()
}//end class
