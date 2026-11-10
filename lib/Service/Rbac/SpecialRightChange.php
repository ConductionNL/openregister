<?php

/**
 * OpenRegister special right change
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * A change of the groups that hold the special right to manage grants on an
 * organisation's records: the list before the save and the list after it.
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */
final class SpecialRightChange {
	/**
	 * Constructor.
	 *
	 * @param array<int|string, mixed> $before The list before.
	 * @param array<int|string, mixed> $after  The list after.
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function __construct(
		public readonly array $before,
		public readonly array $after,
	) {
	}//end __construct()
}//end class
