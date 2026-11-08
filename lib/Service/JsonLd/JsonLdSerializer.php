<?php

/**
 * OpenRegister JSON-LD Serializer
 *
 * Wraps an already-rendered object array (the exact array `objects#show`
 * returns today) into a JSON-LD document: injects `@context`, `@id`, `@type`,
 * lifts the `@self` metadata envelope into `or:` terms, and escapes any
 * `@`-prefixed data keys. Collections become a `@graph` with a shared
 * top-level `@context`.
 *
 * This is a pure read-side transform: it never re-renders objects and never
 * touches the data path, so RBAC, multitenancy, field-level security, and the
 * published predicate all keep applying upstream of it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\JsonLd
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\JsonLd;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Serializes rendered object arrays as JSON-LD.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/json-ld-output/spec.md
 */
class JsonLdSerializer implements JsonLdSerializerInterface {

	/**
	 * The IANA media type for JSON-LD.
	 *
	 * @var string
	 */
	public const MEDIA_TYPE = 'application/ld+json';

	/**
	 * The escape prefix used for data keys that begin with `@` (other than the
	 * injected `@context`/`@id`/`@type`), so the document stays valid JSON-LD.
	 *
	 * @var string
	 */
	private const RAW_PREFIX = 'or:raw#';

	/**
	 * Pagination keys of an `index()` result and the `or:` term each is
	 * emitted under, in emission order.
	 *
	 * @var array<string, string>
	 */
	private const PAGINATION_TERMS = [
		'total' => 'or:total',
		'page' => 'or:page',
		'pages' => 'or:pages',
		'limit' => 'or:limit',
	];

	/**
	 * Constructor.
	 *
	 * @param JsonLdContextService $contextService The context-document service.
	 * @param IURLGenerator $urlGenerator The URL generator (for `@id` fallback).
	 */
	public function __construct(
		private readonly JsonLdContextService $contextService,
		private readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * Decide whether the client wants JSON-LD, based on the `Accept` header.
	 *
	 * True only when `application/ld+json` is the highest-weighted matching
	 * media type. Absent headers, `application/json`, and wildcard-only matches
	 * (`*` / `application/*`) resolve to plain JSON (default unchanged).
	 *
	 * @param IRequest $request The current request.
	 *
	 * @return bool True when JSON-LD output is requested.
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public function wantsJsonLd(IRequest $request): bool {
		$accept = $request->getHeader('Accept');
		if ($accept === '') {
			return false;
		}

		$best = ['type' => null, 'q' => -1.0, 'rank' => -1];
		foreach ($this->acceptCandidates(accept: $accept) as $candidate) {
			if ($this->outranks(candidate: $candidate, best: $best) === true) {
				$best = $candidate;
			}
		}

		return $best['type'] === self::MEDIA_TYPE && $best['q'] > 0.0;
	}//end wantsJsonLd()

	/**
	 * Parse the non-empty media ranges of an `Accept` header, in header order.
	 *
	 * @param string $accept The raw `Accept` header.
	 *
	 * @return array<int, array{type: string, q: float, rank: int}> The parsed media ranges.
	 */
	private function acceptCandidates(string $accept): array {
		$candidates = [];
		foreach (explode(',', $accept) as $part) {
			$candidate = $this->parseAcceptPart(part: $part);
			if ($candidate !== null) {
				$candidates[] = $candidate;
			}
		}

		return $candidates;
	}//end acceptCandidates()

	/**
	 * Parse one media range of an `Accept` header.
	 *
	 * @param string $part One comma-separated part of the header.
	 *
	 * @return array{type: string, q: float, rank: int}|null The media range, or null when it names no type.
	 */
	private function parseAcceptPart(string $part): ?array {
		$segments = explode(';', trim($part));
		$type = strtolower(trim($segments[0]));
		if ($type === '') {
			return null;
		}

		return [
			'type' => $type,
			'q' => $this->qualityOf(segments: $segments),
			'rank' => $this->specificityOf(type: $type),
		];
	}//end parseAcceptPart()

	/**
	 * The `q` weight of a media range; the last `q=` parameter wins, default 1.
	 *
	 * @param array<int, string> $segments The `;`-separated segments of the range.
	 *
	 * @return float The weight.
	 */
	private function qualityOf(array $segments): float {
		$q = 1.0;
		foreach (array_slice($segments, 1) as $paramSegment) {
			$paramSegment = trim($paramSegment);
			if (stripos($paramSegment, 'q=') === 0) {
				$q = (float)substr($paramSegment, 2);
			}
		}

		return $q;
	}//end qualityOf()

	/**
	 * Specificity rank: explicit type beats subtype-wildcard beats `*`/`*`.
	 *
	 * @param string $type The lower-cased media type.
	 *
	 * @return int The rank (2 explicit, 1 subtype wildcard, 0 full wildcard).
	 */
	private function specificityOf(string $type): int {
		if ($type === '*/*') {
			return 0;
		}

		if (str_ends_with($type, '/*') === true) {
			return 1;
		}

		return 2;
	}//end specificityOf()

	/**
	 * Whether a media range beats the best so far: highest q wins, ties broken
	 * by specificity, and the earlier range wins a full tie.
	 *
	 * @param array{type: string, q: float, rank: int}      $candidate The media range.
	 * @param array{type: string|null, q: float, rank: int} $best      The best range so far.
	 *
	 * @return bool True when the candidate replaces the best.
	 */
	private function outranks(array $candidate, array $best): bool {
		return $candidate['q'] > $best['q'] || ($candidate['q'] === $best['q'] && $candidate['rank'] > $best['rank']);
	}//end outranks()

	/**
	 * Serialize a single rendered object array as a JSON-LD node document.
	 *
	 * @param array<string, mixed> $renderedObject The rendered object (top-level data + `@self`).
	 * @param Schema $schema The object's schema.
	 * @param Register $register The object's register.
	 *
	 * @return array<string, mixed> The JSON-LD document.
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public function serialize(array $renderedObject, Schema $schema, Register $register): array {
		$node = $this->buildNode(renderedObject: $renderedObject, target: new JsonLdTarget(register: $register, schema: $schema));

		// Prepend the @context reference (per-schema context document URL).
		return array_merge(
			['@context' => $this->contextService->getSchemaContextUrl($register, $schema)],
			$node
		);
	}//end serialize()

	/**
	 * Serialize a paginated collection result as a JSON-LD `@graph` document.
	 *
	 * @param array<string, mixed> $paginatedResult The `index()` result (results/total/page/...).
	 * @param Schema $schema The collection's schema.
	 * @param Register $register The collection's register.
	 *
	 * @return array<string, mixed> The JSON-LD `@graph` document.
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public function serializeCollection(array $paginatedResult, Schema $schema, Register $register): array {
		$graph = $this->buildGraph(paginatedResult: $paginatedResult, target: new JsonLdTarget(register: $register, schema: $schema));

		$document = [
			'@context' => $this->contextService->getSchemaContextUrl($register, $schema),
			'@graph' => $graph,
		];

		// Pagination metadata under or: terms (keeps the document valid JSON-LD).
		foreach (self::PAGINATION_TERMS as $key => $term) {
			if (array_key_exists($key, $paginatedResult) === true) {
				$document[$term] = $paginatedResult[$key];
			}
		}

		$next = $this->buildNextLink(paginatedResult: $paginatedResult);
		if ($next !== null) {
			$document['or:next'] = $next;
		}

		return $document;
	}//end serializeCollection()

	/**
	 * Build the `@graph` nodes of a paginated result; non-array rows are skipped.
	 *
	 * @param array<string, mixed> $paginatedResult The `index()` result.
	 * @param JsonLdTarget         $target          The collection's register and schema.
	 *
	 * @return array<int, array<string, mixed>> The nodes.
	 */
	private function buildGraph(array $paginatedResult, JsonLdTarget $target): array {
		$results = ($paginatedResult['results'] ?? []);
		if (is_array($results) === false) {
			$results = [];
		}

		$graph = [];
		foreach ($results as $rendered) {
			if (is_array($rendered) === false) {
				continue;
			}

			// Each node carries its own @id/@type but not a repeated @context.
			$graph[] = $this->buildNode(renderedObject: $rendered, target: $target);
		}

		return $graph;
	}//end buildGraph()

	/**
	 * Build a single JSON-LD node (no `@context`) from a rendered object array.
	 *
	 * @param array<string, mixed> $renderedObject The rendered object.
	 * @param JsonLdTarget         $target         The object's register and schema.
	 *
	 * @return array<string, mixed> The JSON-LD node.
	 */
	private function buildNode(array $renderedObject, JsonLdTarget $target): array {
		$self = ($renderedObject['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		$node = [
			'@id' => $this->resolveId(self: $self, target: $target),
			'@type' => $this->contextService->getTypeForSchema($target->schema),
		];

		// Lift @self metadata to or: terms (skip null/empty so the node stays lean).
		foreach ($this->liftSelfMetadata(self: $self) as $term => $value) {
			$node[$term] = $value;
		}

		// Copy the object's data properties, escaping reserved keys.
		foreach ($this->dataProperties(renderedObject: $renderedObject) as $key => $value) {
			$node[$key] = $value;
		}

		return $node;
	}//end buildNode()

	/**
	 * The data properties of a rendered object: `@self` dropped (it is lifted
	 * to `or:` terms) and other `@`-keys escaped under the raw prefix.
	 *
	 * @param array<string, mixed> $renderedObject The rendered object.
	 *
	 * @return array<int|string, mixed> The properties to copy onto the node, in order.
	 */
	private function dataProperties(array $renderedObject): array {
		$properties = [];
		foreach ($renderedObject as $key => $value) {
			if ($key === '@self') {
				// Replaced by the lifted or: terms; never emitted.
				continue;
			}

			if ($this->isReservedKey(key: $key) === true) {
				$properties[self::RAW_PREFIX . ltrim((string)$key, '@')] = $value;
				continue;
			}

			$properties[$key] = $value;
		}

		return $properties;
	}//end dataProperties()

	/**
	 * Resolve a node's `@id`: the canonical object URI, else the absolute
	 * `objects#show` route URL.
	 *
	 * @param array<string, mixed> $self   The object's `@self` envelope.
	 * @param JsonLdTarget         $target The object's register and schema.
	 *
	 * @return string The dereferenceable `@id`.
	 */
	private function resolveId(array $self, JsonLdTarget $target): string {
		$uri = ($self['uri'] ?? null);
		if (is_string($uri) === true && $uri !== '') {
			return $uri;
		}

		$uuid = ($self['id'] ?? '');
		return $this->urlGenerator->linkToRouteAbsolute(
			'openregister.objects.show',
			[
				'register' => $this->slugOf(entity: $target->register),
				'schema' => $this->slugOf(entity: $target->schema),
				'id' => (string)$uuid,
			]
		);
	}//end resolveId()

	/**
	 * Lift the `@self` envelope into `or:`-prefixed JSON-LD terms. The `uri`
	 * key is excluded (it is the node's `@id`); null/empty values are skipped.
	 *
	 * @param array<string, mixed> $self The `@self` envelope.
	 *
	 * @return array<string, mixed> The lifted `or:` terms.
	 */
	private function liftSelfMetadata(array $self): array {
		$lifted = [];
		foreach ($self as $key => $value) {
			// The uri is exposed as @id, not duplicated; empty values are skipped.
			if ($key === 'uri' || $this->isEmptyMetadata(value: $value) === true) {
				continue;
			}

			$lifted['or:' . $key] = $value;
		}

		return $lifted;
	}//end liftSelfMetadata()

	/**
	 * Whether a `@self` value is empty (null, empty string or empty array).
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True when the value is left off the node.
	 */
	private function isEmptyMetadata(mixed $value): bool {
		return $value === null || $value === '' || $value === [];
	}//end isEmptyMetadata()

	/**
	 * Build the `or:next` page URL from a paginated result, when there is a
	 * next page.
	 *
	 * @param array<string, mixed> $paginatedResult The paginated result.
	 *
	 * @return string|null The next-page URL, or null when on the last page.
	 */
	private function buildNextLink(array $paginatedResult): ?string {
		// Honour a server-provided next link if present.
		if (isset($paginatedResult['next']) === true && is_string($paginatedResult['next']) === true) {
			return $paginatedResult['next'];
		}

		$page = ($paginatedResult['page'] ?? null);
		$pages = ($paginatedResult['pages'] ?? null);
		if ($this->hasNextPage(page: $page, pages: $pages) === false) {
			return null;
		}

		// Derive next-page URL from the current request URI when available.
		return $this->nextPageUrl(current: ($paginatedResult['@self']['self'] ?? null), page: $page);
	}//end buildNextLink()

	/**
	 * Whether integer pagination values say there is a page after this one.
	 *
	 * @param mixed $page  The current page.
	 * @param mixed $pages The page count.
	 *
	 * @return bool True when both are integers and the page is before the last.
	 */
	private function hasNextPage(mixed $page, mixed $pages): bool {
		return is_int($page) === true && is_int($pages) === true && $page < $pages;
	}//end hasNextPage()

	/**
	 * Append `_page={page + 1}` to the current request URI.
	 *
	 * @param mixed $current The current request URI, if known.
	 * @param int   $page    The current page.
	 *
	 * @return string|null The next-page URL, or null without a current URI.
	 */
	private function nextPageUrl(mixed $current, int $page): ?string {
		if (is_string($current) === false || $current === '') {
			return null;
		}

		if (str_contains($current, '?') === true) {
			$separator = '&';
		} else {
			$separator = '?';
		}

		return $current . $separator . '_page=' . ($page + 1);
	}//end nextPageUrl()

	/**
	 * Whether a data key is a reserved JSON-LD keyword that must be escaped to
	 * avoid colliding with the injected keywords.
	 *
	 * @param int|string $key The data key.
	 *
	 * @return bool True when the key must be escaped.
	 */
	private function isReservedKey(int|string $key): bool {
		return is_string($key) === true && str_starts_with($key, '@') === true;
	}//end isReservedKey()

	/**
	 * Resolve the slug for an entity, falling back to UUID then id.
	 *
	 * @param Register|Schema $entity The entity.
	 *
	 * @return string The slug-like identifier.
	 */
	private function slugOf(Register|Schema $entity): string {
		$slug = $entity->getSlug();
		if (is_string($slug) === true && $slug !== '') {
			return $slug;
		}

		$uuid = $entity->getUuid();
		if (is_string($uuid) === true && $uuid !== '') {
			return $uuid;
		}

		return (string)$entity->getId();
	}//end slugOf()
}//end class
