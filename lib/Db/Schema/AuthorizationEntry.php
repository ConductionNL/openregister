<?php

/**
 * AuthorizationEntry: one key of a schema authorization block, its value and where it sits.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\Schema;

/**
 * One entry of an authorization block under validation: an action or control
 * key (or a match operator inside a rule), its raw value, and the
 * human-readable location the refusal messages name.
 *
 * No strict_types: the validators that build it declare none, so the value
 * keeps the coercion it had there.
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */
final class AuthorizationEntry {

	/**
	 * Bundle the entry.
	 *
	 * @param int|string $key     The key: an action, a control key or a match operator.
	 * @param mixed      $value   Its raw (untrusted) value.
	 * @param string     $context Where it sits, for the refusal messages.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function __construct(
		public readonly int|string $key,
		public readonly mixed $value,
		public readonly string $context,
	) {
	}//end __construct()

	/**
	 * The location of a rule listed under this entry's action.
	 *
	 * @return string `action '<key>' in <context>`.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function where(): string {
		return "action '{$this->key}' in {$this->context}";
	}//end where()
}//end class
