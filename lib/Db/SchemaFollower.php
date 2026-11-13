<?php

/**
 * SchemaFollower entity — one user following one schema's breaking changes.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-a-reader-may-follow-a-schemas-breaking-changes
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;

/**
 * A user who asked to be told about a schema's breaking changes.
 *
 * @method int getSchemaId()
 * @method void setSchemaId(int $schemaId)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 *
 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-a-reader-may-follow-a-schemas-breaking-changes
 */
class SchemaFollower extends Entity {

	/**
	 * The followed schema.
	 *
	 * @var integer|null
	 */
	protected ?int $schemaId = null;

	/**
	 * The following user.
	 *
	 * @var string|null
	 */
	protected ?string $uid = null;

	/**
	 * When the user started following.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Constructor.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-a-reader-may-follow-a-schemas-breaking-changes
	 */
	public function __construct() {
		$this->addType(fieldName: 'schemaId', type: 'integer');
		$this->addType(fieldName: 'uid', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');
	}//end __construct()
}//end class
