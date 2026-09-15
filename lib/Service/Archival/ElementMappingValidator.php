<?php

/**
 * Checks an administered MDTO element mapping before anything relies on it.
 *
 * 🔴 AN UNMAPPED MANDATORY ELEMENT DISCOVERED BY THE E-DEPOT IS A FAILED
 * TRANSFER AND A SUPPORT CALL. The mapping says which object property fills
 * which MDTO element, and until this existed it was not administered at all:
 * {@see \OCA\OpenRegister\Service\Edepot\MdtoSourceReader} read from a fixed
 * set of places and an administrator could not say that their `zaaknummer` is
 * the `identificatie`. A shipped default is a guess about somebody else's data
 * model.
 *
 * So the mapping is configuration, and configuration is checked where it is
 * written rather than where it is read. Errors are COLLECTED and returned with
 * a code each, never thrown: the caller decides which of them refuse a save.
 * That is the shape {@see \OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator}
 * already uses, and one shape for one job is worth more than a private
 * preference.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use Throwable;

/**
 * Validates the `x-openregister-mdto-mapping` annotation.
 *
 * @psalm-suppress UnusedClass
 */
class ElementMappingValidator {

	/**
	 * The schema configuration key the mapping lives under.
	 */
	public const ANNOTATION_KEY = 'x-openregister-mdto-mapping';

	/**
	 * The key naming the object property that fills an element.
	 */
	public const SOURCE_PROPERTY = 'property';

	/**
	 * The key naming a fixed value that fills an element.
	 */
	public const SOURCE_CONST = 'const';

	/**
	 * Constructor.
	 *
	 * @param MdtoElementCatalogue $catalogue The authority on which elements exist and which are demanded.
	 */
	public function __construct(
		private readonly MdtoElementCatalogue $catalogue,
	) {
	}//end __construct()

	/**
	 * Check one schema's mapping against the MDTO catalogue and its own properties.
	 *
	 * @param array<string, mixed> $mapping    The annotation, as written.
	 * @param array<string, mixed> $properties The schema's declared properties.
	 *
	 * @return array<int, array{code: string, message: string}> The errors, empty when the mapping is sound.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch per rule, and each rule's
	 *              message has to name which element and which key was wrong; an
	 *              administrator reading "invalid mapping" learns nothing.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function validate(array $mapping, array $properties): array {
		if ($mapping === []) {
			return [
				[
					'code' => 'mdto-mapping-empty',
					'message' => 'The MDTO mapping is declared but empty. Remove the key, or map the elements MDTO demands.',
				],
			];
		}

		try {
			$known = $this->catalogue->elements();
			$mandatory = $this->catalogue->mandatory();
		} catch (Throwable $e) {
			return [
				[
					'code' => 'mdto-mapping-catalogue-unreadable',
					'message' => $e->getMessage(),
				],
			];
		}

		$errors = [];
		foreach ($mapping as $element => $entry) {
			$errors = array_merge(
				$errors,
				$this->validateEntry(
					element: (string)$element,
					entry: $entry,
					known: $known,
					properties: $properties
				)
			);
		}

		foreach ($mandatory as $element) {
			if (array_key_exists($element, $mapping) === true) {
				continue;
			}

			$errors[] = [
				'code' => 'mdto-mapping-missing-mandatory',
				'message' => sprintf(
					'MDTO demands "%s" (minOccurs="1" in MDTO-XML1.0.1.xsd) and this mapping does not fill it. '
					. 'A transfer of objects of this schema would be refused.',
					$element
				),
			];
		}

		return $errors;
	}//end validate()

	/**
	 * Check one element's entry.
	 *
	 * @param string               $element    The MDTO element name.
	 * @param mixed                $entry      Whatever was written against it.
	 * @param array<string, bool>  $known      The catalogue.
	 * @param array<string, mixed> $properties The schema's declared properties.
	 *
	 * @return array<int, array{code: string, message: string}> The errors for this entry.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) See validate().
	 */
	private function validateEntry(string $element, mixed $entry, array $known, array $properties): array {
		if (array_key_exists($element, $known) === false) {
			return [
				[
					'code' => 'mdto-mapping-unknown-element',
					'message' => sprintf(
						'"%s" is not an MDTO informatieobject element. MDTO-XML1.0.1.xsd declares: %s.',
						$element,
						implode(', ', array_keys($known))
					),
				],
			];
		}

		if (is_array($entry) === false) {
			return [
				[
					'code' => 'mdto-mapping-entry-not-an-object',
					'message' => sprintf(
						'The mapping for "%s" must be an object naming a "%s" or a "%s".',
						$element,
						self::SOURCE_PROPERTY,
						self::SOURCE_CONST
					),
				],
			];
		}

		$property = $this->text(value: ($entry[self::SOURCE_PROPERTY] ?? null));
		$constant = $this->text(value: ($entry[self::SOURCE_CONST] ?? null));

		if ($property !== null && $constant !== null) {
			return [
				[
					'code' => 'mdto-mapping-two-sources',
					'message' => sprintf(
						'The mapping for "%s" names both a "%s" and a "%s". One of them fills the element; say which.',
						$element,
						self::SOURCE_PROPERTY,
						self::SOURCE_CONST
					),
				],
			];
		}

		if ($property === null && $constant === null) {
			return [
				[
					'code' => 'mdto-mapping-no-source',
					'message' => sprintf(
						'The mapping for "%s" names neither a "%s" nor a "%s", so nothing fills the element.',
						$element,
						self::SOURCE_PROPERTY,
						self::SOURCE_CONST
					),
				],
			];
		}

		if ($property === null) {
			return [];
		}

		// Only the ROOT segment is checked. A dotted path reaches into an
		// object-typed property whose inner shape this schema does not
		// necessarily declare, and refusing a path we cannot verify would make
		// every nested source unmappable.
		$root = explode('.', $property)[0];
		if (array_key_exists($root, $properties) === false) {
			return [
				[
					'code' => 'mdto-mapping-unknown-property',
					'message' => sprintf(
						'The mapping for "%s" reads property "%s", which this schema does not declare.',
						$element,
						$root
					),
				],
			];
		}

		return [];
	}//end validateEntry()

	/**
	 * A non-empty trimmed string, or null.
	 *
	 * @param mixed $value The candidate.
	 *
	 * @return string|null The string, or null when it says nothing.
	 */
	private function text(mixed $value): ?string {
		if (is_string($value) === false && is_int($value) === false) {
			return null;
		}

		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		return $text;
	}//end text()
}//end class
