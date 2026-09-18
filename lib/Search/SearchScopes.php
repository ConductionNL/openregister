<?php

/**
 * What a caller asked the unified search to look in.
 *
 * 🔴 A SCOPE NARROWS THE SCHEMA LIST BEFORE THE CHUNK LOOP, NEVER AFTER IT
 * (D-4). Filtering a page after the fan-out would make a scoped search cost
 * MORE than an unscoped one — the same union over every searchable table, plus
 * a discard — and it would break paging, because the page boundary would be cut
 * before the rows nobody asked for were removed. Narrowing first means a scoped
 * query is cheaper, never dearer, which is the whole reason a caller reaches
 * for one.
 *
 * 🔴 AN UNPARSEABLE SCOPE IS DROPPED, NOT IGNORED SILENTLY AND NOT FATAL.
 * `schema:` with nothing after it, or `colour:blue`, is a caller mistake; the
 * refusal is that it narrows nothing, and it is reported back in the parsed
 * result so a UI can say which chip it could not honour. Treating it as "search
 * everything" would answer the wrong question confidently, and throwing would
 * make one bad chip empty the search bar.
 *
 * 🔑 `files` IS NOT A PLACE, IT IS A KIND. The other three name where to look;
 * `files` says which hits to keep. Folding it into the same vocabulary is what
 * lets a UI render four chips that behave alike, and keeping it a separate
 * FIELD here is what stops it being compared against a schema slug.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search;

/**
 * Parse and hold the scopes of one search.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md
 */
final class SearchScopes {

	/**
	 * The prefixes a scope can carry, and there are no others.
	 *
	 * @var array<int, string>
	 */
	public const PREFIXES = ['app', 'register', 'schema'];

	/**
	 * The bare scope that keeps file hits only.
	 *
	 * @var string
	 */
	public const FILES = 'files';

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $apps      App ids to keep.
	 * @param array<int, string> $registers Register slugs to keep.
	 * @param array<int, string> $schemas   Schema slugs to keep.
	 * @param boolean            $filesOnly Whether only file hits were asked for.
	 * @param array<int, string> $unparsed  Scopes that named nothing this search understands.
	 */
	private function __construct(
		public readonly array $apps,
		public readonly array $registers,
		public readonly array $schemas,
		public readonly bool $filesOnly,
		public readonly array $unparsed,
	) {
	}//end __construct()

	/**
	 * Read the scopes a caller declared.
	 *
	 * @param mixed $raw A comma-separated string, or a list of scopes.
	 *
	 * @return self The scopes.
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public static function parse(mixed $raw): self {
		$apps = [];
		$registers = [];
		$schemas = [];
		$filesOnly = false;
		$unparsed = [];

		foreach (self::tokens(raw: $raw) as $token) {
			if ($token === self::FILES) {
				$filesOnly = true;
				continue;
			}

			$at = strpos($token, ':');
			if ($at === false) {
				$unparsed[] = $token;
				continue;
			}

			$prefix = substr($token, 0, $at);
			$value = trim(substr($token, ($at + 1)));
			if ($value === '' || in_array($prefix, self::PREFIXES, true) === false) {
				$unparsed[] = $token;
				continue;
			}

			match ($prefix) {
				'app' => $apps[] = $value,
				'register' => $registers[] = $value,
				'schema' => $schemas[] = $value,
			};
		}//end foreach

		return new self(
			apps: array_values(array_unique($apps)),
			registers: array_values(array_unique($registers)),
			schemas: array_values(array_unique($schemas)),
			filesOnly: $filesOnly,
			unparsed: array_values(array_unique($unparsed)),
		);
	}//end parse()

	/**
	 * Whether anything was asked for at all.
	 *
	 * 🔑 AN UNPARSEABLE SCOPE DOES NOT MAKE A SEARCH SCOPED. A query carrying
	 * only `colour:blue` narrows nothing, so it must read as unscoped rather
	 * than as "scoped to nothing" — which would answer an empty page to a
	 * caller who simply mistyped, and look exactly like a search that found
	 * nothing.
	 *
	 * @return boolean True when at least one scope narrows something.
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function narrows(): bool {
		return ($this->apps !== [] || $this->registers !== [] || $this->schemas !== [] || $this->filesOnly === true);
	}//end narrows()

	/**
	 * Keep only the schemas these scopes name.
	 *
	 * A scope set that names no schema and no register leaves the list alone:
	 * `files` alone is about which HITS to keep, not where to look, and an
	 * `app:` scope is resolved by the caller into register slugs before it
	 * reaches here.
	 *
	 * @param array<int, array{id: int, slug: string, register: string}> $schemas The searchable schemas.
	 *
	 * @return array<int, int> The schema ids to search.
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function narrowSchemas(array $schemas): array {
		if ($this->schemas === [] && $this->registers === []) {
			return array_values(array_map(static fn (array $s): int => (int)$s['id'], $schemas));
		}

		$kept = [];
		foreach ($schemas as $schema) {
			$bySchema = ($this->schemas !== [] && in_array((string)$schema['slug'], $this->schemas, true) === true);
			$byRegister = ($this->registers !== [] && in_array((string)$schema['register'], $this->registers, true) === true);

			// OR, not AND. Two chips are two things the reader wants to see,
			// and intersecting them answers an empty page to somebody who
			// asked for more rather than less.
			if ($bySchema === true || $byRegister === true) {
				$kept[] = (int)$schema['id'];
			}
		}

		return array_values(array_unique($kept));
	}//end narrowSchemas()

	/**
	 * The scopes as a client reads them back.
	 *
	 * @return array<string, mixed> The scopes.
	 */
	public function jsonSerialize(): array {
		return [
			'apps' => $this->apps,
			'registers' => $this->registers,
			'schemas' => $this->schemas,
			'filesOnly' => $this->filesOnly,
			'unparsed' => $this->unparsed,
		];
	}//end jsonSerialize()

	/**
	 * Split whatever arrived into trimmed, lower-cased tokens.
	 *
	 * @param mixed $raw The caller's value.
	 *
	 * @return array<int, string> The tokens.
	 */
	private static function tokens(mixed $raw): array {
		$parts = [];
		if (is_string($raw) === true) {
			$parts = explode(',', $raw);
		}

		if (is_array($raw) === true) {
			$parts = $raw;
		}

		$tokens = [];
		foreach ($parts as $part) {
			$token = strtolower(trim((string)$part));
			if ($token !== '') {
				$tokens[] = $token;
			}
		}

		return $tokens;
	}//end tokens()
}//end class
