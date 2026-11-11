<?php

/**
 * SearchScopeFilters: the `@self` filters a search's register and schema arguments stand for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;

/**
 * Reads the register and schema a search is scoped to.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class SearchScopeFilters {
	/**
	 * Constructor.
	 *
	 * @param SearchReferenceResolver|null $resolver Resolves a slug or uuid; absent, only numeric ids are read.
	 */
	public function __construct(
		private readonly ?SearchReferenceResolver $resolver,
	) {
	}//end __construct()

	/**
	 * The `@self` register and schema filters of the call, single values or lists.
	 *
	 * A reference is resolved the way the write path resolves it, and refused the way the write
	 * path refuses it; an int cast turned a slug into register 0 and an empty page
	 * (openregister#3990).
	 *
	 * @param int|string|array|null $register The register reference.
	 * @param int|string|array|null $schema   The schema reference.
	 *
	 * @return array<string, mixed> The `@self` filters.
	 *
	 * @throws RegisterNotFoundException When the register reference cannot be read.
	 * @throws SchemaNotFoundException When the schema reference cannot be read.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function forScope(int|string|array|null $register, int|string|array|null $schema): array {
		$self = [];
		$registerId = $this->resolveReference(reference: $register, kind: 'register');
		if ($registerId !== null) {
			$self['register'] = $registerId;
		}

		$schemaId = $this->resolveReference(reference: $schema, kind: 'schema');
		if ($schemaId !== null) {
			$self['schema'] = $schemaId;
		}

		return $self;
	}//end forScope()

	/**
	 * Resolve a register or schema reference for the query being built.
	 *
	 * Delegates to {@see SearchReferenceResolver} when the container wired one.
	 * Without it — a handler built positionally in a unit test — the reference
	 * is still never int-cast into an empty page: what can be read as an id is
	 * read as one, what says nothing becomes `null` (which reaches the global
	 * fallbacks), and everything else is refused by name. That is the whole
	 * point of openregister#3990: a reference nobody can resolve must not look
	 * like a register with no objects in it.
	 *
	 * @param int|string|array|null $reference The register/schema id, uuid, slug, or a list.
	 * @param string $kind Either `register` or `schema`.
	 *
	 * @return int|array|null The numeric id(s), or null when the reference says nothing.
	 *
	 * @psalm-return int|array<int, int>|null
	 *
	 * @throws \OCA\OpenRegister\Exception\RegisterNotFoundException When a register reference names no register.
	 * @throws \OCA\OpenRegister\Exception\SchemaNotFoundException When a schema reference names no schema.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function resolveReference(int|string|array|null $reference, string $kind): int|array|null {
		if ($reference === null) {
			return null;
		}

		if ($this->resolver !== null) {
			if ($kind === 'register') {
				return $this->resolver->register(reference: $reference);
			}

			return $this->resolver->schema(reference: $reference);
		}

		if (is_array($reference) === true) {
			return $this->readReferenceList(references: $reference, kind: $kind);
		}

		return $this->readReference(reference: $reference, kind: $kind);
	}//end resolveReference()

	/**
	 * Read a list of references without a resolver.
	 *
	 * @param array<int|string, mixed> $references The references.
	 * @param string $kind Either `register` or `schema`.
	 *
	 * @return array<int, int> The ids the list names.
	 *
	 * @throws \OCA\OpenRegister\Exception\RegisterNotFoundException When a register reference names no register.
	 * @throws \OCA\OpenRegister\Exception\SchemaNotFoundException When a schema reference names no schema.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function readReferenceList(array $references, string $kind): array {
		$readable = array_filter($references, static fn (mixed $item): bool => is_int($item) || is_string($item));
		$ids = array_map(fn (int|string $item): ?int => $this->readReference(reference: $item, kind: $kind), $readable);

		return array_values(array_filter($ids, static fn (?int $id): bool => $id !== null));
	}//end readReferenceList()

	/**
	 * Read one reference without a resolver.
	 *
	 * Only what can be read as an id is read as one. Nothing is int-cast into
	 * an empty page, and nothing is resolved either, because there is no mapper
	 * here to ask.
	 *
	 * @param int|string $reference The id, uuid or slug.
	 * @param string $kind Either `register` or `schema`.
	 *
	 * @return int|null The id, or null when the reference says nothing.
	 *
	 * @throws \OCA\OpenRegister\Exception\RegisterNotFoundException When a register reference names no register.
	 * @throws \OCA\OpenRegister\Exception\SchemaNotFoundException When a schema reference names no schema.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function readReference(int|string $reference, string $kind): ?int {
		$text = new SearchReferenceText(reference: $reference);
		if ($text->isBlank() === true) {
			return null;
		}

		return ($text->positiveId() ?? throw $this->unreadableReference(reference: $text->text, kind: $kind));
	}//end readReference()

	/**
	 * The refusal of a reference this handler cannot read.
	 *
	 * @param string $reference The trimmed reference.
	 * @param string $kind      Either `register` or `schema`.
	 *
	 * @return RegisterNotFoundException|SchemaNotFoundException The refusal.
	 */
	private function unreadableReference(string $reference, string $kind): RegisterNotFoundException|SchemaNotFoundException {
		if ($kind === 'register') {
			return new RegisterNotFoundException(registerSlugOrId: $reference);
		}

		return new SchemaNotFoundException(schemaSlugOrId: $reference);
	}//end unreadableReference()
}//end class
