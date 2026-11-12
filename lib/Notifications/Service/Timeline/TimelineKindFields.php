<?php

/**
 * Judges the fields an entry carries against the kind it names.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Notifications\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Timeline;

use OCA\OpenRegister\Notifications\Db\TimelineKind;

/**
 * One entry's fields, judged against one kind's declared properties.
 */
class TimelineKindFields {

	/**
	 * Constructor.
	 *
	 * @param TimelineKind        $kind   The declaration.
	 * @param array<string,mixed> $fields The values the entry carries.
	 */
	public function __construct(
		private readonly TimelineKind $kind,
		private readonly array $fields,
	) {
	}//end __construct()

	/**
	 * The values the kind declares, once all of them fit.
	 *
	 * Every property is judged before anything is thrown, so a caller fixing a
	 * form is told about all four wrong fields at once rather than one per
	 * round trip.
	 *
	 * @return array<string,mixed> The accepted values, with anything the kind does not declare dropped.
	 *
	 * @throws TimelineValidationException When a value does not fit.
	 */
	public function validated(): array {
		$errors = $this->errors();
		if ($errors !== []) {
			throw new TimelineValidationException($errors);
		}

		$accepted = [];
		foreach (array_keys($this->declarations()) as $name) {
			if (array_key_exists($name, $this->fields) === true) {
				$accepted[$name] = $this->fields[$name];
			}
		}

		return $accepted;
	}//end validated()

	/**
	 * The reason each declared property does not fit.
	 *
	 * @return array<string,string> The reasons by property name.
	 */
	private function errors(): array {
		$errors = [];
		foreach ($this->declarations() as $name => $declaration) {
			$reason = $this->checkProperty(name: $name, declaration: $declaration);
			if ($reason !== null) {
				$errors[$name] = $reason;
			}
		}

		return $errors;
	}//end errors()

	/**
	 * The kind's property declarations by name.
	 *
	 * @return array<string,mixed> The declarations.
	 */
	private function declarations(): array {
		return array_filter(($this->kind->getProperties() ?? []), 'is_string', ARRAY_FILTER_USE_KEY);
	}//end declarations()

	/**
	 * Judge one declared property against what the entry carries.
	 *
	 * Returns the reason it does not fit, or null when it does, which is also
	 * the answer for a property the entry simply does not carry and the kind
	 * does not require.
	 *
	 * @param string $name        The property name.
	 * @param mixed  $declaration Its declaration.
	 *
	 * @return string|null The reason, or null.
	 */
	private function checkProperty(string $name, mixed $declaration): ?string {
		if (array_key_exists($name, $this->fields) === false) {
			return $this->absenceReason(name: $name);
		}

		$rules = [];
		if (is_array($declaration) === true) {
			$rules = $declaration;
		}

		return $this->checkValue(value: $this->fields[$name], declaration: $rules);
	}//end checkProperty()

	/**
	 * Why a property may not be absent, if the kind requires it.
	 *
	 * @param string $name The property name.
	 *
	 * @return string|null The reason, or null.
	 */
	private function absenceReason(string $name): ?string {
		if (in_array($name, ($this->kind->getRequired() ?? []), true) === true) {
			return 'This field is required by kind '.(string)$this->kind->getSlug();
		}

		return null;
	}//end absenceReason()

	/**
	 * Check one value against one property declaration.
	 *
	 * @param mixed               $value       The value the entry carries.
	 * @param array<string,mixed> $declaration The property declaration.
	 *
	 * @return string|null The reason it does not fit, or null when it does.
	 */
	private function checkValue(mixed $value, array $declaration): ?string {
		$type = $this->declaredType(declaration: $declaration);
		if ($this->fitsType(value: $value, type: $type) === false) {
			return 'Expected a value of type '.$type;
		}

		$enum = ($declaration['enum'] ?? null);
		if (is_array($enum) === false || in_array($value, $enum, true) === true) {
			return null;
		}

		return 'Expected one of: '.implode(', ', array_map('strval', $enum));
	}//end checkValue()

	/**
	 * The type a property declares, string when it names none.
	 *
	 * @param array<string,mixed> $declaration The property declaration.
	 *
	 * @return string The type.
	 */
	private function declaredType(array $declaration): string {
		$type = ($declaration['type'] ?? null);
		if (is_string($type) === true) {
			return $type;
		}

		return 'string';
	}//end declaredType()

	/**
	 * Whether a value has a type. A type this subset does not know accepts anything.
	 *
	 * @param mixed  $value The value.
	 * @param string $type  The declared type.
	 *
	 * @return boolean True when it fits.
	 */
	private function fitsType(mixed $value, string $type): bool {
		return match ($type) {
			'string' => is_string($value),
			'integer' => is_int($value),
			'number' => (is_int($value) === true || is_float($value) === true),
			'boolean' => is_bool($value),
			'array' => is_array($value),
			'object' => is_array($value),
			default => true,
		};
	}//end fitsType()
}//end class
