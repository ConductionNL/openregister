<?php

/**
 * Names of the GraphQL types and fields of one generated schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/graphql-api/spec.md
 */

namespace OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator;

/**
 * Turns schema slugs into GraphQL names and keeps type names unique within one schema.
 */
class GraphQLNames {

	/**
	 * Singular rules in order: suffix, shortest word it applies to, replacement.
	 *
	 * The `ss` rule keeps words like `glass` whole.
	 */
	private const SINGULAR_RULES = [
		['en', 5, ''],
		['ies', 0, 'y'],
		['es', 5, ''],
		['ss', 0, 'ss'],
		['s', 0, ''],
	];

	/**
	 * Schema ID each type name is taken by.
	 *
	 * @var array<string, int>
	 */
	private array $usedTypeNames = [];

	/**
	 * Convert a slug to a PascalCase GraphQL type name.
	 *
	 * @param string $slug The kebab-case or camelCase slug
	 * @param int|null $schemaId Schema ID for deduplication (optional)
	 *
	 * @return string The PascalCase type name
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function toTypeName(string $slug, ?int $schemaId = null): string {
		$name = $this->pascalCase(slug: $slug);
		if ($schemaId === null) {
			return $name;
		}

		return $this->claimTypeName(name: $name, schemaId: $schemaId);
	}//end toTypeName()

	/**
	 * Convert a slug to a valid GraphQL field name (camelCase).
	 *
	 * GraphQL names must match [_a-zA-Z][_a-zA-Z0-9]*.
	 * Converts kebab-case and snake_case to camelCase.
	 *
	 * @param string $slug The slug to convert
	 *
	 * @return string A valid GraphQL field name
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function toFieldName(string $slug): string {
		$parts = preg_split(pattern: '/[-_]/', subject: $slug);
		$first = array_shift($parts);
		$rest = array_map(
			callback: fn ($part) => ucfirst(string: $part),
			array: $parts
		);

		$name = $first . implode(separator: '', array: $rest);

		if (preg_match(pattern: '/^[_a-zA-Z]/', subject: $name) !== 1) {
			$name = '_' . $name;
		}

		return preg_replace(pattern: '/[^_a-zA-Z0-9]/', replacement: '', subject: $name);
	}//end toFieldName()

	/**
	 * Naive singularization for Dutch/English schema slugs.
	 *
	 * @param string $plural The plural form
	 *
	 * @return string The singular form
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function singularize(string $plural): string {
		foreach (self::SINGULAR_RULES as [$suffix, $shortest, $replacement]) {
			if (str_ends_with(haystack: $plural, needle: $suffix) === true && strlen(string: $plural) >= $shortest) {
				return substr(string: $plural, offset: 0, length: -strlen(string: $suffix)) . $replacement;
			}
		}

		return $plural;
	}//end singularize()

	/**
	 * Convert a slug to PascalCase that starts with a letter.
	 *
	 * @param string $slug The slug
	 *
	 * @return string The PascalCase name
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function pascalCase(string $slug): string {
		$name = str_replace(search: ['-', '_'], replace: ' ', subject: $slug);
		$name = ucwords(string: $name);
		$name = str_replace(search: ' ', replace: '', subject: $name);

		if (preg_match(pattern: '/^[A-Za-z]/', subject: $name) !== 1) {
			$name = 'Type' . $name;
		}

		return $name;
	}//end pascalCase()

	/**
	 * Take a type name for a schema, adding the ID when another schema holds the name.
	 *
	 * @param string $name The base type name
	 * @param int $schemaId The schema taking the name
	 *
	 * @return string The name now held by the schema
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function claimTypeName(string $name, int $schemaId): string {
		if (($this->usedTypeNames[$name] ?? $schemaId) !== $schemaId) {
			$name .= $schemaId;
		}

		$this->usedTypeNames[$name] = $schemaId;
		return $name;
	}//end claimTypeName()
}//end class
