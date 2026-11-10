<?php

/**
 * ProjectionRun — one profile projection in progress.
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

use OCA\OpenRegister\Db\ExportProfile;
use OCA\OpenRegister\Db\Schema;

/**
 * The profile being written, the schema it resolves against and its field set.
 *
 * Built by ExportProfileWriter for one write and never handed outside it
 * (ADR-011 Rule 3).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ProjectionRun {

	/**
	 * The ordered field set the profile declares.
	 *
	 * @var array<int, string>
	 */
	public readonly array $fields;

	/**
	 * Start a projection.
	 *
	 * @param ExportProfile $profile The profile.
	 * @param Schema|null   $schema  The schema, when it resolves, for enum labels and relation hints.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ExportProfile $profile,
		public readonly ?Schema $schema,
	) {
		$this->fields = $profile->getFieldsArray();
	}//end __construct()

	/**
	 * Whether the profile writes values as a surface would show them.
	 *
	 * @return bool True in the rendered value mode.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function rendered(): bool {
		return (($this->profile->getValueMode() ?? ExportProfile::MODE_STORED) === ExportProfile::MODE_RENDERED);
	}//end rendered()
}//end class
