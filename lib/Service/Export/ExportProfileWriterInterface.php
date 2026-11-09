<?php

/**
 * ExportProfileWriterInterface: what callers outside the component use of ExportProfileWriter.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

use OCA\OpenRegister\Db\ExportProfile;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * Declares only what callers outside the component use of ExportProfileWriter; the rest
 * of the class stays its own.
 */
interface ExportProfileWriterInterface {

	/**
	 * The prefix of the CSV metadata line.
	 *
	 * @var string
	 */
	public const CSV_METADATA_PREFIX = '#openregister-export ';

	/**
	 * One CSV data line for a single object, with no header and no metadata.
	 *
	 * The whole-set extract writes one object at a time into a file that is
	 * already open, so it needs the row without the envelope the request path
	 * builds around it.
	 *
	 * @param ExportProfile $profile The profile.
	 * @param ObjectEntity  $object  The object.
	 * @param Schema|null   $schema  The schema, when it resolves.
	 *
	 * @return string The CSV line, newline terminated.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function csvLineFor(ExportProfile $profile, ObjectEntity $object, ?Schema $schema = null): string;

	/**
	 * The CSV header line for a profile, with its metadata line above it.
	 *
	 * @param ExportProfile $profile The profile.
	 *
	 * @return string The two opening lines of a whole-set file.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function csvOpeningFor(ExportProfile $profile): string;
}//end interface
