<?php

/**
 * OpenRegister ContentSearchWidening
 *
 * One request to widen a metadata page with chunk-body-text matches.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * The search query, the metadata page it produced and the caller's organisation.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class ContentSearchWidening {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $query         The original search query (`_search` and register/schema scope).
	 * @param MetadataArmPage      $page          The page the metadata arm served.
	 * @param string|null          $activeOrgUuid The caller's active organisation, forwarded to the overlap probe.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		public readonly array $query,
		public readonly MetadataArmPage $page,
		public readonly ?string $activeOrgUuid=null,
	) {
	}//end __construct()

	/**
	 * The `_search` term, or null when the query carries none or an empty one.
	 *
	 * @return string|null The search term.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function searchTerm(): ?string {
		$term = ($this->query['_search'] ?? null);
		if (is_string($term) === false || trim($term) === '') {
			return null;
		}

		return $term;
	}//end searchTerm()
}//end class
