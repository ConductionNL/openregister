<?php

/**
 * A configuration import: whether it was forced and how many keys it overwrote.
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
 *
 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * What one configuration import did to the stored settings.
 *
 * @spec openspec/changes/settings-change-audit/specs/audit-trail-immutable/spec.md
 */
final class SettingsImport {
	/**
	 * Describe the import.
	 *
	 * @param bool $forced          Whether the import was forced over an existing one.
	 * @param int  $keysOverwritten How many stored keys the import replaced.
	 */
	public function __construct(
		public readonly bool $forced,
		public readonly int $keysOverwritten,
	) {
	}//end __construct()
}//end class
