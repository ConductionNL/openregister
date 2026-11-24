<?php

/**
 * The slugs a schema answered to before it was renamed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\Schema;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Formats\UuidFormat;

/**
 * Matches a slug against the former slugs of a set of schemas, and says when a path was served through one.
 *
 * A renamed schema keeps its old slug in `formerSlugs`. Three callers need the
 * same two answers about it: the register-scoped object path resolution and the
 * register import (which schema answers to this old slug?), and the object
 * controller (was this request served through an old slug, and where is the
 * new path?). Keeping the rules here keeps them identical.
 *
 * A CURRENT SLUG ALWAYS WINS. Callers ask this only after the exact current-slug
 * match came back empty, and a schema whose current slug is the asked slug is
 * never treated as a former-slug match.
 *
 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md#requirement-a-former-slug-keeps-answering-and-says-where-to-go
 */
final class FormerSlugs {

	/**
	 * The schema among these that answered to the slug before it was renamed.
	 *
	 * Several schemas can list the same former slug (renamed one after the
	 * other); the lowest id wins, so the answer does not depend on the order
	 * the rows were read in.
	 *
	 * @param iterable<mixed> $schemas The candidate schemas.
	 * @param string          $slug    The slug asked for.
	 *
	 * @return Schema|null The renamed schema, or null when none answered to the slug.
	 *
	 * @spec openspec/changes/modelling-rename-without-loss/tasks.md#2.2
	 */
	public static function among(iterable $schemas, string $slug): ?Schema {
		if ($slug === '') {
			return null;
		}

		$found = null;
		foreach ($schemas as $schema) {
			if (self::answeredTo(schema: $schema, slug: $slug) === false) {
				continue;
			}

			if ($found === null || (int)$schema->getId() < (int)$found->getId()) {
				$found = $schema;
			}
		}

		return $found;
	}//end among()

	/**
	 * Whether a request naming this schema reference was served through a former slug.
	 *
	 * Only a slug can be former: a numeric id or a uuid never changes on a
	 * rename. The current slug is compared case-insensitively, as the slug
	 * lookups compare it.
	 *
	 * @param int|string $schemaRef The schema reference from the request path.
	 * @param mixed      $schema    The schema the request resolved to.
	 *
	 * @return bool True when the reference is one of the schema's former slugs.
	 *
	 * @spec openspec/changes/modelling-rename-without-loss/tasks.md#2.2
	 */
	public static function servedThrough(int|string $schemaRef, mixed $schema): bool {
		if (is_string($schemaRef) === false || $schemaRef === '' || ctype_digit($schemaRef) === true) {
			return false;
		}

		if (UuidFormat::isCanonical(value: $schemaRef) === true) {
			return false;
		}

		return self::answeredTo(schema: $schema, slug: $schemaRef);
	}//end servedThrough()

	/**
	 * The path of the current slug, for the `Link: rel="successor-version"` of a former-slug request.
	 *
	 * The request path is kept and only its schema segment is replaced, so the
	 * link names the same resource (a list, one object, a sub-resource) at its
	 * new address. A path that does not carry the expected segment falls back
	 * to the list path of the current slug.
	 *
	 * @param string $requestUri The request URI, query included or not.
	 * @param string $register   The register segment of the request path.
	 * @param string $former     The former slug the request named.
	 * @param string $current    The schema's current slug.
	 *
	 * @return string The path at the current slug.
	 *
	 * @spec openspec/changes/modelling-rename-without-loss/tasks.md#2.2
	 */
	public static function successorPath(string $requestUri, string $register, string $former, string $current): string {
		$path    = (string)(parse_url($requestUri, PHP_URL_PATH) ?? '');
		$segment = '/api/objects/' . rawurlencode($register) . '/';
		$at      = strpos($path, $segment . rawurlencode($former));
		if ($at === false) {
			return '/index.php/apps/openregister/api/objects/' . rawurlencode($register) . '/' . rawurlencode($current);
		}

		$tail = substr($path, $at + strlen($segment . rawurlencode($former)));
		if ($tail !== '' && $tail[0] !== '/') {
			return '/index.php/apps/openregister/api/objects/' . rawurlencode($register) . '/' . rawurlencode($current);
		}

		return substr($path, 0, $at) . $segment . rawurlencode($current) . $tail;
	}//end successorPath()

	/**
	 * The response headers that tell a caller a former slug served it.
	 *
	 * @param string $successorPath The path at the current slug.
	 *
	 * @return array<string, string> The `Deprecation` and `Link` headers.
	 *
	 * @spec openspec/changes/modelling-rename-without-loss/tasks.md#2.2
	 */
	public static function headers(string $successorPath): array {
		return [
			'Deprecation' => 'true',
			'Link'        => '<' . $successorPath . '>; rel="successor-version"',
		];
	}//end headers()

	/**
	 * Whether a schema lists the slug as former and does not carry it as its current slug.
	 *
	 * @param mixed  $schema The schema.
	 * @param string $slug   The slug.
	 *
	 * @return bool
	 */
	private static function answeredTo(mixed $schema, string $slug): bool {
		if (($schema instanceof Schema) === false) {
			return false;
		}

		if (strcasecmp((string)$schema->getSlug(), $slug) === 0) {
			return false;
		}

		return in_array($slug, ($schema->getFormerSlugs() ?? []), true);
	}//end answeredTo()
}//end class
