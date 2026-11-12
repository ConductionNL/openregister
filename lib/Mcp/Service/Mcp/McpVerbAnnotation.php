<?php

/**
 * OpenRegister MCP Verb Annotation
 *
 * One verb entry of the `x-openregister-mcp` schema annotation's `tools`
 * object, with the checks of its config.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Mcp\Service\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Service\Mcp;

/**
 * One verb of the `tools` object and its config, validated against the schema's properties.
 *
 * @spec openspec/specs/ai-mcp/spec.md
 *   (Requirement: REQ-DIALECT-002 — Save-time validation of the dialect shape)
 */
final class McpVerbAnnotation {

	/**
	 * The verb key as declared.
	 *
	 * @var string
	 */
	private string $verb;

	/**
	 * The verb's config as declared (an object when well-formed).
	 *
	 * @var mixed
	 */
	private mixed $config;

	/**
	 * The schema's declared properties, for the `filters` cross-check.
	 *
	 * @var array<string|int, mixed>
	 */
	private array $properties;

	/**
	 * Constructor
	 *
	 * @param string               $verb       The verb key as declared.
	 * @param mixed                $config     The verb's config as declared.
	 * @param array<string, mixed> $properties The schema's declared properties.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function __construct(string $verb, mixed $config, array $properties) {
		$this->verb = $verb;
		$this->config = $config;
		$this->properties = $properties;
	}//end __construct()

	/**
	 * The errors of this verb: an unknown verb, a config that is not an object, or the errors of its keys.
	 *
	 * @return array<int, array{code: string, message: string}> The errors (empty = valid).
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Scenario: Unknown verb key is rejected; Scenario: The verb set is closed)
	 */
	public function errors(): array {
		if (in_array($this->verb, McpAnnotationValidator::VERBS, true) === false) {
			$allowed = implode(', ', McpAnnotationValidator::VERBS);
			$message = sprintf('x-openregister-mcp: unrecognised verb "%s". Allowed verbs: %s.', $this->verb, $allowed);
			return [['code' => 'mcp-unknown-verb', 'message' => $message]];
		}

		if (is_array($this->config) === false) {
			$message = sprintf('x-openregister-mcp: verb "%s" config must be an object.', $this->verb);
			return [['code' => 'mcp-bad-verb-config', 'message' => $message]];
		}

		return $this->configErrors(config: $this->config);
	}//end errors()

	/**
	 * The errors of the config's keys: unknown keys, description, scope, hints and filters, in that order.
	 *
	 * @param array<string|int, mixed> $config The verb's config object.
	 *
	 * @return array<int, array{code: string, message: string}> The errors.
	 */
	private function configErrors(array $config): array {
		return array_merge(
			$this->unknownKeyErrors(config: $config),
			$this->descriptionErrors(config: $config),
			$this->scopeErrors(config: $config),
			$this->hintErrors(config: $config),
			$this->filtersErrors(config: $config)
		);
	}//end configErrors()

	/**
	 * One error per config key outside description, scope, filters and the hints.
	 *
	 * @param array<string|int, mixed> $config The verb's config object.
	 *
	 * @return array<int, array{code: string, message: string}> The errors.
	 */
	private function unknownKeyErrors(array $config): array {
		$knownKeys = array_merge(['description', 'scope', 'filters'], McpAnnotationValidator::HINT_KEYS);
		$errors = [];
		foreach (array_keys($config) as $key) {
			if (in_array((string)$key, $knownKeys, true) === false) {
				$message = sprintf('x-openregister-mcp: verb "%s" has an unrecognised key "%s".', $this->verb, (string)$key);
				$errors[] = ['code' => 'mcp-unknown-key', 'message' => $message];
			}
		}

		return $errors;
	}//end unknownKeyErrors()

	/**
	 * The error of a `description` that is present and not a string.
	 *
	 * @param array<string|int, mixed> $config The verb's config object.
	 *
	 * @return array<int, array{code: string, message: string}> The errors.
	 */
	private function descriptionErrors(array $config): array {
		if (array_key_exists('description', $config) === false || is_string($config['description']) === true) {
			return [];
		}

		$message = sprintf('x-openregister-mcp: verb "%s" `description` must be a string.', $this->verb);
		return [['code' => 'mcp-bad-description', 'message' => $message]];
	}//end descriptionErrors()

	/**
	 * The error of a `scope` that is present and not one of the allowed scopes.
	 *
	 * @param array<string|int, mixed> $config The verb's config object.
	 *
	 * @return array<int, array{code: string, message: string}> The errors.
	 */
	private function scopeErrors(array $config): array {
		if (array_key_exists('scope', $config) === false || in_array($config['scope'], McpAnnotationValidator::SCOPES, true) === true) {
			return [];
		}

		$scopes = implode(', ', McpAnnotationValidator::SCOPES);
		$message = sprintf('x-openregister-mcp: verb "%s" `scope` must be one of [%s].', $this->verb, $scopes);
		return [['code' => 'mcp-bad-scope', 'message' => $message]];
	}//end scopeErrors()

	/**
	 * One error per annotation hint that is present and not a boolean.
	 *
	 * @param array<string|int, mixed> $config The verb's config object.
	 *
	 * @return array<int, array{code: string, message: string}> The errors.
	 */
	private function hintErrors(array $config): array {
		$errors = [];
		foreach (McpAnnotationValidator::HINT_KEYS as $hintKey) {
			if (array_key_exists($hintKey, $config) === true && is_bool($config[$hintKey]) === false) {
				$message = sprintf('x-openregister-mcp: verb "%s" `%s` must be a boolean.', $this->verb, $hintKey);
				$errors[] = ['code' => 'mcp-bad-hint', 'message' => $message];
			}
		}

		return $errors;
	}//end hintErrors()

	/**
	 * Validate the `filters` key: permitted on `search` only, a list of
	 * strings, each naming an existing schema property.
	 *
	 * @param array<string|int, mixed> $config The verb's config object.
	 *
	 * @return array<int, array{code: string, message: string}> The errors.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Scenario: search filter must reference an existing property; Scenario: filters are permitted only on the search verb)
	 */
	private function filtersErrors(array $config): array {
		if (array_key_exists('filters', $config) === false) {
			return [];
		}

		if ($this->verb !== 'search') {
			$message = sprintf('x-openregister-mcp: `filters` is valid on the "search" verb only (found on "%s").', $this->verb);
			return [['code' => 'mcp-filters-not-search', 'message' => $message]];
		}

		return $this->filterListErrors(filters: $config['filters']);
	}//end filtersErrors()

	/**
	 * The errors of the `search.filters` value: not a list, or the errors of its entries.
	 *
	 * @param mixed $filters The `search.filters` value.
	 *
	 * @return array<int, array{code: string, message: string}> The errors.
	 */
	private function filterListErrors(mixed $filters): array {
		if (is_array($filters) === false) {
			return [['code' => 'mcp-bad-filters', 'message' => 'x-openregister-mcp: `search.filters` must be a list of property names.']];
		}

		return array_merge([], ...array_map(fn (mixed $filter): array => $this->filterErrors(filter: $filter), array_values($filters)));
	}//end filterListErrors()

	/**
	 * The error of one `search.filters` entry: not a string, or not a property of the schema.
	 *
	 * @param mixed $filter The filter entry.
	 *
	 * @return array<int, array{code: string, message: string}> The errors.
	 */
	private function filterErrors(mixed $filter): array {
		if (is_string($filter) === false) {
			return [['code' => 'mcp-bad-filters', 'message' => 'x-openregister-mcp: `search.filters` entries must be strings.']];
		}

		if (array_key_exists($filter, $this->properties) === true) {
			return [];
		}

		$message = sprintf('x-openregister-mcp: `search.filters` names "%s" which is not a property on this schema.', $filter);
		return [['code' => 'mcp-unknown-filter-property', 'message' => $message]];
	}//end filterErrors()
}//end class
