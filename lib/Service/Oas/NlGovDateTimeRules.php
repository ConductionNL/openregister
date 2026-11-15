<?php

/**
 * The NLGov date and time rules, checked on the schemas of a generated OpenAPI document.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

/**
 * Checks /core/date-time/format and /core/date-time/date-omit-time-portion on
 * every schema under `components.schemas`, nested properties, items and
 * compositions included.
 *
 * A property fails /core/date-time/format when it names a date or time format
 * that is not one of RFC 3339's (`date`, `date-time`, `time`), when it puts
 * such a format on a type other than string, or when its example or default is
 * not written in that format. A `date` whose example or default carries a time
 * fails /core/date-time/date-omit-time-portion instead.
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */
final class NlGovDateTimeRules {

	/**
	 * The RFC 3339 formats, each with the pattern a value in it matches.
	 *
	 * @var array<string, string>
	 */
	private const RFC_FORMATS = [
		'date'      => '/^\d{4}-\d{2}-\d{2}$/',
		'date-time' => '/^\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/',
		'time'      => '/^\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})?$/',
	];

	/**
	 * Date and time formats that are not RFC 3339's, compared without case, `-` or `_`.
	 *
	 * @var list<string>
	 */
	private const OTHER_DATE_FORMATS = ['datetime', 'datetimelocal', 'timestamp', 'unixtime', 'epoch'];

	/**
	 * The keys under which a schema holds further schemas, as lists.
	 *
	 * @var list<string>
	 */
	private const LIST_KEYS = ['allOf', 'anyOf', 'oneOf'];

	/**
	 * Constructor.
	 *
	 * @param NlGovFindings $findings Where the findings go.
	 */
	public function __construct(
		private readonly NlGovFindings $findings,
	) {
	}//end __construct()

	/**
	 * Check every component schema of a document.
	 *
	 * @param array<string, mixed> $oas The OpenAPI document.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public function check(array $oas): void {
		$schemas = ($oas['components']['schemas'] ?? null);
		if (is_array($schemas) === false) {
			return;
		}

		foreach ($schemas as $name => $schema) {
			$this->walk(schema: $schema, path: 'components.schemas.'.$name);
		}
	}//end check()

	/**
	 * Check one schema, then every schema under it.
	 *
	 * @param mixed  $schema The schema.
	 * @param string $path   Where it is in the document.
	 *
	 * @return void
	 */
	private function walk(mixed $schema, string $path): void {
		if (is_array($schema) === false) {
			return;
		}

		if (is_string($schema['format'] ?? null) === true) {
			$this->checkFormat(schema: $schema, path: $path);
		}

		foreach ($this->children(schema: $schema, path: $path) as $childPath => $child) {
			$this->walk(schema: $child, path: $childPath);
		}
	}//end walk()

	/**
	 * The schemas directly under one schema, by their path.
	 *
	 * @param array<string, mixed> $schema The schema.
	 * @param string               $path   Its path.
	 *
	 * @return array<string, mixed> The child schemas.
	 */
	private function children(array $schema, string $path): array {
		$children = [];
		foreach (self::listOf(value: ($schema['properties'] ?? null)) as $name => $property) {
			$children[$path.'.properties.'.$name] = $property;
		}

		foreach (['items', 'additionalProperties'] as $key) {
			$children[$path.'.'.$key] = ($schema[$key] ?? null);
		}

		foreach (self::LIST_KEYS as $key) {
			foreach (self::listOf(value: ($schema[$key] ?? null)) as $index => $member) {
				$children[$path.'.'.$key.'.'.$index] = $member;
			}
		}

		return $children;
	}//end children()

	/**
	 * Check the format of one schema, its type and its example and default.
	 *
	 * @param array<string, mixed> $schema The schema, which has a string `format`.
	 * @param string               $path   Where it is in the document.
	 *
	 * @return void
	 */
	private function checkFormat(array $schema, string $path): void {
		$format = $schema['format'];
		if (isset(self::RFC_FORMATS[$format]) === false) {
			if (in_array(strtolower(str_replace(['-', '_'], '', $format)), self::OTHER_DATE_FORMATS, true) === true) {
				$this->findings->add(
					ruleId: '/core/date-time/format',
					path: $path.'.format',
					message: sprintf('The format "%s" is not an RFC 3339 format (date, date-time or time).', $format)
				);
			}

			return;
		}

		$type = ($schema['type'] ?? 'string');
		if (in_array('string', (array)$type, true) === false) {
			$this->findings->add(
				ruleId: '/core/date-time/format',
				path: $path.'.type',
				message: sprintf('The format "%s" is declared on type "%s", not on a string.', $format, implode('|', (array)$type))
			);
		}

		foreach (['example', 'default'] as $key) {
			if (is_string($schema[$key] ?? null) === true) {
				$this->checkValue(format: $format, value: $schema[$key], path: $path.'.'.$key);
			}
		}
	}//end checkFormat()

	/**
	 * Check one example or default against its RFC 3339 format.
	 *
	 * @param string $format The format.
	 * @param string $value  The value.
	 * @param string $path   Where it is in the document.
	 *
	 * @return void
	 */
	private function checkValue(string $format, string $value, string $path): void {
		if (preg_match(self::RFC_FORMATS[$format], $value) === 1) {
			return;
		}

		if ($format === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}[Tt ]/', $value) === 1) {
			$this->findings->add(
				ruleId: '/core/date-time/date-omit-time-portion',
				path: $path,
				message: sprintf('The date "%s" carries a time portion.', $value)
			);
			return;
		}

		$this->findings->add(
			ruleId: '/core/date-time/format',
			path: $path,
			message: sprintf('The value "%s" is not an RFC 3339 %s.', $value, $format)
		);
	}//end checkValue()

	/**
	 * The value when it is an array, else an empty array.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<mixed> The array.
	 */
	private static function listOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end listOf()
}//end class
