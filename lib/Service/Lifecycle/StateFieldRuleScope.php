<?php

/**
 * OpenRegister StateFieldRuleScope
 *
 * Where one field rule entry of `x-openregister-lifecycle.states` sits: the
 * state, the rule kind and the schema properties it is checked against.
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
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * The state, rule kind and schema properties a field rule entry is validated in.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class StateFieldRuleScope {

	/**
	 * Constructor.
	 *
	 * @param string               $state      The state name.
	 * @param string               $kind       The rule kind the entry sits under.
	 * @param array<string, mixed> $properties The schema's declared properties.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $state,
		public readonly string $kind,
		public readonly array $properties,
	) {
	}//end __construct()

	/**
	 * The annotation path of the rule kind, as error messages name it.
	 *
	 * @return string The path, e.g. `x-openregister-lifecycle.states."open".fields."hidden"`.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function path(): string {
		return sprintf('x-openregister-lifecycle.states."%s".fields."%s"', $this->state, $this->kind);
	}//end path()
}//end class
