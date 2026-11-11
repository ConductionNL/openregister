<?php

/**
 * OpenRegister view patch
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Search\Controller\Support;

use OCA\OpenRegister\Search\Db\View;

/**
 * One write of a saved view: the route id, the stored view and the request body,
 * with what the body declares.
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */
final class ViewPatch {
	/**
	 * The view id from the route.
	 *
	 * @var string
	 */
	public string $id = '';

	/**
	 * The stored view.
	 *
	 * @var View|null
	 */
	public ?View $view = null;

	/**
	 * The request parameters.
	 *
	 * @var array
	 */
	public array $data = [];

	/**
	 * The view query the request body declares.
	 *
	 * The frontend still sends `configuration`, of which only the query keys are kept;
	 * otherwise a `query` array is taken as is.
	 *
	 * @return array<string, mixed>|null The query, or null when the body declares none.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function query(): ?array {
		$config = $this->data['configuration'] ?? null;
		if (is_array($config) === true) {
			return self::configurationQuery(config: $config);
		}

		$query = $this->data['query'] ?? null;
		if (is_array($query) === true) {
			return $query;
		}

		return null;
	}//end query()

	/**
	 * The query keys of a legacy `configuration` body, with their defaults.
	 *
	 * @param array<string, mixed> $config The `configuration` the frontend sent.
	 *
	 * @return array<string, mixed> registers, schemas, source, searchTerms, facetFilters and enabledFacets.
	 */
	private static function configurationQuery(array $config): array {
		return [
			'registers' => $config['registers'] ?? [],
			'schemas' => $config['schemas'] ?? [],
			'source' => $config['source'] ?? 'auto',
			'searchTerms' => $config['searchTerms'] ?? [],
			'facetFilters' => $config['facetFilters'] ?? [],
			'enabledFacets' => $config['enabledFacets'] ?? [],
		];
	}//end configurationQuery()


	/**
	 * The presentation the request body declares, when it is an array.
	 *
	 * @return array<string, mixed>|null The presentation, or null.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function presentation(): ?array {
		$presentation = $this->data['presentation'] ?? null;
		if (is_array($presentation) === false) {
			return null;
		}

		return $presentation;
	}//end presentation()


	/**
	 * The share list from the request body, or null when it does not mention sharing.
	 *
	 * Read after the controller has checked the shares.
	 *
	 * @return array|null
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function shares(): ?array {
		if (array_key_exists('sharedWith', $this->data) === false || $this->data['sharedWith'] === null) {
			return null;
		}

		return $this->data['sharedWith'];
	}//end shares()


	/**
	 * The error for a create or full update that lacks its name or its query.
	 *
	 * @return string|null The error, or null when both are present.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function missingField(): ?string {
		if (isset($this->data['name']) === false || empty($this->data['name']) === true) {
			return 'View name is required';
		}

		if ($this->query() === null) {
			return 'View query or configuration is required';
		}

		return null;
	}//end missingField()
}//end class
