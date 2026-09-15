<?php

/**
 * OpenRegister StateFieldRules
 *
 * The effective field rules of one object in one lifecycle state: what is
 * hidden, what is frozen and what must be filled, for the user who asked.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/field-rules-by-state/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

use JsonSerializable;

/**
 * What a state says about the fields of one object, already decided.
 *
 * This is the answer, not the declaration: every group test and every
 * condition has been evaluated against this object and this user, so a
 * consumer reads three flat lists and renders them. It is the shape
 * `@self.fieldRules` publishes, which is why the requirement's "the rules
 * that apply to this object as it stands, not the rules that could apply"
 * is a property of this class rather than a note in a caller.
 *
 * @spec openspec/changes/field-rules-by-state/specs/row-field-level-security/spec.md
 */
final class StateFieldRules implements JsonSerializable {

	/**
	 * Constructor.
	 *
	 * @param string|null $state The state the rules were resolved for, null when the schema declares no lifecycle.
	 * @param array<int, string> $hidden Properties kept out of the rendered object.
	 * @param array<int, string> $readOnly Properties rendered but refused on change.
	 * @param array<int, string> $required Properties that may not be left empty.
	 * @param array<string, string> $messages Per-property refusal text the author declared.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?string $state = null,
		private readonly array $hidden = [],
		private readonly array $readOnly = [],
		private readonly array $required = [],
		private readonly array $messages = [],
	) {
	}//end __construct()

	/**
	 * The empty answer: a schema with no lifecycle, or a state that declares nothing.
	 *
	 * @param string|null $state The state, when one is known.
	 *
	 * @return self The empty rule set.
	 */
	public static function none(?string $state = null): self {
		return new self(state: $state);
	}//end none()

	/**
	 * The state these rules belong to.
	 *
	 * @return string|null The state, or null when the schema declares no lifecycle.
	 */
	public function getState(): ?string {
		return $this->state;
	}//end getState()

	/**
	 * Properties kept out of the rendered object.
	 *
	 * @return array<int, string> The property names.
	 */
	public function getHidden(): array {
		return $this->hidden;
	}//end getHidden()

	/**
	 * Properties rendered but refused on change.
	 *
	 * @return array<int, string> The property names.
	 */
	public function getReadOnly(): array {
		return $this->readOnly;
	}//end getReadOnly()

	/**
	 * Properties that may not be left empty.
	 *
	 * @return array<int, string> The property names.
	 */
	public function getRequired(): array {
		return $this->required;
	}//end getRequired()

	/**
	 * Whether any rule at all applies.
	 *
	 * Callers use this to skip work rather than to decide anything: a schema
	 * without state rules must cost nothing on the render and save paths.
	 *
	 * @return bool True when all three lists are empty.
	 */
	public function isEmpty(): bool {
		return ($this->hidden === [] && $this->readOnly === [] && $this->required === []);
	}//end isEmpty()

	/**
	 * Whether a property is hidden in this state for this user.
	 *
	 * @param string $property The property name.
	 *
	 * @return bool True when the property is hidden.
	 */
	public function hides(string $property): bool {
		return in_array($property, $this->hidden, true);
	}//end hides()

	/**
	 * Whether a property is read only in this state for this user.
	 *
	 * @param string $property The property name.
	 *
	 * @return bool True when the property is read only.
	 */
	public function freezes(string $property): bool {
		return in_array($property, $this->readOnly, true);
	}//end freezes()

	/**
	 * The refusal sentence the author declared for a property, when there is one.
	 *
	 * @param string $property The property name.
	 *
	 * @return string|null The declared message, or null.
	 */
	public function messageFor(string $property): ?string {
		return ($this->messages[$property] ?? null);
	}//end messageFor()

	/**
	 * The three lists, in the shape `@self.fieldRules` publishes.
	 *
	 * `state` rides along because a form that renders a refusal needs to name
	 * the state the rule came from, and asking for the lifecycle field a
	 * second time is how two surfaces end up disagreeing about which state
	 * the object is in.
	 *
	 * @return array{state: string|null, hidden: array<int, string>, readOnly: array<int, string>, required: array<int, string>} The published shape.
	 */
	public function jsonSerialize(): array {
		return [
			'state' => $this->state,
			'hidden' => array_values($this->hidden),
			'readOnly' => array_values($this->readOnly),
			'required' => array_values($this->required),
		];
	}//end jsonSerialize()
}//end class
