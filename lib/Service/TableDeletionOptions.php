<?php

/**
 * OpenRegister table deletion options
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * How a whole-table delete runs: hard or soft, and the audit trigger it is recorded under.
 *
 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
 */
final class TableDeletionOptions {
	/**
	 * Constructor.
	 *
	 * @param bool        $hardDelete  True to remove the rows, false to soft-delete them.
	 * @param string|null $triggeredBy Audit trigger context; the method's own bulk trigger when null.
	 */
	public function __construct(
		public readonly bool $hardDelete = false,
		public readonly ?string $triggeredBy = null,
	) {
	}//end __construct()
}//end class
