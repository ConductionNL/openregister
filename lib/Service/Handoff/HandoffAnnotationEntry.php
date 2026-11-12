<?php

/**
 * OpenRegister Handoff Annotation Entry
 *
 * One entry of a source schema's `x-openregister-handoff` annotation, with
 * the schema facts its rules are checked against (declared lifecycle states,
 * own properties). Split out of {@see HandoffAnnotationValidator} so each rule
 * takes one value instead of an entry, an id, the schema facts and a
 * by-reference error list.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Handoff;

/**
 * A single `x-openregister-handoff` entry in the context of its schema.
 */
final class HandoffAnnotationEntry {

	/**
	 * The shape a usable entry `id` must have.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

	/**
	 * Constructor.
	 *
	 * @param mixed                   $entry           The raw entry, as found in the annotation.
	 * @param int                     $index           The entry's position in the annotation.
	 * @param array<int, string>|null $lifecycleStates Declared lifecycle states (null = no lifecycle declared).
	 * @param array<string, mixed>    $properties      The source schema's properties map.
	 */
	public function __construct(
		private readonly mixed $entry,
		public readonly int $index,
		public readonly ?array $lifecycleStates,
		public readonly array $properties,
	) {

	}//end __construct()

	/**
	 * Whether the entry is an object at all.
	 *
	 * @return bool True when the entry is a map the other rules can read.
	 */
	public function isObject(): bool {
		return is_array($this->entry) === true;
	}//end isObject()

	/**
	 * One field of the entry, or a default when it is absent or null.
	 *
	 * @param string $key     The field name.
	 * @param mixed  $default The value to use when the field is absent or null.
	 *
	 * @return mixed The field's value.
	 */
	public function field(string $key, mixed $default = null): mixed {
		if (is_array($this->entry) === false) {
			return $default;
		}

		return ($this->entry[$key] ?? $default);
	}//end field()

	/**
	 * The entry's `id` when it is slug-like, else null.
	 *
	 * @return string|null The usable id.
	 */
	public function validId(): ?string {
		$id = $this->field(key: 'id');
		if (is_string($id) === false || preg_match(self::ID_PATTERN, $id) !== 1) {
			return null;
		}

		return $id;
	}//end validId()

	/**
	 * How the entry is named in messages: its id, or a positional placeholder.
	 *
	 * @return string The entry's label.
	 */
	public function label(): string {
		return ($this->validId() ?? sprintf('entry-%d', $this->index));
	}//end label()

	/**
	 * Prefix a message with the entry it is about.
	 *
	 * @param string $message The message body.
	 *
	 * @return string `Handoff "<label>": <message>`.
	 */
	public function about(string $message): string {
		return sprintf('Handoff "%s": ', $this->label()).$message;
	}//end about()

	/**
	 * Build one error about this entry.
	 *
	 * @param string $code    The error code.
	 * @param string $message The message body, without the entry prefix.
	 *
	 * @return array{code: string, message: string} The error.
	 */
	public function fault(string $code, string $message): array {
		return ['code' => $code, 'message' => $this->about(message: $message)];
	}//end fault()

	/**
	 * The target kind URI as a string (empty when absent).
	 *
	 * @return string The kind URI.
	 */
	public function kindUri(): string {
		return (string) $this->field(key: 'targetSemanticType', default: '');
	}//end kindUri()

	/**
	 * The contract fields the mapping declares, as strings.
	 *
	 * @return array<int, string> The mapped fields, empty when the mapping is not a map.
	 */
	public function mappingFields(): array {
		$mapping = $this->field(key: 'mapping');
		if (is_array($mapping) === false) {
			return [];
		}

		return array_map('strval', array_keys($mapping));
	}//end mappingFields()

	/**
	 * The mapping expression declared for one contract field.
	 *
	 * @param string $field The contract field.
	 *
	 * @return mixed The expression, or null when unmapped.
	 */
	public function expressionFor(string $field): mixed {
		$mapping = $this->field(key: 'mapping');
		if (is_array($mapping) === false) {
			return null;
		}

		return ($mapping[$field] ?? null);
	}//end expressionFor()
}//end class
