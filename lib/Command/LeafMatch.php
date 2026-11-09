<?php

/**
 * LeafMatch — one leaf organisation row and the organisation it merges into.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

/**
 * A leaf row ready for adoption: its fields and its merge target, if any.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
 */
final class LeafMatch {

	/**
	 * Pair a row with its merge target.
	 *
	 * @param array<string, mixed>      $fields The leaf row's fields.
	 * @param array<string, mixed>|null $target The organisation it merges into, if any.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $fields,
		public readonly ?array $target,
	) {
	}//end __construct()

	/**
	 * The row's uuid.
	 *
	 * @return string The uuid, or '' when the row carries none.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
	 */
	public function uuid(): string {
		return (string)($this->fields['uuid'] ?? '');
	}//end uuid()
}//end class
