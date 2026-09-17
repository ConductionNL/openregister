<?php

/**
 * The links out of a record, built from the record's own values.
 *
 * WHY THIS EXISTS. A caseworker holding a case has to open the same address in
 * the GIS, in the BAG viewer and in the financial system. A menu entry cannot
 * do that: a menu entry is the same for every record, and the question is
 * always about THIS record's address. So dossiq's own note reads
 * "src/menu-layout.json holds app links; no per-record substitution", and the
 * caseworker copies an identifier between two browser tabs by hand.
 *
 * Declaring the template on the schema means one mechanism serves every app
 * and a leaf app configures rather than codes (design D-1).
 *
 * 🔴 AN UNFILLABLE PLACEHOLDER HIDES THE LINK. A URL with `{bagId}` still in
 * it is a broken link that looks exactly like a working one: it renders, it is
 * blue, it is clickable, and it fails only once somebody clicks it and reads a
 * stranger's 404. Offering nothing is the honest answer, and a `condition` on
 * the declaration is how an author says "only when this holds" deliberately
 * rather than relying on a value happening to be absent (design D-2).
 *
 * 🔑 IT RESOLVES, IT DOES NOT FETCH. Nothing here calls the system on the
 * other end. A link is an offer to the person reading the record, and probing
 * every declared target on every render would turn one object read into five
 * outbound calls to systems this instance does not control.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExternalLink
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExternalLink;

/**
 * Turns a schema's declared external links into the links one object offers.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExternalLink
 */
class ExternalLinkResolver {

	/**
	 * The schema configuration key carrying the declarations.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-external-links';

	/**
	 * How deep a placeholder may reach into the object.
	 *
	 * `{adres.bagId}` is a reasonable thing to write. A placeholder ten levels
	 * down is a sign the template is doing work a computed property should do,
	 * and an unbounded walk over caller-supplied data is a denial of service
	 * with a friendly name.
	 *
	 * @var integer
	 */
	private const MAX_PLACEHOLDER_DEPTH = 6;

	/**
	 * The placeholder grammar: `{name}` or `{parent.child}`.
	 *
	 * @var string
	 */
	private const PLACEHOLDER_PATTERN = '/\{([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)\}/';

	/**
	 * The links one object offers.
	 *
	 * @param array<int, mixed> $declarations The schema's declared links.
	 * @param array<string, mixed> $object The object's own values.
	 *
	 * @return array<int, array<string, string>> The offered links, each with a title and a url.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#requirement-a-schema-declares-links-out-of-its-objects-req-avs-001
	 */
	public function resolve(array $declarations, array $object): array {
		$links = [];
		foreach ($declarations as $declaration) {
			if (is_array($declaration) === false) {
				continue;
			}

			$link = $this->resolveOne(declaration: $declaration, object: $object);
			if ($link !== null) {
				$links[] = $link;
			}
		}

		return $links;

	}//end resolve()

	/**
	 * The placeholder names a template uses.
	 *
	 * @param string $template The URL template.
	 *
	 * @return array<int, string> The placeholder paths, in order of first appearance.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
	 */
	public static function placeholdersIn(string $template): array {
		$matched = [];
		if (preg_match_all(self::PLACEHOLDER_PATTERN, $template, $matched) === false) {
			return [];
		}

		return array_values(array_unique($matched[1]));

	}//end placeholdersIn()

	/**
	 * One declaration against one object, or null when it is not offered.
	 *
	 * @param array<string, mixed> $declaration The declaration.
	 * @param array<string, mixed> $object The object's own values.
	 *
	 * @return array<string, string>|null The link, or null.
	 */
	private function resolveOne(array $declaration, array $object): ?array {
		$title = trim((string)($declaration['title'] ?? ''));
		$template = trim((string)($declaration['url'] ?? ''));
		if ($title === '' || $template === '') {
			return null;
		}

		if ($this->conditionHolds(condition: ($declaration['condition'] ?? null), object: $object) === false) {
			return null;
		}

		$url = $this->fill(template: $template, object: $object);
		if ($url === null) {
			return null;
		}

		$link = [
			'title' => $title,
			'url' => $url,
		];

		$description = trim((string)($declaration['description'] ?? ''));
		if ($description !== '') {
			$link['description'] = $description;
		}

		$target = trim((string)($declaration['target'] ?? ''));
		if ($target !== '') {
			$link['target'] = $target;
		}

		return $link;

	}//end resolveOne()

	/**
	 * Fill a template's placeholders, or refuse when one cannot be filled.
	 *
	 * @param string $template The URL template.
	 * @param array<string, mixed> $object The object's own values.
	 *
	 * @return string|null The filled URL, or null when a placeholder has no value.
	 */
	private function fill(string $template, array $object): ?string {
		$filled = $template;
		foreach (self::placeholdersIn(template: $template) as $path) {
			$value = $this->valueAt(path: $path, object: $object);
			if ($value === null) {
				return null;
			}

			$filled = str_replace('{' . $path . '}', rawurlencode($value), $filled);
		}

		return $filled;

	}//end fill()

	/**
	 * One scalar value out of the object, by dotted path.
	 *
	 * Only a scalar fills a placeholder. An array or an object has no single
	 * spelling in a URL, and picking one (the first element, a JSON blob, a
	 * comma join) would be this class inventing a convention nobody declared.
	 *
	 * @param string $path The dotted path.
	 * @param array<string, mixed> $object The object's own values.
	 *
	 * @return string|null The value as a string, or null when there is none.
	 */
	private function valueAt(string $path, array $object): ?string {
		$segments = explode('.', $path);
		if (count($segments) > self::MAX_PLACEHOLDER_DEPTH) {
			return null;
		}

		$cursor = $object;
		foreach ($segments as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return null;
			}

			$cursor = $cursor[$segment];
		}

		return self::scalarOrNull(value: $cursor);

	}//end valueAt()

	/**
	 * A scalar as a string, or null for anything that is not one.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The string, or null.
	 */
	private static function scalarOrNull(mixed $value): ?string {
		if (is_string($value) === true) {
			$trimmed = trim($value);
			if ($trimmed === '') {
				return null;
			}

			return $trimmed;
		}

		if (is_int($value) === true || is_float($value) === true) {
			return (string)$value;
		}

		if ($value === true) {
			return 'true';
		}

		if ($value === false) {
			return 'false';
		}

		return null;

	}//end scalarOrNull()

	/**
	 * Whether a declaration's condition holds for this object.
	 *
	 * The grammar is deliberately small: a map of property path to expected
	 * value, every entry of which must hold. Anything richer belongs in the
	 * rules engine, which already exists, rather than in a second condition
	 * dialect nobody can find the documentation for.
	 *
	 * @param mixed $condition The declared condition, or null.
	 * @param array<string, mixed> $object The object's own values.
	 *
	 * @return bool True when the link may be offered.
	 */
	private function conditionHolds(mixed $condition, array $object): bool {
		if ($condition === null || $condition === []) {
			return true;
		}

		if (is_array($condition) === false) {
			return false;
		}

		foreach ($condition as $path => $expected) {
			$actual = $this->valueAt(path: (string)$path, object: $object);
			if ($actual === null) {
				return false;
			}

			if ($this->matches(actual: $actual, expected: $expected) === false) {
				return false;
			}
		}

		return true;

	}//end conditionHolds()

	/**
	 * Whether one resolved value satisfies one expectation.
	 *
	 * @param string $actual The object's value, as a string.
	 * @param mixed $expected The declared expectation: a scalar, or a list of them.
	 *
	 * @return bool True when it holds.
	 */
	private function matches(string $actual, mixed $expected): bool {
		if (is_array($expected) === true) {
			foreach ($expected as $candidate) {
				$wanted = self::scalarOrNull(value: $candidate);
				if ($wanted !== null && $wanted === $actual) {
					return true;
				}
			}

			return false;
		}

		$wanted = self::scalarOrNull(value: $expected);

		return ($wanted !== null && $wanted === $actual);

	}//end matches()
}//end class
