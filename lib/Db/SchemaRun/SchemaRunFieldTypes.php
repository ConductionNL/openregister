<?php

/**
 * OpenRegister SchemaRunFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\SchemaRun
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

namespace OCA\OpenRegister\Db\SchemaRun;

/**
 * The column types of a schema run, in registration order.
 *
 * @spec exclude schema run plumbing moved out of the entity, not a capability of its own
 */
final class SchemaRunFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'schemaId' => 'integer',
		'registerId' => 'integer',
		'type' => 'string',
		'state' => 'string',
		'proposedDefinition' => 'json',
		'plan' => 'json',
		'options' => 'json',
		'processed' => 'integer',
		'total' => 'integer',
		'cursor' => 'integer',
		'report' => 'json',
		'startedBy' => 'string',
		'rolledBackFrom' => 'integer',
		'created' => 'datetime',
		'updated' => 'datetime',
	];
}//end class
