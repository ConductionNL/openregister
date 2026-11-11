<?php

/**
 * OpenRegister presentation check
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
 * A presentation config being validated against the query of the view that carries it.
 *
 * Built and used only inside ViewService.
 *
 * @spec openspec/specs/saved-search-views/spec.md#requirement-views-persist-a-validated-presentation-config-req-view-pres-01
 */
final class PresentationCheck {
	/**
	 * Start a check.
	 *
	 * @param array                $presentation The presentation config.
	 * @param array<string, mixed> $query        The view's query (holds `schemas`).
	 */
	public function __construct(
		public readonly array $presentation,
		public readonly array $query,
	) {
	}//end __construct()

	/**
	 * The value the presentation declares at a dotted path such as `kanban.groupByField`.
	 *
	 * @param string $path Section and key, separated by a dot.
	 *
	 * @return mixed The declared value, or null when absent.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md#requirement-views-persist-a-validated-presentation-config-req-view-pres-01
	 */
	public function declared(string $path): mixed {
		[$section, $key] = explode('.', $path, 2);
		return $this->presentation[$section][$key] ?? null;
	}//end declared()

	/**
	 * The first schema the view's query references, or null when none is configured.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/saved-search-views/spec.md#requirement-views-persist-a-validated-presentation-config-req-view-pres-01
	 */
	public function schemaRef(): mixed {
		return $this->query['schemas'][0] ?? null;
	}//end schemaRef()
}//end class
