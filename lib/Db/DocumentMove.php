<?php

/**
 * One node moved by a document store move.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A file, folder or earlier version, its copy, and how far the move got.
 *
 * @method int|null getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int|null getOldId()
 * @method void setOldId(int $oldId)
 * @method int|null getNewId()
 * @method void setNewId(?int $newId)
 * @method string|null getKind()
 * @method void setKind(string $kind)
 * @method int|null getVersionTime()
 * @method void setVersionTime(?int $versionTime)
 * @method string|null getSha256()
 * @method void setSha256(?string $sha256)
 * @method string|null getState()
 * @method void setState(string $state)
 * @method string|null getReason()
 * @method void setReason(?string $reason)
 * @method DateTime|null getMovedAt()
 * @method void setMovedAt(DateTime $movedAt)
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */
class DocumentMove extends Entity implements JsonSerializable {

	public const KIND_FILE = 'file';
	public const KIND_FOLDER = 'folder';
	public const KIND_VERSION = 'version';

	public const STATE_COPIED = 'copied';
	public const STATE_VERIFIED = 'verified';
	public const STATE_SWITCHED = 'switched';
	public const STATE_REFUSED = 'refused';

	/**
	 * The register the node belongs to.
	 *
	 * @var integer|null
	 */
	protected ?int $registerId = null;

	/**
	 * The node id before the move.
	 *
	 * @var integer|null
	 */
	protected ?int $oldId = null;

	/**
	 * The copy's node id, null when no copy was made.
	 *
	 * @var integer|null
	 */
	protected ?int $newId = null;

	/**
	 * `file`, `folder` or `version`.
	 *
	 * @var string|null
	 */
	protected ?string $kind = null;

	/**
	 * For a version: its original time, as Nextcloud recorded it.
	 *
	 * @var integer|null
	 */
	protected ?int $versionTime = null;

	/**
	 * The SHA-256 the source and the copy were checked against.
	 *
	 * @var string|null
	 */
	protected ?string $sha256 = null;

	/**
	 * `copied`, `verified`, `switched` or `refused`.
	 *
	 * @var string|null
	 */
	protected ?string $state = null;

	/**
	 * Why a row was refused, or what was recorded about it.
	 *
	 * @var string|null
	 */
	protected ?string $reason = null;

	/**
	 * When the row last changed.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $movedAt = null;

	/**
	 * Declare the column types.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function __construct() {
		$this->addType(fieldName: 'registerId', type: 'integer');
		$this->addType(fieldName: 'oldId', type: 'integer');
		$this->addType(fieldName: 'newId', type: 'integer');
		$this->addType(fieldName: 'kind', type: 'string');
		$this->addType(fieldName: 'versionTime', type: 'integer');
		$this->addType(fieldName: 'sha256', type: 'string');
		$this->addType(fieldName: 'state', type: 'string');
		$this->addType(fieldName: 'reason', type: 'string');
		$this->addType(fieldName: 'movedAt', type: 'datetime');

	}//end __construct()

	/**
	 * The row as JSON.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function jsonSerialize(): array {
		return (new TypedFieldsJson())->serialise(fieldTypes: $this->getFieldTypes(), values: get_object_vars($this));

	}//end jsonSerialize()
}//end class
