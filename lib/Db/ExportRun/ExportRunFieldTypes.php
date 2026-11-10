<?php

/**
 * OpenRegister ExportRunFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\ExportRun
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

namespace OCA\OpenRegister\Db\ExportRun;

/**
 * The column types of a export run, in registration order.
 *
 * @spec exclude export run plumbing moved out of the entity, not a capability of its own
 */
final class ExportRunFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'source' => 'string',
		'profile' => 'string',
		'actor' => 'string',
		'registerName' => 'string',
		'schemaName' => 'string',
		'format' => 'string',
		'filename' => 'string',
		'rowCount' => 'integer',
		'fileId' => 'integer',
		'filePath' => 'string',
		'downloadCount' => 'integer',
		'retentionSeconds' => 'integer',
		'status' => 'string',
		'producedAt' => 'datetime',
		'expiresAt' => 'datetime',
		'created' => 'datetime',
		'updated' => 'datetime',
	];
}//end class
