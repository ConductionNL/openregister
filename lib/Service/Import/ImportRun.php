<?php

/**
 * OpenRegister ImportRun
 *
 * One pass of an import preview: deciding its rows, or committing them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use OCA\OpenRegister\Db\ImportPreview;
use OCP\IUser;

/**
 * One pass of an import preview: the preview, where it reads into and writes
 * to, the saved column mapping its rows are decided with, and who runs it.
 *
 * These travel together through every row decision and every write of
 * {@see ImportPreviewService}, so they are passed as one. Deciding a preview
 * carries a mapping and no actor; committing one carries an actor and no mapping.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
final class ImportRun {

	/**
	 * Constructor.
	 *
	 * @param ImportPreview             $preview     The preview being decided or committed.
	 * @param ImportDestination         $destination The target register and schema.
	 * @param array<string, mixed>|null $pack        The saved column mapping, if any.
	 * @param IUser|null                $currentUser The actor writing the rows, if any.
	 */
	public function __construct(
		public readonly ImportPreview $preview,
		public readonly ImportDestination $destination,
		public readonly ?array $pack = null,
		public readonly ?IUser $currentUser = null,
	) {
	}//end __construct()
}//end class
