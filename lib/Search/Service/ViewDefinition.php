<?php

/**
 * OpenRegister view definition
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Search\Service;

/**
 * What a saved view is: its name and description, the search it saves and how it presents the results.
 *
 * Not strict on purpose: the views controller fills it from the request body, as it filled
 * ViewService::create()'s parameters before.
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */
final readonly class ViewDefinition {
	/**
	 * Define a view.
	 *
	 * @param string               $name         The name of the view.
	 * @param string               $description  The description of the view.
	 * @param array<string, mixed> $query        The query parameters (registers, schemas, filters).
	 * @param array|null           $presentation Presentation config (viewType + kanban/calendar config); null = table.
	 */
	public function __construct(
		public string $name,
		public string $description,
		public array $query,
		public ?array $presentation=null,
	) {
	}//end __construct()
}//end class
