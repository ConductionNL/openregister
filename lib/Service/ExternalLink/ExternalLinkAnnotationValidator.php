<?php

/**
 * Refuses an external-link declaration at schema save.
 *
 * WHY VALIDATE AT SAVE AND NOT AT RENDER. A declaration that cannot produce a
 * link fails silently at render by design: an unfillable placeholder hides the
 * link, which is the right answer for a record that genuinely has no `bagId`
 * and the wrong one for a template that misspells the property. The two are
 * indistinguishable at render time, and the second kind can sit there for a
 * year with its author believing the feature shipped.
 *
 * So the shape is refused here, where the author is looking at the screen, and
 * a placeholder naming a property the schema does not define is refused too:
 * that is the typo case, and it is the only one that is unambiguously wrong
 * before any object exists.
 *
 * 🔴 NOT REFUSED: a scheme this instance has never seen, a host that does not
 * resolve, a target that is currently down. A link out of a gemeente's record
 * points at a system this instance does not control and often cannot reach, and
 * refusing a save because a GIS was down at that moment would be an outage in
 * the schema editor caused by somebody else's outage. What IS refused is a
 * scheme that runs code in the reader's browser, because `javascript:` in a
 * rendered link is a stored cross-site scripting vector wearing a title.
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
 * Pure validation of the `x-openregister-external-links` annotation.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExternalLink
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 * Reason: `ExternalLinkResolver::placeholdersIn()` is a pure parser over a
 *         string, kept on the resolver so the validator and the renderer can
 *         never disagree about what a placeholder is. Injecting the resolver to
 *         reach one stateless parse would add a dependency with nothing behind
 *         it, and duplicating the regex is precisely how the two would drift.
 */
final class ExternalLinkAnnotationValidator {

	/**
	 * Schemes a rendered link may use.
	 *
	 * An allowlist rather than a `javascript:` denylist, because a denylist of
	 * dangerous schemes is a list somebody has to keep current against every
	 * browser, and `data:` and `vbscript:` were each somebody's surprise.
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

	/**
	 * The most links one schema may declare.
	 *
	 * A ceiling rather than a rule about taste: every declared link is resolved
	 * on every render of every object of that schema, so an unbounded list is a
	 * per-row cost an author sets by accident.
	 *
	 * @var integer
	 */
	public const MAX_LINKS = 25;

	/**
	 * Validate the annotation on a schema definition.
	 *
	 * @param array<string, mixed> $shape The schema shape: `properties` and the annotation.
	 *
	 * @return array<int, string> The errors; an empty list means valid.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#requirement-a-schema-declares-links-out-of-its-objects-req-avs-001
	 */
	public function validate(array $shape): array {
		$declarations = ($shape[ExternalLinkResolver::ANNOTATION] ?? null);
		if ($declarations === null) {
			return [];
		}

		if (is_array($declarations) === false || array_is_list($declarations) === false) {
			return [ExternalLinkResolver::ANNOTATION . ' must be a list of link declarations.'];
		}

		if (count($declarations) > self::MAX_LINKS) {
			return [
				ExternalLinkResolver::ANNOTATION . ' declares ' . count($declarations)
					. ' links; at most ' . self::MAX_LINKS . ' are allowed, because every one of them is resolved on every render.',
			];
		}

		$known = $this->knownPaths(properties: ($shape['properties'] ?? []));

		$errors = [];
		foreach ($declarations as $index => $declaration) {
			foreach ($this->validateOne(declaration: $declaration, known: $known) as $error) {
				$errors[] = 'link ' . $index . ': ' . $error;
			}
		}

		return $errors;

	}//end validate()

	/**
	 * Validate one declaration.
	 *
	 * @param mixed $declaration The declaration.
	 * @param array<int, string> $known The property paths the schema defines.
	 *
	 * @return array<int, string> The errors for this declaration.
	 */
	private function validateOne(mixed $declaration, array $known): array {
		if (is_array($declaration) === false) {
			return ['must be an object with a title and a url.'];
		}

		$errors = [];

		$title = trim((string)($declaration['title'] ?? ''));
		if ($title === '') {
			$errors[] = 'needs a title; a link with no label is a link nobody clicks.';
		}

		$template = trim((string)($declaration['url'] ?? ''));
		if ($template === '') {
			$errors[] = 'needs a url template.';
			return $errors;
		}

		$schemeError = $this->validateScheme(template: $template);
		if ($schemeError !== null) {
			$errors[] = $schemeError;
		}

		foreach ($this->validatePlaceholders(template: $template, known: $known) as $error) {
			$errors[] = $error;
		}

		foreach ($this->validateCondition(condition: ($declaration['condition'] ?? null), known: $known) as $error) {
			$errors[] = $error;
		}

		return $errors;

	}//end validateOne()

	/**
	 * Refuse a scheme that is not on the allowlist.
	 *
	 * The scheme is read off the literal prefix, before any placeholder, so a
	 * template whose scheme is itself a placeholder is refused: a URL whose
	 * scheme comes from stored data is a scheme an object's author chooses.
	 *
	 * @param string $template The URL template.
	 *
	 * @return string|null The error, or null.
	 */
	private function validateScheme(string $template): ?string {
		$matched = [];
		if (preg_match('#^([A-Za-z][A-Za-z0-9+.-]*):#', $template, $matched) !== 1) {
			return 'url must start with a scheme; one of ' . implode(', ', self::ALLOWED_SCHEMES) . '.';
		}

		$scheme = strtolower($matched[1]);
		if (in_array($scheme, self::ALLOWED_SCHEMES, true) === false) {
			return 'url scheme "' . $scheme . '" is not allowed; use one of ' . implode(', ', self::ALLOWED_SCHEMES) . '.';
		}

		return null;

	}//end validateScheme()

	/**
	 * Refuse a placeholder naming a property the schema does not define.
	 *
	 * @param string $template The URL template.
	 * @param array<int, string> $known The property paths the schema defines.
	 *
	 * @return array<int, string> The errors.
	 */
	private function validatePlaceholders(string $template, array $known): array {
		$placeholders = ExternalLinkResolver::placeholdersIn(template: $template);
		if ($placeholders === []) {
			return [];
		}

		// A schema with no declared properties cannot contradict anything, and
		// refusing every placeholder against it would break a register that
		// stores loosely-typed objects on purpose.
		if ($known === []) {
			return [];
		}

		$errors = [];
		foreach ($placeholders as $path) {
			if ($this->isKnown(path: $path, known: $known) === false) {
				$errors[] = 'url names {' . $path . '}, which this schema does not define. '
					. 'A misspelt placeholder hides the link on every object and looks exactly like an object with no value.';
			}
		}

		return $errors;

	}//end validatePlaceholders()

	/**
	 * Refuse a condition that is not a map, or names an unknown property.
	 *
	 * @param mixed $condition The declared condition.
	 * @param array<int, string> $known The property paths the schema defines.
	 *
	 * @return array<int, string> The errors.
	 */
	private function validateCondition(mixed $condition, array $known): array {
		if ($condition === null) {
			return [];
		}

		if (is_array($condition) === false || array_is_list($condition) === true) {
			return ['condition must be a map of property path to expected value.'];
		}

		if ($known === []) {
			return [];
		}

		$errors = [];
		foreach (array_keys($condition) as $path) {
			if ($this->isKnown(path: (string)$path, known: $known) === false) {
				$errors[] = 'condition names ' . $path . ', which this schema does not define.';
			}
		}

		return $errors;

	}//end validateCondition()

	/**
	 * Whether a placeholder path is one the schema defines.
	 *
	 * A path whose first segment is a declared property is accepted even when
	 * the rest of it is not declared: a nested object property often has no
	 * sub-schema, and refusing `{adres.bagId}` because `adres` is an untyped
	 * object would refuse the exact case this feature was asked for.
	 *
	 * @param string $path The dotted path.
	 * @param array<int, string> $known The property paths the schema defines.
	 *
	 * @return bool True when the path is addressable.
	 */
	private function isKnown(string $path, array $known): bool {
		if (in_array($path, $known, true) === true) {
			return true;
		}

		$root = explode('.', $path)[0];

		return in_array($root, $known, true);

	}//end isKnown()

	/**
	 * The property names a schema defines.
	 *
	 * @param mixed $properties The schema's `properties` block.
	 *
	 * @return array<int, string> The property names.
	 */
	private function knownPaths(mixed $properties): array {
		if (is_array($properties) === false) {
			return [];
		}

		$names = [];
		foreach (array_keys($properties) as $name) {
			$names[] = (string)$name;
		}

		return $names;

	}//end knownPaths()
}//end class
