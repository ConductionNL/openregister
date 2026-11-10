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

use Exception;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
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
	 * @param SchemaCatalogInterface $catalog Resolves a register or schema reference.
	 * @param LoggerInterface        $logger  Records each reference that had to be resolved.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		private readonly SchemaCatalogInterface $catalog,
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
		foreach (self::REGISTER_KEYS as $spelling) {
			$query = $this->normaliseKey(query: $query, place: new SearchReferenceKey(spelling: $spelling, kind: 'register'));
		}

		foreach (self::SCHEMA_KEYS as $spelling) {
			$query = $this->normaliseKey(query: $query, place: new SearchReferenceKey(spelling: $spelling, kind: 'schema'));
		}

		return $query;
	}//end normaliseQuery()

	/**
	 * Resolve one key of a query array in place.
	 *
	 * @param array              $query The search query.
	 * @param SearchReferenceKey $place Where the reference sits and what it names.
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
	 */
	private function normaliseKey(array $query, SearchReferenceKey $place): array {
		$value = $place->readFrom(query: $query);
		if ($value === null) {
			return $query;
		}

		return $place->writeTo(query: $query, resolved: $this->resolve(reference: $value, kind: $place->kind));
	}//end normaliseKey()

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
			return $this->resolveList(references: $reference, kind: $kind);
		}

		return $this->resolveOne(reference: $reference, kind: $kind);
	}//end resolve()

	/**
	 * Resolve a list of references, leaving out what says nothing.
	 *
	 * A nested array or an object is not a reference. It is skipped, so a
	 * refusal always names a reference and never a shape.
	 *
	 * @param array  $references The references.
	 * @param string $kind       Either `register` or `schema`.
	 *
	 * @return array<int, int> The numeric ids.
	 *
	 * @throws RegisterNotFoundException When a register reference names no register.
	 * @throws SchemaNotFoundException   When a schema reference names no schema.
	 */
	private function resolveList(array $references, string $kind): array {
		$ids = [];
		foreach ($references as $item) {
			if (is_int($item) === true || is_string($item) === true) {
				$ids[] = $this->resolveOne(reference: $item, kind: $kind);
			}
		}

		return array_values(array_filter($ids, static fn (?int $id): bool => $id !== null));
	}//end resolveList()

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
		// Says nothing, so it filters nothing. `null` was always what this
		// meant; `0` only ever meant it by accident, and then suppressed the
		// global fallbacks that a real null reaches.
		$text = new SearchReferenceText(reference: $reference);
		if ($text->isBlank() === true) {
			return null;
		}

		// An id already. The common case, and it costs no query.
		return ($text->positiveId() ?? $this->lookUpAndWarn(reference: $reference, kind: $kind));
	}//end resolveOne()

	/**
	 * Resolve a slug, a uuid, a zero or a negative number the way the write path does, and say so.
	 *
	 * @param int|string $reference The id, uuid or slug.
	 * @param string     $kind      Either `register` or `schema`.
	 *
	 * @return int The numeric id.
	 *
	 * @throws RegisterNotFoundException When a register reference names no register.
	 * @throws SchemaNotFoundException   When a schema reference names no schema.
	 */
	private function lookUpAndWarn(int|string $reference, string $kind): int {
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
	}//end lookUpAndWarn()

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
				return (int)$this->catalog->registers()->find((string)$reference, _rbac: false, _multitenancy: false)->getId();
			}

			return (int)$this->catalog->schemas()->find((string)$reference, _rbac: false, _multitenancy: false)->getId();
		} catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
			if ($kind === 'register') {
				throw $this->noSuchRegister(reference: (string)$reference, cause: $e);
			}

			throw new SchemaNotFoundException(schemaSlugOrId: (string)$reference, code: 404, previous: $e);
		}//end try
	}//end lookUp()

	/**
	 * The refusal of a register reference that names no register.
	 *
	 * @param string    $reference The reference.
	 * @param Exception $cause     What the mapper raised.
	 *
	 * @return RegisterNotFoundException The refusal.
	 */
	private function noSuchRegister(string $reference, Exception $cause): RegisterNotFoundException {
		return new RegisterNotFoundException(
			registerSlugOrId: $reference,
			code: 404,
			previous: $cause,
			remedies: 'A search was scoped to this register. Pass a register id, uuid or slug that exists;'
			.' an unknown reference is refused rather than answered with an empty page.'
		);
	}//end noSuchRegister()
}//end class
