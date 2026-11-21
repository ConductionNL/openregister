<?php

/**
 * ExportScope — which register and schema an export touched, and as whom.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
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

namespace OCA\OpenRegister\Service\Export;

/**
 * The register and schema an export read or was refused, and the principal it
 * ran as when that is not the session user.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ExportScope {

	/**
	 * Describe the scope.
	 *
	 * @param int|null    $register The register.
	 * @param int|null    $schema   The schema.
	 * @param string|null $actorId  The principal, when it is not the session user.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ?int $register,
		public readonly ?int $schema,
		public readonly ?string $actorId,
	) {
	}//end __construct()
}//end class
