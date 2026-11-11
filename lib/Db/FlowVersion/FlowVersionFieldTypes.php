<?php

/**
 * OpenRegister FlowVersionFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\FlowVersion
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

namespace OCA\OpenRegister\Db\FlowVersion;

/**
 * The column types of a flow version, in registration order, and its API field order.
 *
 * @spec exclude flow version plumbing moved out of the entity, not a capability of its own
 */
final class FlowVersionFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'flowUuid' => 'string',
		'version' => 'integer',
		'status' => 'string',
		'definitionHash' => 'string',
		'owner' => 'string',
		'organisation' => 'string',
		'publishedAt' => 'datetime',
		'publishedBy' => 'string',
		'deprecatedAt' => 'datetime',
		'semver' => 'string',
		'semverSource' => 'string',
		'created' => 'datetime',
	];

	/**
	 * The serialised fields after the id, in API order; `semverSource` sits beside
	 * `semver` so a back-filled version can be distrusted correctly.
	 *
	 * @var string[]
	 */
	public const JSON_ORDER = [
		'flowUuid', 'version', 'semver', 'semverSource', 'status', 'definitionHash',
		'owner', 'organisation', 'publishedAt', 'publishedBy', 'deprecatedAt', 'created',
	];
}//end class
