<?php

/**
 * OpenRegister ContentReportFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\ContentReport
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

namespace OCA\OpenRegister\Db\ContentReport;

/**
 * The column types of a content report, in registration order.
 *
 * @spec exclude content report plumbing moved out of the entity, not a capability of its own
 */
final class ContentReportFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'objectUuid' => 'string',
		'register' => 'string',
		'schema' => 'string',
		'reason' => 'string',
		'reportedBy' => 'string',
		'status' => 'string',
		'copy' => 'json',
		'copyHash' => 'string',
		'reviewerGroup' => 'string',
		'retentionPeriod' => 'string',
		'expires' => 'datetime',
		'removedAt' => 'datetime',
		'removalAudit' => 'string',
		'organisationId' => 'string',
		'created' => 'datetime',
		'updated' => 'datetime',
	];
}//end class
