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
	 * App ids to keep.
	 *
	 * @var array<int, string>
	 */
	public readonly array $apps;

	/**
	 * Register slugs to keep.
	 *
	 * @var array<int, string>
	 */
	public readonly array $registers;

	/**
	 * Schema slugs to keep.
	 *
	 * @var array<int, string>
	 */
	public readonly array $schemas;

	/**
	 * Constructor.
	 *
	 * @param SearchScopeTargets $targets   The apps, registers and schemas to keep.
	 * @param boolean            $filesOnly Whether only file hits were asked for.
	 * @param array<int, string> $unparsed  Scopes that named nothing this search understands.
	 */
	private function __construct(
		SearchScopeTargets $targets,
		public readonly bool $filesOnly,
		public readonly array $unparsed,
	) {
		$this->apps      = $targets->apps;
		$this->registers = $targets->registers;
		$this->schemas   = $targets->schemas;
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
		$found = ['app' => [], 'register' => [], 'schema' => [], self::FILES => [], 'unparsed' => []];
		foreach (self::tokens(raw: $raw) as $token) {
			[$bucket, $value] = self::classify(token: $token);
			$found[$bucket][] = $value;
		}

		return new self(
			targets: new SearchScopeTargets(
				apps: self::distinct(values: $found['app']),
				registers: self::distinct(values: $found['register']),
				schemas: self::distinct(values: $found['schema'])
			),
			filesOnly: $found[self::FILES] !== [],
			unparsed: self::distinct(values: $found['unparsed']),
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
			return array_values(array_map(static fn (array $row): int => (int)$row['id'], $schemas));
		}

		$kept = [];
		foreach ($schemas as $schema) {
			if ($this->keeps(schema: $schema) === true) {
				$kept[] = (int)$schema['id'];
			}
		}

		return array_values(array_unique($kept));
	}//end narrowSchemas()

	/**
	 * Whether a schema row is named by a schema scope or a register scope.
	 *
	 * OR, not AND. Two chips are two things the reader wants to see, and
	 * intersecting them answers an empty page to somebody who asked for more
	 * rather than less. A row's slug is only read when a schema scope exists,
	 * and its register only when a register scope exists.
	 *
	 * @param array{id: int, slug: string, register: string} $schema One searchable schema.
	 *
	 * @return boolean True when the row is kept.
	 */
	private function keeps(array $schema): bool {
		$bySchema = ($this->schemas !== [] && in_array((string)$schema['slug'], $this->schemas, true) === true);
		$byRegister = ($this->registers !== [] && in_array((string)$schema['register'], $this->registers, true) === true);

		return $bySchema === true || $byRegister === true;
	}//end keeps()

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
		$tokens = [];
		foreach (self::parts(raw: $raw) as $part) {
			$token = strtolower(trim((string)$part));
			if ($token !== '') {
				$tokens[] = $token;
			}
		}

		return $tokens;
	}//end tokens()

	/**
	 * The raw parts of whatever arrived: a comma-split string, a list as-is, or nothing.
	 *
	 * @param mixed $raw The caller's value.
	 *
	 * @return array<int|string, mixed> The untrimmed parts.
	 */
	private static function parts(mixed $raw): array {
		if (is_string($raw) === true) {
			return explode(',', $raw);
		}

		if (is_array($raw) === true) {
			return $raw;
		}

		return [];
	}//end parts()

	/**
	 * Sort one token into the bucket it fills, with the value it carries.
	 *
	 * `files` fills its own bucket; a token without a colon is unparsed.
	 *
	 * @param string $token One trimmed, lower-cased token.
	 *
	 * @return array{string, string} The bucket key and the value to add to it.
	 */
	private static function classify(string $token): array {
		if ($token === self::FILES) {
			return [self::FILES, $token];
		}

		$colonAt = strpos($token, ':');
		if ($colonAt === false) {
			return ['unparsed', $token];
		}

		return self::classifyPrefixed(token: $token, colonAt: $colonAt);
	}//end classify()

	/**
	 * Sort a `prefix:value` token: a known prefix with a non-empty value fills
	 * that prefix's bucket, anything else is unparsed.
	 *
	 * @param string $token   One trimmed, lower-cased token.
	 * @param int    $colonAt The position of its first colon.
	 *
	 * @return array{string, string} The bucket key and the value to add to it.
	 */
	private static function classifyPrefixed(string $token, int $colonAt): array {
		$prefix = substr($token, 0, $colonAt);
		$value = trim(substr($token, ($colonAt + 1)));
		if ($value === '' || in_array($prefix, self::PREFIXES, true) === false) {
			return ['unparsed', $token];
		}

		return [$prefix, $value];
	}//end classifyPrefixed()

	/**
	 * Drop repeats, keeping first occurrences, and reindex.
	 *
	 * @param array<int, string> $values The values.
	 *
	 * @return array<int, string> The distinct values.
	 */
	private static function distinct(array $values): array {
		return array_values(array_unique($values));
	}//end distinct()
}//end class
