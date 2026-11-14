<?php

/**
 * SearchSnippetAttacher — `@self.snippet` on the rows of a search that asked `_snippet=true`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md#requirement-a-result-shows-the-passage-that-matched-only-from-what-the-reader-may-see-req-sdl-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

use JsonSerializable;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use Throwable;

/**
 * Adds the matched passage to each row of a paginated search, only when asked.
 *
 * 🔑 WITHOUT `_snippet=true` NOTHING CHANGES. The result is returned as it came,
 * rows and all, so ZKN-CONTENT-003's row shape holds for every caller that does
 * not ask.
 *
 * 🔑 THE PASSAGE COMES FROM THE ROW ITSELF. A row is serialised the way the
 * response would serialise it (after `_fields`, write-only redaction and
 * property read rules), and the snippet is taken from those values only, so it
 * can never quote what the row does not already show. On top of that the
 * schema's definitions keep a write-only or encrypted property out, and a row
 * whose schema cannot be read gets no snippet (fail closed).
 *
 * 🔑 NO CONTENT SNIPPET YET. A content snippet needs the anonymised copy's
 * verification verdict (`redaction-release-safeguards`); until a file carries
 * one, a content hit gets no snippet (task 2.4), so only property hits are
 * quoted here.
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md#requirement-a-result-shows-the-passage-that-matched-only-from-what-the-reader-may-see-req-sdl-002
 */
final class SearchSnippetAttacher {

	/**
	 * The request flag.
	 *
	 * @var string
	 */
	public const FLAG = '_snippet';

	/**
	 * Schema property definitions by schema id; null when the schema could not be read.
	 *
	 * @var array<string, array<string, array<string, mixed>>|null>
	 */
	private array $properties = [];

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface $schemas  Where a row's schema definitions are read.
	 * @param SnippetBuilder        $snippets Builds the passage under the safety rules.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemas,
		private readonly SnippetBuilder $snippets = new SnippetBuilder(),
	) {
	}//end __construct()

	/**
	 * Whether a query asks for snippets: `_snippet` true, `"true"` or `"1"`, and a search term.
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return bool True when snippets are wanted.
	 *
	 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md#requirement-a-result-shows-the-passage-that-matched-only-from-what-the-reader-may-see-req-sdl-002
	 */
	public static function asked(array $query): bool {
		$flag = ($query[self::FLAG] ?? false);
		$on   = ($flag === true || $flag === 1 || (is_string($flag) === true && in_array(strtolower($flag), ['true', '1'], true) === true));

		return $on === true && is_string($query['_search'] ?? null) === true && trim($query['_search']) !== '';
	}//end asked()

	/**
	 * The result with `@self.snippet` on every row that has a quotable match; unchanged when not asked.
	 *
	 * @param array<string, mixed> $result The paginated search result.
	 * @param array<string, mixed> $query  The search query.
	 *
	 * @return array<string, mixed> The result.
	 *
	 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md#requirement-a-result-shows-the-passage-that-matched-only-from-what-the-reader-may-see-req-sdl-002
	 */
	public function attach(array $result, array $query): array {
		if (self::asked(query: $query) === false || is_array($result['results'] ?? null) === false) {
			return $result;
		}

		$search = trim((string) $query['_search']);
		foreach ($result['results'] as $key => $row) {
			$result['results'][$key] = $this->withSnippet(row: $row, search: $search);
		}

		return $result;
	}//end attach()

	/**
	 * One row, serialised, with its snippet when one may be quoted.
	 *
	 * @param mixed  $row    The row: an entity or its array.
	 * @param string $search The search term.
	 *
	 * @return mixed The row as an array, or as it came when it is neither.
	 */
	private function withSnippet(mixed $row, string $search): mixed {
		if ($row instanceof JsonSerializable) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return $row;
		}

		$properties = $this->propertiesOf(schema: ($row['@self']['schema'] ?? null));
		if ($properties === null) {
			return $row;
		}

		$values = $row;
		unset($values['@self']);
		$snippet = $this->snippets->forProperty(search: $search, object: $values, properties: $properties);
		if ($snippet !== null) {
			$row['@self']['snippet'] = $snippet;
		}

		return $row;
	}//end withSnippet()

	/**
	 * The property definitions of a row's schema, read once per schema; null when unknown or unreadable.
	 *
	 * @param mixed $schema The row's `@self.schema`: an id, a slug, or an extended schema with an `id`.
	 *
	 * @return array<string, array<string, mixed>>|null The definitions in schema order.
	 */
	private function propertiesOf(mixed $schema): ?array {
		if (is_array($schema) === true) {
			$schema = ($schema['id'] ?? null);
		}

		if (is_int($schema) === false && (is_string($schema) === false || $schema === '')) {
			return null;
		}

		$key = (string) $schema;
		if (array_key_exists($key, $this->properties) === false) {
			try {
				$found = $this->schemas->find(id: $schema, _rbac: false, _multitenancy: false)->getProperties();
				$this->properties[$key] = array_filter((array) $found, 'is_array');
			} catch (Throwable $e) {
				$this->properties[$key] = null;
			}
		}

		return $this->properties[$key];
	}//end propertiesOf()
}//end class
