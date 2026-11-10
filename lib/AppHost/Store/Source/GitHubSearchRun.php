<?php

/**
 * OpenRegister AppHost — GitHub Search Run
 *
 * The state one GitHub topic search shares across its per-topic requests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Store
 * @package  OCA\OpenRegister\AppHost\Store\Source
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

namespace OCA\OpenRegister\AppHost\Store\Source;

use OCP\Http\Client\IClient;

/**
 * One search across a store's topics: the client and the free-text query.
 *
 * Built by GitHubStoreSource for one search and never handed out of it.
 */
final class GitHubSearchRun {
	/**
	 * Constructor.
	 *
	 * @param IClient $client The HTTP client every topic request uses.
	 * @param string  $query  The free-text search, possibly empty.
	 */
	public function __construct(
		public readonly IClient $client,
		public readonly string $query,
	) {
	}//end __construct()

	/**
	 * The GitHub search term for one topic.
	 *
	 * @param string $topic The topic searched.
	 *
	 * @return string The `q` parameter.
	 */
	public function termFor(string $topic): string {
		if ($this->query === '') {
			return 'topic:' . $topic;
		}

		return 'topic:' . $topic . ' ' . $this->query;
	}//end termFor()
}//end class
