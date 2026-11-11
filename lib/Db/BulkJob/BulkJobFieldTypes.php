<?php

/**
 * OpenRegister BulkJobFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\BulkJob
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

namespace OCA\OpenRegister\Db\BulkJob;

/**
 * The column types of a bulk job, in registration order.
 *
 * @spec exclude bulk job plumbing moved out of the entity, not a capability of its own
 */
final class BulkJobFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'action' => 'string',
		'parameters' => 'json',
		'selectionType' => 'string',
		'selection' => 'json',
		'registerId' => 'integer',
		'schemaId' => 'integer',
		'justification' => 'string',
		'state' => 'string',
		'total' => 'integer',
		'processed' => 'integer',
		'applied' => 'integer',
		'skipped' => 'integer',
		'refused' => 'integer',
		'failed' => 'integer',
		'cursor' => 'integer',
		'report' => 'json',
		'startedBy' => 'string',
		'reversalWindow' => 'integer',
		'reversibleUntil' => 'datetime',
		'reversesJobId' => 'integer',
		'reversedByJobId' => 'integer',
		'created' => 'datetime',
		'updated' => 'datetime',
	];
}//end class
