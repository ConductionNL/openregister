<?php

/**
 * OpenRegister SubjectExportFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\SubjectExport
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

namespace OCA\OpenRegister\Db\SubjectExport;

/**
 * The column types of a subject export, in registration order.
 *
 * @spec exclude subject export plumbing moved out of the entity, not a capability of its own
 */
final class SubjectExportFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'subject' => 'string',
		'subjectType' => 'string',
		'status' => 'string',
		'requestedBy' => 'string',
		'requestId' => 'string',
		'objectCount' => 'integer',
		'contentHash' => 'string',
		'error' => 'string',
		'readyAt' => 'datetime',
		'expiresAt' => 'datetime',
		'deliveredAt' => 'datetime',
		'created' => 'datetime',
		'updated' => 'datetime',
	];
}//end class
