<?php

/**
 * OpenRegister LifecycleTerminals
 *
 * Reads a schema's lifecycle field and terminal (final) states, so the
 * temporal sweep leaves finalised objects alone.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation\TemporalSweep
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation\TemporalSweep;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Lifecycle\LifecycleDeclarationForms;

/**
 * The lifecycle field and terminal states of a schema.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class LifecycleTerminals {

	/**
	 * Wire the owner of the `final` shape rules.
	 *
	 * @param LifecycleDeclarationForms $finalForm Owns the shape rules for `initial` and `final`.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LifecycleDeclarationForms $finalForm = new LifecycleDeclarationForms(),
	) {
	}//end __construct()

	/**
	 * The schema's lifecycle field + terminal (final) states, or [null, []]
	 * when the schema declares no usable lifecycle. Schemas without a
	 * lifecycle sweep ALL their objects (bounded by the unchanged-skip).
	 *
	 * @param Schema $schema The schema to inspect.
	 *
	 * @return array{0: string|null, 1: array<int, string>} Lifecycle field + terminal states.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function of(Schema $schema): array {
		$config = ($schema->getConfiguration() ?? []);
		$lifecycle = ($config['x-openregister-lifecycle'] ?? null);
		if (is_array($lifecycle) === false) {
			return [null, []];
		}

		$field = $this->field(lifecycle: $lifecycle);
		if ($field === null) {
			return [null, []];
		}

		return [$field, $this->finalStates(lifecycle: $lifecycle)];
	}//end of()

	/**
	 * Whether an object's data sits in one of the terminal states.
	 *
	 * @param array{0: string|null, 1: array<int, string>} $terminals What {@see self::of()} returned.
	 * @param array<string, mixed> $data The object's data.
	 *
	 * @return bool True when the object is in a terminal state.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function isTerminal(array $terminals, array $data): bool {
		$field = $terminals[0];
		if ($field === null) {
			return false;
		}

		return in_array((string)($data[$field] ?? ''), $terminals[1], true);
	}//end isTerminal()

	/**
	 * The lifecycle field (`field`, else `property`), or null when unusable.
	 *
	 * @param array<string, mixed> $lifecycle The lifecycle block.
	 *
	 * @return string|null The field name.
	 */
	private function field(array $lifecycle): ?string {
		$field = ($lifecycle['field'] ?? ($lifecycle['property'] ?? null));
		if (is_string($field) === false || $field === '') {
			return null;
		}

		return $field;
	}//end field()

	/**
	 * The declared final states as strings.
	 *
	 * `final` also takes the reference form `{ from, field }`, whose ends are
	 * rows in another schema. This sweep compares stored strings, so it has
	 * nothing to compare them with and says so by naming no terminals,
	 * rather than stringifying the declaration into two states no object
	 * will ever be in. See LifecycleDeclarationForms.
	 *
	 * @param array<string, mixed> $lifecycle The lifecycle block.
	 *
	 * @return array<int, string> The terminal states.
	 */
	private function finalStates(array $lifecycle): array {
		$final = ($lifecycle['final'] ?? []);
		if (is_array($final) === false || $this->finalForm->isReferenceForm($final) === true) {
			return [];
		}

		return array_values(array_map('strval', $final));
	}//end finalStates()
}//end class
