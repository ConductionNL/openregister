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
class JsonLdSerializer
{

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
     * Constructor.
     *
     * @param JsonLdContextService $contextService The context-document service.
     * @param IURLGenerator        $urlGenerator   The URL generator (for `@id` fallback).
     */
    public function __construct(
        private readonly JsonLdContextService $contextService,
        private readonly IURLGenerator $urlGenerator
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
    public function wantsJsonLd(IRequest $request): bool
    {
        $accept = $request->getHeader('Accept');
        if ($accept === '') {
            return false;
        }

        $best     = null;
        $bestQ    = -1.0;
        $bestRank = -1;

        foreach (explode(',', $accept) as $part) {
            $segments = explode(';', trim($part));
            $type     = strtolower(trim($segments[0]));
            if ($type === '') {
                continue;
            }

            $q = 1.0;
            foreach (array_slice($segments, 1) as $paramSegment) {
                $paramSegment = trim($paramSegment);
                if (stripos($paramSegment, 'q=') === 0) {
                    $q = (float) substr($paramSegment, 2);
                }
            }

            // Specificity rank: explicit type beats subtype-wildcard beats `*/*`.
            $rank = 2;
            if ($type === '*/*') {
                $rank = 0;
            } else if (str_ends_with($type, '/*') === true) {
                $rank = 1;
            }

            // Highest q wins; ties broken by specificity.
            if ($q > $bestQ || ($q === $bestQ && $rank > $bestRank)) {
                $bestQ    = $q;
                $bestRank = $rank;
                $best     = $type;
            }
        }//end foreach

        return $best === self::MEDIA_TYPE && $bestQ > 0.0;
    }//end wantsJsonLd()

    /**
     * Serialize a single rendered object array as a JSON-LD node document.
     *
     * @param array<string, mixed> $renderedObject The rendered object (top-level data + `@self`).
     * @param Schema               $schema         The object's schema.
     * @param Register             $register       The object's register.
     *
     * @return array<string, mixed> The JSON-LD document.
     *
     * @spec openspec/specs/json-ld-output/spec.md
     */
    public function serialize(array $renderedObject, Schema $schema, Register $register): array
    {
        $node = $this->buildNode(renderedObject: $renderedObject, schema: $schema, register: $register);

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
     * @param Schema               $schema          The collection's schema.
     * @param Register             $register        The collection's register.
     *
     * @return array<string, mixed> The JSON-LD `@graph` document.
     *
     * @spec openspec/specs/json-ld-output/spec.md
     */
    public function serializeCollection(array $paginatedResult, Schema $schema, Register $register): array
    {
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
            $graph[] = $this->buildNode(renderedObject: $rendered, schema: $schema, register: $register);
        }

        $document = [
            '@context' => $this->contextService->getSchemaContextUrl($register, $schema),
            '@graph'   => $graph,
        ];

        // Pagination metadata under or: terms (keeps the document valid JSON-LD).
        if (array_key_exists('total', $paginatedResult) === true) {
            $document['or:total'] = $paginatedResult['total'];
        }

        if (array_key_exists('page', $paginatedResult) === true) {
            $document['or:page'] = $paginatedResult['page'];
        }

        if (array_key_exists('pages', $paginatedResult) === true) {
            $document['or:pages'] = $paginatedResult['pages'];
        }

        if (array_key_exists('limit', $paginatedResult) === true) {
            $document['or:limit'] = $paginatedResult['limit'];
        }

        $next = $this->buildNextLink(paginatedResult: $paginatedResult);
        if ($next !== null) {
            $document['or:next'] = $next;
        }

        return $document;
    }//end serializeCollection()

    /**
     * Build a single JSON-LD node (no `@context`) from a rendered object array.
     *
     * @param array<string, mixed> $renderedObject The rendered object.
     * @param Schema               $schema         The object's schema.
     * @param Register             $register       The object's register.
     *
     * @return array<string, mixed> The JSON-LD node.
     */
    private function buildNode(array $renderedObject, Schema $schema, Register $register): array
    {
        $self = ($renderedObject['@self'] ?? []);
        if (is_array($self) === false) {
            $self = [];
        }

        $node = [
            '@id'   => $this->resolveId(self: $self, register: $register, schema: $schema),
            '@type' => $this->contextService->getTypeForSchema($schema),
        ];

        // Lift @self metadata to or: terms (skip null/empty so the node stays lean).
        foreach ($this->liftSelfMetadata(self: $self) as $term => $value) {
            $node[$term] = $value;
        }

        // Copy the object's data properties, escaping reserved keys.
        foreach ($renderedObject as $key => $value) {
            if ($key === '@self') {
                // Replaced by the lifted or: terms; never emitted.
                continue;
            }

            if ($this->isReservedKey(key: $key) === true) {
                $node[self::RAW_PREFIX.ltrim((string) $key, '@')] = $value;
                continue;
            }

            $node[$key] = $value;
        }

        return $node;
    }//end buildNode()

    /**
     * Resolve a node's `@id`: the canonical object URI, else the absolute
     * `objects#show` route URL.
     *
     * @param array<string, mixed> $self     The object's `@self` envelope.
     * @param Register             $register The object's register.
     * @param Schema               $schema   The object's schema.
     *
     * @return string The dereferenceable `@id`.
     */
    private function resolveId(array $self, Register $register, Schema $schema): string
    {
        $uri = ($self['uri'] ?? null);
        if (is_string($uri) === true && $uri !== '') {
            return $uri;
        }

        $uuid = ($self['id'] ?? '');
        return $this->urlGenerator->linkToRouteAbsolute(
            'openregister.objects.show',
            [
                'register' => $this->slugOf(entity: $register),
                'schema'   => $this->slugOf(entity: $schema),
                'id'       => (string) $uuid,
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
    private function liftSelfMetadata(array $self): array
    {
        $lifted = [];
        foreach ($self as $key => $value) {
            if ($key === 'uri') {
                // Exposed as @id, not duplicated.
                continue;
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $lifted['or:'.$key] = $value;
        }

        return $lifted;
    }//end liftSelfMetadata()

    /**
     * Build the `or:next` page URL from a paginated result, when there is a
     * next page.
     *
     * @param array<string, mixed> $paginatedResult The paginated result.
     *
     * @return string|null The next-page URL, or null when on the last page.
     */
    private function buildNextLink(array $paginatedResult): ?string
    {
        // Honour a server-provided next link if present.
        if (isset($paginatedResult['next']) === true && is_string($paginatedResult['next']) === true) {
            return $paginatedResult['next'];
        }

        $page  = ($paginatedResult['page'] ?? null);
        $pages = ($paginatedResult['pages'] ?? null);
        if (is_int($page) === false || is_int($pages) === false || $page >= $pages) {
            return null;
        }

        // Derive next-page URL from the current request URI when available.
        $current = ($paginatedResult['@self']['self'] ?? null);
        if (is_string($current) === false || $current === '') {
            return null;
        }

        if (str_contains($current, '?') === true) {
            $separator = '&';
        } else {
            $separator = '?';
        }

        return $current.$separator.'_page='.($page + 1);
    }//end buildNextLink()

    /**
     * Whether a data key is a reserved JSON-LD keyword that must be escaped to
     * avoid colliding with the injected keywords.
     *
     * @param int|string $key The data key.
     *
     * @return bool True when the key must be escaped.
     */
    private function isReservedKey(int | string $key): bool
    {
        return is_string($key) === true && str_starts_with($key, '@') === true;
    }//end isReservedKey()

    /**
     * Resolve the slug for an entity, falling back to UUID then id.
     *
     * @param Register|Schema $entity The entity.
     *
     * @return string The slug-like identifier.
     */
    private function slugOf(Register | Schema $entity): string
    {
        $slug = $entity->getSlug();
        if (is_string($slug) === true && $slug !== '') {
            return $slug;
        }

        $uuid = $entity->getUuid();
        if (is_string($uuid) === true && $uuid !== '') {
            return $uuid;
        }

        return (string) $entity->getId();
    }//end slugOf()
}//end class
