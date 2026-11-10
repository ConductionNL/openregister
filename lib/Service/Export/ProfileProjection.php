<?php

/**
 * ProfileProjection — an export profile and the schema it is projected against.
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
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

use OCA\OpenRegister\Db\ExportProfile;
use OCA\OpenRegister\Db\Schema;

/**
 * The profile to write and, when it resolves, the schema for enum labels and relation hints.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ProfileProjection {
	/**
	 * Constructor.
	 *
	 * @param ExportProfile $profile The profile.
	 * @param Schema|null   $schema  The schema, when it resolves.
	 */
	public function __construct(
		public readonly ExportProfile $profile,
		public readonly ?Schema $schema=null,
	) {
	}//end __construct()
}//end class
