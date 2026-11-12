<?php

/**
 * OpenRegister ErasurePreviewFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\ErasurePreview
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

namespace OCA\OpenRegister\Db\ErasurePreview;

/**
 * The column types of an erasure preview, in registration order.
 *
 * @spec exclude erasure preview plumbing moved out of the entity, not a capability of its own
 */
final class ErasurePreviewFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'subject' => 'string',
		'subjectType' => 'string',
		'eraseMode' => 'string',
		'requestId' => 'string',
		'digest' => 'string',
		'report' => 'json',
		'outcome' => 'json',
		'status' => 'string',
		'createdBy' => 'string',
		'approvedBy' => 'string',
		'approvedAt' => 'datetime',
		'consumedAt' => 'datetime',
		'created' => 'datetime',
		'updated' => 'datetime',
	];
}//end class
