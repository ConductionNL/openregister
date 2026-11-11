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
class ElementMappingValidator implements ElementMappingValidatorInterface {

	/**
	 * The key naming the object property that fills an element.
	 */
	public const SOURCE_PROPERTY = 'property';

	/**
	 * The key naming a fixed value that fills an element.
	 */
	public const SOURCE_CONST = 'const';

	/**
	 * The message for a mandatory element the mapping leaves out; takes the element.
	 */
	private const MISSING_MANDATORY = 'MDTO demands "%s" (minOccurs="1" in MDTO-XML1.0.1.xsd) and this mapping does not fill it. '
		. 'A transfer of objects of this schema would be refused.';

	/**
	 * The message for an element MDTO does not have; takes the element and the catalogue.
	 */
	private const UNKNOWN_ELEMENT = '"%s" is not an MDTO informatieobject element. MDTO-XML1.0.1.xsd declares: %s.';

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
	 * Each rule's message names which element and which key was wrong; an
	 * administrator reading "invalid mapping" learns nothing.
	 *
	 * @param array<string, mixed> $mapping    The annotation, as written.
	 * @param array<string, mixed> $properties The schema's declared properties.
	 *
	 * @return array<int, array{code: string, message: string}> The errors, empty when the mapping is sound.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function validate(array $mapping, array $properties): array {
		if ($mapping === []) {
			$message = 'The MDTO mapping is declared but empty. Remove the key, or map the elements MDTO demands.';
			return [$this->error(code: 'mdto-mapping-empty', message: $message)];
		}

		try {
			$known = $this->catalogue->elements();
			$mandatory = $this->catalogue->mandatory();
		} catch (Throwable $e) {
			return [$this->error(code: 'mdto-mapping-catalogue-unreadable', message: $e->getMessage())];
		}

		$run = new MdtoMappingRun(known: $known, properties: $properties);
		$errors = $this->entryErrors(mapping: $mapping, run: $run);

		return array_merge($errors, $this->missingMandatory(mapping: $mapping, mandatory: $mandatory));
	}//end validate()

	/**
	 * Check every entry of the mapping, in the order it was written.
	 *
	 * @param array<string, mixed> $mapping The annotation, as written.
	 * @param MdtoMappingRun       $run     The catalogue and the schema's properties.
	 *
	 * @return array<int, array{code: string, message: string}> The errors of all entries.
	 */
	private function entryErrors(array $mapping, MdtoMappingRun $run): array {
		$errors = [];
		foreach ($mapping as $element => $entry) {
			$errors = array_merge(
				$errors,
				$this->validateEntry(entry: new MdtoMappingEntry(element: (string)$element, value: $entry), run: $run)
			);
		}

		return $errors;
	}//end entryErrors()

	/**
	 * Name every element MDTO demands that the mapping does not fill.
	 *
	 * @param array<string, mixed> $mapping   The annotation, as written.
	 * @param array<int, string>   $mandatory The elements MDTO demands.
	 *
	 * @return array<int, array{code: string, message: string}> One error per unfilled element.
	 */
	private function missingMandatory(array $mapping, array $mandatory): array {
		$errors = [];
		foreach ($mandatory as $element) {
			if (array_key_exists($element, $mapping) === false) {
				$message = sprintf(self::MISSING_MANDATORY, $element);
				$errors[] = $this->error(code: 'mdto-mapping-missing-mandatory', message: $message);
			}
		}

		return $errors;
	}//end missingMandatory()

	/**
	 * Check one element's entry.
	 *
	 * @param MdtoMappingEntry $entry The element and whatever was written against it.
	 * @param MdtoMappingRun   $run   The catalogue and the schema's properties.
	 *
	 * @return array<int, array{code: string, message: string}> The errors for this entry.
	 */
	private function validateEntry(MdtoMappingEntry $entry, MdtoMappingRun $run): array {
		if (array_key_exists($entry->element, $run->known) === false) {
			$message = sprintf(self::UNKNOWN_ELEMENT, $entry->element, implode(', ', array_keys($run->known)));
			return [$this->error(code: 'mdto-mapping-unknown-element', message: $message)];
		}

		if (is_array($entry->value) === false) {
			$format = 'The mapping for "%s" must be an object naming a "%s" or a "%s".';
			return [$this->error(code: 'mdto-mapping-entry-not-an-object', message: $this->sourceMessage(format: $format, element: $entry->element))];
		}

		return $this->sourceErrors(entry: $entry, properties: $run->properties);
	}//end validateEntry()

	/**
	 * Check that an entry names exactly one source, and that a property source exists.
	 *
	 * @param MdtoMappingEntry     $entry      The element and the entry written against it, an array.
	 * @param array<string, mixed> $properties The schema's declared properties.
	 *
	 * @return array<int, array{code: string, message: string}> The errors for this entry.
	 */
	private function sourceErrors(MdtoMappingEntry $entry, array $properties): array {
		$named = count(array_filter([$this->propertySource(entry: $entry), $this->constSource(entry: $entry)], 'is_string'));

		if ($named === 2) {
			$format = 'The mapping for "%s" names both a "%s" and a "%s". One of them fills the element; say which.';
			return [$this->error(code: 'mdto-mapping-two-sources', message: $this->sourceMessage(format: $format, element: $entry->element))];
		}

		if ($named === 0) {
			$format = 'The mapping for "%s" names neither a "%s" nor a "%s", so nothing fills the element.';
			return [$this->error(code: 'mdto-mapping-no-source', message: $this->sourceMessage(format: $format, element: $entry->element))];
		}

		return $this->propertyErrors(entry: $entry, properties: $properties);
	}//end sourceErrors()

	/**
	 * The property an entry reads, or null when it names none.
	 *
	 * @param MdtoMappingEntry $entry The entry; its value is an array.
	 *
	 * @return string|null The property source.
	 */
	private function propertySource(MdtoMappingEntry $entry): ?string {
		return ArchivalText::fromStringOrInt(value: ($entry->value[self::SOURCE_PROPERTY] ?? null));
	}//end propertySource()

	/**
	 * The constant an entry fills the element with, or null when it names none.
	 *
	 * @param MdtoMappingEntry $entry The entry; its value is an array.
	 *
	 * @return string|null The constant source.
	 */
	private function constSource(MdtoMappingEntry $entry): ?string {
		return ArchivalText::fromStringOrInt(value: ($entry->value[self::SOURCE_CONST] ?? null));
	}//end constSource()

	/**
	 * Check that a property source names a property the schema declares.
	 *
	 * Only the ROOT segment is checked. A dotted path reaches into an
	 * object-typed property whose inner shape this schema does not necessarily
	 * declare, and refusing a path we cannot verify would make every nested
	 * source unmappable.
	 *
	 * @param MdtoMappingEntry     $entry      The entry, naming exactly one source.
	 * @param array<string, mixed> $properties The schema's declared properties.
	 *
	 * @return array<int, array{code: string, message: string}> The error, or none.
	 */
	private function propertyErrors(MdtoMappingEntry $entry, array $properties): array {
		$property = $this->propertySource(entry: $entry);
		if ($property === null) {
			return [];
		}

		$root = explode('.', $property)[0];
		if (array_key_exists($root, $properties) === true) {
			return [];
		}

		$format = 'The mapping for "%s" reads property "%s", which this schema does not declare.';
		$message = sprintf($format, $entry->element, $root);
		return [$this->error(code: 'mdto-mapping-unknown-property', message: $message)];
	}//end propertyErrors()

	/**
	 * A message about an entry's source, naming the element and both source keys.
	 *
	 * @param string $format  The message, with the element, the property key and the const key in that order.
	 * @param string $element The MDTO element name.
	 *
	 * @return string The message.
	 */
	private function sourceMessage(string $format, string $element): string {
		return sprintf($format, $element, self::SOURCE_PROPERTY, self::SOURCE_CONST);
	}//end sourceMessage()

	/**
	 * One validation error.
	 *
	 * @param string $code    The error code.
	 * @param string $message The message.
	 *
	 * @return array{code: string, message: string} The error.
	 */
	private function error(string $code, string $message): array {
		return ['code' => $code, 'message' => $message];
	}//end error()
}//end class
