<?php

/**
 * OpenRegister ExportProfileCaller
 *
 * Who is asking for an export profile, and whether they administer the instance.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

/**
 * The caller {@see ExportProfileServiceInterface::assertOwnerOrAdmin()} decides about.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ExportProfileCaller {

	/**
	 * Name the caller.
	 *
	 * @param string $uid     The caller's uid.
	 * @param bool   $isAdmin Whether the caller is an instance administrator.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly string $uid,
		public readonly bool $isAdmin,
	) {
	}//end __construct()
}//end class
