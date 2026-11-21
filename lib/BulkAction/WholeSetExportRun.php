<?php

/**
 * OpenRegister whole-set export run
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BulkAction
 * @package  OCA\OpenRegister\BulkAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BulkAction;

use OCA\OpenRegister\Db\ExportProfile;
use OCA\OpenRegister\Db\Schema;
use OCP\IUser;

/**
 * The state of exporting one row of a whole-set job (ADR-011 Rule 3): the
 * step, its profile and actor, and the register and schema the row belongs
 * to, coerced once. It never leaves ExportWholeSetAction.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class WholeSetExportRun {

	/**
	 * The object's register, as an int, or null when it has none.
	 *
	 * @var integer|null
	 */
	public ?int $registerId = null;

	/**
	 * The object's schema, as an int, or null when it has none.
	 *
	 * @var integer|null
	 */
	public ?int $schemaId = null;

	/**
	 * The object's schema, or null when it cannot be read.
	 *
	 * @var Schema|null
	 */
	public ?Schema $schema = null;

	/**
	 * Constructor.
	 *
	 * @param BulkStep      $step    The member, rehearsal or commit.
	 * @param ExportProfile $profile The whole-set profile.
	 * @param IUser         $actor   The user the job runs as.
	 */
	public function __construct(
		public readonly BulkStep $step,
		public readonly ExportProfile $profile,
		public readonly IUser $actor,
	) {
	}//end __construct()
}//end class
