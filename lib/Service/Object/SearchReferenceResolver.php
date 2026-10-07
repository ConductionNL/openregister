<?php

/**
 * SearchReferenceResolver - register and schema references on the READ path
 *
 * The write path (`saveObject()`, `find()`) accepts a register or schema as an
 * entity, an id, a uuid or a slug, resolves it, and throws when it cannot. The
 * read path used to int-cast the same two values, which turned every slug, uuid
 * and empty string into `0` and then into an empty page. This resolver gives the
 * read path the write path's manners.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use Psr\Log\LoggerInterface;

/**
 * Resolves a register or schema reference for a search.
 *
 * Three answers, and no fourth:
 *
 * - a numeric id passes straight through, so the hot path costs nothing;
 * - a slug or uuid is resolved to its numeric id, the way the write path does;
 * - anything that names nothing raises {@see RegisterNotFoundException} or
 *   {@see SchemaNotFoundException}.
 *
 * The fourth answer, the one this class exists to remove, was "the empty page".
 * `(int)'my-register'` is `0`, `0` is not `null`, so the search ran scoped to a
 * register that cannot exist, found nothing, and reported nothing found. Three
 * apps read that as a fact about the data and acted on it: a document freeze
 * that never froze, five delete cascades fed by an empty result, and a register
 * wipe that deleted nothing. See ConductionNL/openregister#3990.
 *
 * A reference that is null, an empty string or only whitespace is NOT an error:
 * it says nothing, so the key is dropped and the search reaches the same global
 * fallbacks a real `null` would have reached. That is the one case where `0` and
 * `null` differ and `null` was always meant.
 *
 * Lookups run with RBAC and multitenancy OFF, like every other structural lookup
 * in the query builder ({@see SearchQueryHandler::schemaHasObjectSource()}): the
 * resolver hands back an id and no data, and the rows the search then reads stay
 * gated by RBAC and the tenant filter downstream.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object
 */
class SearchReferenceResolver {

	/**
	 * The query keys that carry a register reference.
	 *
	 * Bare top-level `register` / `schema` are deliberately absent: on a query
	 * array they can also be an object-field filter on a property of that name,
	 * and guessing which one was meant would trade a silent empty page for a
	 * loud wrong refusal.
	 *
	 * The third member says whether the key holds a LIST. A list key that is
	 * not spelled as an array is left exactly as it is: MagicMapper ignores
	 * such a value today, and turning that silence into a refusal is a separate
	 * decision from this one.
	 *
	 * @var array<int, array{0: string|null, 1: string, 2: bool}>
	 */
	private const REGISTER_KEYS = [
		['@self', 'register', false],
		['@self', 'registers', true],
		[null, '_register', false],
		[null, '_registers', true],
	];

	/**
	 * The query keys that carry a schema reference.
	 *
	 * @var array<int, array{0: string|null, 1: string, 2: bool}>
	 */
	private const SCHEMA_KEYS = [
		['@self', 'schema', false],
		['@self', 'schemas', true],
		[null, '_schema', false],
		[null, '_schemas', true],
	];

	/**
	 * SearchReferenceResolver constructor.
	 *
	 * @param RegisterMapper  $registerMapper Resolves a register reference.
	 * @param SchemaMapper    $schemaMapper   Resolves a schema reference.
	 * @param LoggerInterface $logger         Records each reference that had to be resolved.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve a register reference to its numeric id.
	 *
	 * @param int|string|array|null $reference The register id, uuid, slug, or a list of them.
	 *
	 * @return int|array|null The numeric id(s), or null when the reference says nothing.
	 *
	 * @throws RegisterNotFoundException When the reference names no register.
	 *
	 * @psalm-return int|array<int, int>|null
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function register(int|string|array|null $reference): int|array|null {
		return $this->resolve(reference: $reference, kind: 'register');
	}//end register()

	/**
	 * Resolve a schema reference to its numeric id.
	 *
	 * @param int|string|array|null $reference The schema id, uuid, slug, or a list of them.
	 *
	 * @return int|array|null The numeric id(s), or null when the reference says nothing.
	 *
	 * @throws SchemaNotFoundException When the reference names no schema.
	 *
	 * @psalm-return int|array<int, int>|null
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function schema(int|string|array|null $reference): int|array|null {
		return $this->resolve(reference: $reference, kind: 'schema');
	}//end schema()

	/**
	 * Resolve every register and schema reference carried by a query array.
	 *
	 * This is the seam for callers that hand a ready-made query to
	 * `searchObjects()` or `searchObjectsPaginated()` instead of going through
	 * `buildSearchQuery()`. filinq reached the bug that way.
	 *
	 * @param array $query The search query.
	 *
	 * @phpstan-param array<string, mixed> $query
	 * @psalm-param   array<string, mixed> $query
	 *
	 * @return array The same query with every reference resolved.
	 *
	 * @phpstan-return array<string, mixed>
	 * @psalm-return   array<string, mixed>
	 *
	 * @throws RegisterNotFoundException When a register reference names no register.
	 * @throws SchemaNotFoundException   When a schema reference names no schema.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function normaliseQuery(array $query): array {
		foreach (self::REGISTER_KEYS as [$parent, $key, $plural]) {
			$query = $this->normaliseKey(
				query: $query,
				parent: $parent,
				key: $key,
				kind: 'register',
				plural: $plural
			);
		}

		foreach (self::SCHEMA_KEYS as [$parent, $key, $plural]) {
			$query = $this->normaliseKey(
				query: $query,
				parent: $parent,
				key: $key,
				kind: 'schema',
				plural: $plural
			);
		}

		return $query;
	}//end normaliseQuery()

	/**
	 * Resolve one key of a query array in place.
	 *
	 * @param array       $query  The search query.
	 * @param string|null $parent The containing key (`@self`), or null for top level.
	 * @param string      $key    The key holding the reference.
	 * @param string      $kind   Either `register` or `schema`.
	 * @param bool        $plural Whether the key holds a list.
	 *
	 * @phpstan-param array<string, mixed> $query
	 * @psalm-param   array<string, mixed> $query
	 *
	 * @return array The query with that key resolved, or dropped when it said nothing.
	 *
	 * @phpstan-return array<string, mixed>
	 * @psalm-return   array<string, mixed>
	 *
	 * @throws RegisterNotFoundException When a register reference names no register.
	 * @throws SchemaNotFoundException   When a schema reference names no schema.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag names the key's shape, not a mode
	 */
	private function normaliseKey(array $query, ?string $parent, string $key, string $kind, bool $plural): array {
		$value = $this->referenceAt(query: $query, parent: $parent, key: $key, plural: $plural);
		if ($value === null) {
			return $query;
		}

		return $this->writeBack(
			query: $query,
			parent: $parent,
			key: $key,
			resolved: $this->resolve(reference: $value, kind: $kind)
		);
	}//end normaliseKey()

	/**
	 * The reference a query carries at one key, when it carries one.
	 *
	 * Null means there is nothing to resolve: the key is absent, its value is
	 * not a reference shape, or it is a list key spelled as a single value.
	 *
	 * @param array       $query  The search query.
	 * @param string|null $parent The containing key (`@self`), or null for top level.
	 * @param string      $key    The key holding the reference.
	 * @param bool        $plural Whether the key holds a list.
	 *
	 * @phpstan-param array<string, mixed> $query
	 * @psalm-param   array<string, mixed> $query
	 *
	 * @return int|string|array|null The reference, or null when there is none to read.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag names the key's shape, not a mode
	 */
	private function referenceAt(array $query, ?string $parent, string $key, bool $plural): int|string|array|null {
		$holder = $query;
		if ($parent !== null) {
			if (is_array($query[$parent] ?? null) === false) {
				return null;
			}

			$holder = $query[$parent];
		}

		$value = ($holder[$key] ?? null);
		if (is_int($value) === false && is_string($value) === false && is_array($value) === false) {
			return null;
		}

		if ($plural === true && is_array($value) === false) {
			return null;
		}

		return $value;
	}//end referenceAt()

	/**
	 * Put a resolved reference back, or drop the key when it said nothing.
	 *
	 * @param array           $query    The search query.
	 * @param string|null     $parent   The containing key (`@self`), or null for top level.
	 * @param string          $key      The key holding the reference.
	 * @param int|array|null  $resolved The resolved id(s), or null.
	 *
	 * @phpstan-param array<string, mixed> $query
	 * @psalm-param   array<string, mixed> $query
	 *
	 * @return array The query.
	 *
	 * @phpstan-return array<string, mixed>
	 * @psalm-return   array<string, mixed>
	 */
	private function writeBack(array $query, ?string $parent, string $key, int|array|null $resolved): array {
		if ($parent === null) {
			if ($resolved === null) {
				unset($query[$key]);
				return $query;
			}

			$query[$key] = $resolved;
			return $query;
		}

		if ($resolved === null) {
			unset($query[$parent][$key]);
			return $query;
		}

		$query[$parent][$key] = $resolved;

		return $query;
	}//end writeBack()

	/**
	 * Resolve a reference, or a list of them, to numeric id(s).
	 *
	 * @param int|string|array|null $reference The reference(s).
	 * @param string                $kind      Either `register` or `schema`.
	 *
	 * @return int|array|null The numeric id(s), or null when the reference says nothing.
	 *
	 * @psalm-return int|array<int, int>|null
	 *
	 * @throws RegisterNotFoundException When a register reference names no register.
	 * @throws SchemaNotFoundException   When a schema reference names no schema.
	 */
	private function resolve(int|string|array|null $reference, string $kind): int|array|null {
		if ($reference === null) {
			return null;
		}

		if (is_array($reference) === true) {
			$ids = [];
			foreach ($reference as $item) {
				if (is_int($item) === false && is_string($item) === false) {
					// A nested array or an object is not a reference. Left as
					// it is so the refusal names a reference, never a shape.
					continue;
				}

				$id = $this->resolveOne(reference: $item, kind: $kind);
				if ($id !== null) {
					$ids[] = $id;
				}
			}

			return $ids;
		}

		return $this->resolveOne(reference: $reference, kind: $kind);
	}//end resolve()

	/**
	 * Resolve a single reference to its numeric id.
	 *
	 * @param int|string $reference The id, uuid or slug.
	 * @param string     $kind      Either `register` or `schema`.
	 *
	 * @return int|null The numeric id, or null when the reference says nothing.
	 *
	 * @throws RegisterNotFoundException When a register reference names no register.
	 * @throws SchemaNotFoundException   When a schema reference names no schema.
	 */
	private function resolveOne(int|string $reference, string $kind): ?int {
		// An id already. The common case, and it costs no query.
		if (is_int($reference) === true && $reference > 0) {
			return $reference;
		}

		if (is_string($reference) === true) {
			$trimmed = trim($reference);

			// Says nothing, so it filters nothing. `null` was always what this
			// meant; `0` only ever meant it by accident, and then suppressed the
			// global fallbacks that a real null reaches.
			if ($trimmed === '') {
				return null;
			}

			if (ctype_digit($trimmed) === true && (int)$trimmed > 0) {
				return (int)$trimmed;
			}
		}

		// A slug, a uuid, a zero or a negative number: ask the mapper, the same
		// question the write path asks.
		$id = $this->lookUp(reference: $reference, kind: $kind);

		$this->logger->warning(
			message: '[SearchReferenceResolver] resolved a non-numeric '.$kind.' reference on a search; pass the numeric id to skip the lookup',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'reference' => $reference,
				'resolved' => $id,
			]
		);

		return $id;
	}//end resolveOne()

	/**
	 * Ask the mapper what a reference names.
	 *
	 * @param int|string $reference The id, uuid or slug.
	 * @param string     $kind      Either `register` or `schema`.
	 *
	 * @return int The numeric id.
	 *
	 * @throws RegisterNotFoundException When a register reference names no register.
	 * @throws SchemaNotFoundException   When a schema reference names no schema.
	 */
	private function lookUp(int|string $reference, string $kind): int {
		// Only "no such row" and "more than one row" become a refusal. A
		// database error is left to travel: answering "not found" for an
		// instance that is merely unreachable is the same lie in a new place.
		try {
			if ($kind === 'register') {
				return (int)$this->registerMapper->find((string)$reference, _rbac: false, _multitenancy: false)->getId();
			}

			return (int)$this->schemaMapper->find((string)$reference, _rbac: false, _multitenancy: false)->getId();
		} catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
			if ($kind === 'register') {
				throw new RegisterNotFoundException(
					registerSlugOrId: (string)$reference,
					code: 404,
					previous: $e,
					remedies: 'A search was scoped to this register. Pass a register id, uuid or slug that exists;'
					.' an unknown reference is refused rather than answered with an empty page.'
				);
			}

			throw new SchemaNotFoundException(schemaSlugOrId: (string)$reference, code: 404, previous: $e);
		}//end try
	}//end lookUp()
}//end class
