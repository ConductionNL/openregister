<?php

/**
 * OpenRegister Object Search Result Formatter
 *
 * Shared logic behind every OpenRegister unified-search result: icon
 * precedence, deep-link URL resolution, and subline/excerpt building. Both
 * the generic `ObjectsProvider` and the schema-scoped
 * `AbstractSchemaSearchProvider` delegate to this service so one result row
 * is formatted exactly the same way regardless of which provider found it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCP\IURLGenerator;
use OCP\Search\SearchResultEntry;

/**
 * Builds a `SearchResultEntry` for one OpenRegister object search result row.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
final class ObjectSearchResultFormatter {

	/**
	 * Number of characters of context shown on each side of an excerpt match.
	 *
	 * @var int
	 */
	private const EXCERPT_CONTEXT = 60;

	/**
	 * The URL generator service
	 *
	 * @var IURLGenerator
	 */
	private readonly IURLGenerator $urlGenerator;

	/**
	 * Deep link registry for resolving URLs to consuming apps
	 *
	 * @var DeepLinkRegistryService
	 */
	private readonly DeepLinkRegistryService $deepLinkRegistry;

	/**
	 * Shared preview-formatting service (schema icon/name resolution).
	 *
	 * @var ObjectPreviewFormatter
	 */
	private readonly ObjectPreviewFormatter $formatter;

	/**
	 * Constructor for ObjectSearchResultFormatter.
	 *
	 * @param IURLGenerator $urlGenerator The URL generator service
	 * @param DeepLinkRegistryService $deepLinkRegistry Deep link registry for URL resolution
	 * @param ObjectPreviewFormatter $formatter Shared schema icon/name resolution service
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d1
	 */
	public function __construct(
		IURLGenerator $urlGenerator,
		DeepLinkRegistryService $deepLinkRegistry,
		ObjectPreviewFormatter $formatter,
	) {
		$this->urlGenerator = $urlGenerator;
		$this->deepLinkRegistry = $deepLinkRegistry;
		$this->formatter = $formatter;
	}//end __construct()

	/**
	 * Build a `SearchResultEntry` for one rendered search result row.
	 *
	 * @param array|ObjectEntity $result The rendered object data (already RBAC-filtered),
	 *                                   or the entity itself (normalised internally)
	 * @param string|null $term The search term (null/empty for filter-only browse)
	 *
	 * @return SearchResultEntry The formatted result entry
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	public function format(array|ObjectEntity $result, ?string $term): SearchResultEntry {
		if ($result instanceof ObjectEntity) {
			$result = $result->jsonSerialize();
		}

		// Extract metadata from @self (jsonSerialize puts metadata there).
		$selfData = $result['@self'] ?? [];
		$registerId = (int)($selfData['register'] ?? $result['register'] ?? 0);
		$schemaId = (int)($selfData['schema'] ?? $result['schema'] ?? 0);
		$uuid = $selfData['id'] ?? $result['id'] ?? '';

		// Build a flat data array for deep link URL resolution. The
		// resolveUrl method needs {uuid} and other top-level keys.
		$selfArray = [];
		if (is_array($selfData) === true) {
			$selfArray = $selfData;
		}

		$flatData = array_merge(
			$selfArray,
			['uuid' => $uuid, 'register' => $registerId, 'schema' => $schemaId]
		);

		// Try deep link registry first, fall back to OpenRegister's own route.
		$objectUrl = $this->deepLinkRegistry->resolveUrl(
			registerId: $registerId,
			schemaId: $schemaId,
			objectData: $flatData
		);
		if ($objectUrl === null) {
			$objectUrl = $this->urlGenerator->linkToRoute(
				'openregister.objects.show',
				['register' => $registerId, 'schema' => $schemaId, 'id' => $uuid]
			);
		}

		// Resolve the per-app label for this (register, schema) pair.
		$appLabel = $this->deepLinkRegistry->resolveDisplayName(
			registerId: $registerId,
			schemaId: $schemaId
		);

		[$thumbnailUrl, $icon, $rounded] = $this->resolveIcon(schemaId: $schemaId, appLabel: $appLabel, registerId: $registerId);

		// Create descriptive title and subline.
		$name = $selfData['name'] ?? '';

		$title = 'Unknown Object';
		if (isset($result['title']) === true && is_string($result['title']) === true) {
			$title = $result['title'];
		} elseif (is_string($name) === true && $name !== '') {
			$title = $name;
		} elseif ($uuid !== '') {
			$title = (string)$uuid;
		}

		$subline = $this->buildSubline(
			object: $result,
			registerId: $registerId,
			schemaId: $schemaId,
			appLabel: $appLabel,
			term: $term
		);

		return new SearchResultEntry(
			$thumbnailUrl,
			$title,
			$subline,
			$objectUrl,
			$icon,
			$rounded
		);
	}//end format()

	/**
	 * Icon precedence:
	 * 1. the schema's own MDI icon (an explicit, per-schema choice by the
	 *    app author), rendered as a self-hosted data: SVG so it renders in
	 *    the search dropdown and passes the image CSP;
	 * 2. the consuming app's registered (rounded) icon;
	 * 3. the generic OpenRegister icon class.
	 * The rounded avatar style only applies to the registered app icon — a
	 * schema glyph is a square monochrome icon. The schema glyph is served
	 * from the icon endpoint as a real same-origin SVG URL and passed as
	 * the THUMBNAIL, because Nextcloud search only paints a thumbnail from
	 * a URL — an icon-class name or a data: URI is not rendered as an image.
	 *
	 * @param int $schemaId The schema database ID
	 * @param string|null $appLabel The owning app's display name, or null
	 * @param int $registerId The register database ID
	 *
	 * @return array{0: string, 1: string, 2: bool} [thumbnailUrl, icon, rounded]
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	private function resolveIcon(int $schemaId, ?string $appLabel, int $registerId): array {
		$mdiIconUrl = $this->formatter->resolveMdiIconUrl(schemaId: $schemaId);
		if ($mdiIconUrl !== null) {
			return [$mdiIconUrl, 'icon-openregister', false];
		}

		$icon = $this->deepLinkRegistry->resolveIcon(
			registerId: $registerId,
			schemaId: $schemaId
		) ?? 'icon-openregister';
		$rounded = ($appLabel !== null);

		return ['', $icon, $rounded];
	}//end resolveIcon()

	/**
	 * Build the result subline: `{Owner} · {Register} · {Schema} — {excerpt}`.
	 *
	 * The owner label is the deep-link display name for claimed pairs, or
	 * `Open Register` for unclaimed pairs. The excerpt is appended when a
	 * term-driven match (or fallback summary/description) is available.
	 *
	 * @param array $object The rendered object data.
	 * @param int $registerId The register database ID.
	 * @param int $schemaId The schema database ID.
	 * @param string|null $appLabel The owning app's display name, or null.
	 * @param string|null $term The search term, or null for filter-only.
	 *
	 * @return string The composed subline.
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	private function buildSubline(
		array $object,
		int $registerId,
		int $schemaId,
		?string $appLabel,
		?string $term,
	): string {
		$owner = 'Open Register';
		if ($appLabel !== null && $appLabel !== '') {
			$owner = $appLabel;
		}

		$parts = [$owner];
		if ($registerId > 0) {
			$parts[] = $this->formatter->resolveRegisterName(registerId: $registerId);
		}

		if ($schemaId > 0) {
			$parts[] = $this->formatter->resolveSchemaName(schemaId: $schemaId);
		}

		$subline = implode(' · ', $parts);

		$excerpt = $this->buildExcerpt(object: $object, term: (string)$term);
		if ($excerpt !== '') {
			$subline .= ' — ' . $excerpt;
		}

		return $subline;
	}//end buildSubline()

	/**
	 * Build an excerpt around the first occurrence of the term.
	 *
	 * Walks the object's top-level scalar string values in property order
	 * (skipping `@self`), returns ±EXCERPT_CONTEXT chars around the first
	 * case-insensitive match of the term (ellipsised, matched substring
	 * left verbatim). With no string match — numeric/relational hit or a
	 * filter-only browse — falls back to `summary`, then a truncated
	 * `description`, then an empty string. The object passed in is the
	 * rendered object the user is allowed to read, so field-level security
	 * already redacted hidden fields from the excerpt source.
	 *
	 * @param array $object The rendered object data.
	 * @param string $term The search term (empty for filter-only browse).
	 *
	 * @return string The excerpt, or an empty string.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Excerpt walks fields with several optional paths.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Excerpt has multiple fallback branches.
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	private function buildExcerpt(array $object, string $term): string {
		if ($term !== '') {
			foreach ($object as $key => $value) {
				if ($key === '@self' || is_string($value) === false) {
					continue;
				}

				$position = mb_stripos($value, $term);
				if ($position === false) {
					continue;
				}

				return $this->sliceExcerpt(value: $value, position: $position, length: mb_strlen($term));
			}
		}

		// Fallback chain: summary → truncated description → empty.
		if (isset($object['summary']) === true && is_string($object['summary']) === true && $object['summary'] !== '') {
			return $object['summary'];
		}

		if (isset($object['description']) === true && is_string($object['description']) === true && $object['description'] !== '') {
			$description = $object['description'];
			if (mb_strlen($description) > 100) {
				return mb_substr($description, 0, 100) . '…';
			}

			return $description;
		}

		return '';
	}//end buildExcerpt()

	/**
	 * Cut a ±context window around a match position, ellipsising the edges.
	 *
	 * @param string $value The full field value.
	 * @param int $position The byte/char position of the match.
	 * @param int $length The length of the matched term.
	 *
	 * @return string The ellipsised fragment with the matched substring verbatim.
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	private function sliceExcerpt(string $value, int $position, int $length): string {
		$start = max(0, ($position - self::EXCERPT_CONTEXT));
		$end = min(mb_strlen($value), ($position + $length + self::EXCERPT_CONTEXT));

		$fragment = mb_substr($value, $start, ($end - $start));

		if ($start > 0) {
			$fragment = '…' . $fragment;
		}

		if ($end < mb_strlen($value)) {
			$fragment .= '…';
		}

		return $fragment;
	}//end sliceExcerpt()
}//end class
