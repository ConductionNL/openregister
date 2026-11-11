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
final class ObjectSearchResultFormatter implements ObjectSearchResultFormatterInterface {

	/**
	 * Number of characters of context shown on each side of an excerpt match.
	 *
	 * @var int
	 */
	private const EXCERPT_CONTEXT = 60;

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
		private readonly IURLGenerator $urlGenerator,
		private readonly DeepLinkRegistryService $deepLinkRegistry,
		private readonly ObjectPreviewFormatter $formatter,
	) {
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
		$hit = self::hitOf(result: $result);

		$objectUrl = $this->resolveObjectUrl(hit: $hit);

		$hit = $this->labelled(hit: $hit);

		[$thumbnailUrl, $icon, $rounded] = $this->resolveIcon(hit: $hit);

		return new SearchResultEntry(
			$thumbnailUrl,
			self::titleOf(hit: $hit),
			$this->buildSubline(hit: $hit, term: $term),
			$objectUrl,
			$icon,
			$rounded
		);
	}//end format()

	/**
	 * The search hit for one result, normalising an entity to its rendered data.
	 *
	 * @param array|ObjectEntity $result The rendered object data, or the entity itself.
	 *
	 * @return ObjectSearchHit The hit.
	 */
	private static function hitOf(array|ObjectEntity $result): ObjectSearchHit {
		if ($result instanceof ObjectEntity) {
			$result = $result->jsonSerialize();
		}

		return new ObjectSearchHit(object: $result);
	}//end hitOf()

	/**
	 * The hit with the per-app label of its (register, schema) pair.
	 *
	 * @param ObjectSearchHit $hit The search hit.
	 *
	 * @return ObjectSearchHit The labelled hit.
	 */
	private function labelled(ObjectSearchHit $hit): ObjectSearchHit {
		return $hit->withAppLabel(
			$this->deepLinkRegistry->resolveDisplayName(
				registerId: $hit->registerId(),
				schemaId: $hit->schemaId()
			)
		);
	}//end labelled()

	/**
	 * The hit's title: its string `title`, else its non-empty `@self` name, else its uuid.
	 *
	 * @param ObjectSearchHit $hit The search hit.
	 *
	 * @return string The title, "Unknown Object" when the hit carries none of them.
	 */
	private static function titleOf(ObjectSearchHit $hit): string {
		$result = $hit->object();
		$name = $hit->selfData()['name'] ?? '';
		$uuid = $hit->uuid();

		if (isset($result['title']) === true && is_string($result['title']) === true) {
			return $result['title'];
		}

		if (is_string($name) === true && $name !== '') {
			return $name;
		}

		if ($uuid !== '') {
			return $uuid;
		}

		return 'Unknown Object';
	}//end titleOf()

	/**
	 * The hit's link: the owning app's deep link when one is registered and
	 * every placeholder in it filled, otherwise OpenRegister's own page.
	 *
	 * @param ObjectSearchHit $hit The search hit.
	 *
	 * @return string The link.
	 *
	 * @spec openspec/changes/search-deep-link-names-object-properties/specs/deep-link-registry/spec.md#requirement-a-search-hits-deep-link-can-name-the-objects-own-properties
	 */
	private function resolveObjectUrl(ObjectSearchHit $hit): string {
		// The object's own scalar properties come first, so a template can
		// name one (`/apps/dossiq/cases/{case}`), and `@self` plus the
		// resolved uuid, register and schema are merged over them: an own
		// property named `uuid` or `owner` never hijacks the metadata.
		$ids = $hit->ids();
		$flatData = array_merge($this->ownLinkProperties(object: $hit->object()), $hit->selfData(), $ids);

		$objectUrl = $this->deepLinkRegistry->resolveUrl(
			registerId: $ids['register'],
			schemaId: $ids['schema'],
			objectData: $flatData
		);

		// A placeholder the object could not fill (its `case` is absent or empty:
		// ownLinkProperties() passes neither) would leave a literal `{case}` in
		// the link. Such a hit opens OpenRegister's own page instead of a broken
		// one.
		if ($objectUrl !== null && preg_match('/\{[A-Za-z0-9_]+\}/', $objectUrl) !== 1) {
			return $objectUrl;
		}

		return $this->urlGenerator->linkToRoute(
			'openregister.objects.show',
			['register' => $ids['register'], 'schema' => $ids['schema'], 'id' => $ids['uuid']]
		);
	}//end resolveObjectUrl()

	/**
	 * The object's own properties a deep-link template may name: declared
	 * (not `@self`, not an `_`-prefixed key the pipeline attached, the same
	 * line {@see buildExcerpt()} draws) and scalar, and holding a value. An
	 * empty or blank string and a boolean are left out, so the placeholder
	 * stays unfilled and the hit falls back to OpenRegister's own page: filled
	 * with nothing, `/apps/dossiq/cases/{case}` would become `/apps/dossiq/cases/`
	 * and no brace would be left to notice it by. Values are URL-encoded,
	 * because they land in a path and a value like `../settings` must not
	 * walk out of it.
	 *
	 * @param array<string|int,mixed> $object The serialised object.
	 *
	 * @return array<string,string> Property name to encoded value.
	 *
	 * @spec openspec/changes/search-deep-link-names-object-properties/specs/deep-link-registry/spec.md#requirement-a-search-hits-deep-link-can-name-the-objects-own-properties
	 */
	private function ownLinkProperties(array $object): array {
		$properties = [];
		foreach ($object as $key => $value) {
			$key = (string)$key;
			if ($key === '@self' || str_starts_with($key, '_') === true) {
				continue;
			}

			if (is_scalar($value) === false || is_bool($value) === true || trim((string)$value) === '') {
				continue;
			}

			$properties[$key] = rawurlencode((string)$value);
		}

		return $properties;
	}//end ownLinkProperties()

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
	 * @param ObjectSearchHit $hit The search hit.
	 *
	 * @return array{0: string, 1: string, 2: bool} [thumbnailUrl, icon, rounded]
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	private function resolveIcon(ObjectSearchHit $hit): array {
		$mdiIconUrl = $this->formatter->resolveMdiIconUrl(schemaId: $hit->schemaId());
		if ($mdiIconUrl !== null) {
			return [$mdiIconUrl, 'icon-openregister', false];
		}

		$icon = $this->deepLinkRegistry->resolveIcon(
			registerId: $hit->registerId(),
			schemaId: $hit->schemaId()
		) ?? 'icon-openregister';
		$rounded = ($hit->appLabel() !== null);

		return ['', $icon, $rounded];
	}//end resolveIcon()

	/**
	 * Build the result subline: `{Owner} · {Register} · {Schema} — {excerpt}`.
	 *
	 * The owner label is the deep-link display name for claimed pairs, or
	 * `Open Register` for unclaimed pairs. The excerpt is appended when a
	 * term-driven match (or fallback summary/description) is available.
	 *
	 * @param ObjectSearchHit $hit  The search hit.
	 * @param string|null     $term The search term, or null for filter-only.
	 *
	 * @return string The composed subline.
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	private function buildSubline(ObjectSearchHit $hit, ?string $term): string {
		$subline = implode(' · ', $this->sublineParts(hit: $hit));

		$excerpt = $this->buildExcerpt(object: $hit->object(), term: (string)$term);
		if ($excerpt !== '') {
			$subline .= ' — ' . $excerpt;
		}

		return $subline;
	}//end buildSubline()

	/**
	 * The owner, register and schema names a hit's subline names.
	 *
	 * @param ObjectSearchHit $hit The hit.
	 *
	 * @return array<int, string> The owning app (or Open Register), then the register and schema names when known.
	 */
	private function sublineParts(ObjectSearchHit $hit): array {
		$parts = [self::ownerOf(appLabel: $hit->appLabel())];
		if ($hit->registerId() > 0) {
			$parts[] = $this->formatter->resolveRegisterName(registerId: $hit->registerId());
		}

		if ($hit->schemaId() > 0) {
			$parts[] = $this->formatter->resolveSchemaName(schemaId: $hit->schemaId());
		}

		return $parts;
	}//end sublineParts()

	/**
	 * The owner a subline names: the hit's app, or Open Register.
	 *
	 * @param string|null $appLabel The app label the hit carries.
	 *
	 * @return string The owner.
	 */
	private static function ownerOf(?string $appLabel): string {
		if ($appLabel !== null && $appLabel !== '') {
			return $appLabel;
		}

		return 'Open Register';
	}//end ownerOf()

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
			$excerpt = $this->termExcerpt(object: $object, term: $term);
			if ($excerpt !== null) {
				return $excerpt;
			}
		}

		return self::fallbackExcerpt(object: $object);
	}//end buildExcerpt()

	/**
	 * The excerpt around the term in the first of the object's own string values that holds it.
	 *
	 * @param array  $object The rendered object data.
	 * @param string $term   The (non-empty) search term.
	 *
	 * @return string|null The excerpt, or null when no own string value holds the term.
	 */
	private function termExcerpt(array $object, string $term): ?string {
		foreach ($object as $key => $value) {
			// Only the object's OWN properties are excerpt material.
			// `@self` is metadata, and an `_`-prefixed key is reserved:
			// it is something the pipeline attached, not something the
			// schema declares and field-level security rendered. Now that
			// file text is in scope, that distinction is load-bearing. A
			// chunk carries the text of a whole FILE, which can hold
			// values the reader is redacted out of on the object, so an
			// excerpt drawn from an attached key would leak past a
			// redaction that the object itself still honours.
			if ($key === '@self' || str_starts_with((string)$key, '_') === true || is_string($value) === false) {
				continue;
			}

			$excerpt = $this->excerptAround(value: $value, term: $term);
			if ($excerpt !== null) {
				return $excerpt;
			}
		}

		return null;
	}//end termExcerpt()

	/**
	 * The excerpt without a term match: the summary, else the description truncated to 100 characters, else ''.
	 *
	 * @param array $object The rendered object data.
	 *
	 * @return string The excerpt, or an empty string.
	 */
	private static function fallbackExcerpt(array $object): string {
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
	}//end fallbackExcerpt()

	/**
	 * Cut a ±context window around the first case-insensitive match of the
	 * term, ellipsising the edges.
	 *
	 * @param string $value The full field value.
	 * @param string $term  The (non-empty) search term.
	 *
	 * @return string|null The ellipsised fragment with the matched substring
	 *                     verbatim, or null when the value does not contain the term.
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	private function excerptAround(string $value, string $term): ?string {
		$position = mb_stripos($value, $term);
		if ($position === false) {
			return null;
		}

		$start = max(0, ($position - self::EXCERPT_CONTEXT));
		$end = min(mb_strlen($value), ($position + mb_strlen($term) + self::EXCERPT_CONTEXT));

		$fragment = mb_substr($value, $start, ($end - $start));

		if ($start > 0) {
			$fragment = '…' . $fragment;
		}

		if ($end < mb_strlen($value)) {
			$fragment .= '…';
		}

		return $fragment;
	}//end excerptAround()
}//end class
