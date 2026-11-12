<?php

/**
 * OpenRegister VerwerkingsactiviteitFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\Verwerkingsactiviteit
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

namespace OCA\OpenRegister\Db\Verwerkingsactiviteit;

/**
 * The column types of a processing activity, in registration order.
 *
 * @spec exclude processing activity plumbing moved out of the entity, not a capability of its own
 */
final class VerwerkingsactiviteitFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'code' => 'string',
		'name' => 'string',
		'description' => 'string',
		'purpose' => 'string',
		'legalBasis' => 'string',
		'dataSubjectCategories' => 'json',
		'personalDataCategories' => 'json',
		'retentionPeriod' => 'string',
		'recipients' => 'json',
		'internationalTransfers' => 'json',
		'technicalMeasures' => 'string',
		'organisationalMeasures' => 'string',
		'controller' => 'json',
		'dpoContactDetails' => 'json',
		'organisationId' => 'string',
		'status' => 'string',
		'created' => 'datetime',
		'updated' => 'datetime',
	];
}//end class
