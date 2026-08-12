<?php

/**
 * OpenRegister AnonymisationLog Entity
 *
 * Entity for recording anonymisation runs against `OCP\Files\File` nodes.
 *
 * The `sanitization` column carries the JSON-serialised
 * {@see \OCA\OpenRegister\Service\File\SanitizationReport} produced by the
 * Office document sanitiser. It is `null` for non-Office anonymisation runs
 * (PDF, plain text) per spec
 * `openspec/changes/office-document-sanitization/specs/office-document-sanitization/spec.md`
 * — see "Sanitisation report MUST be persisted on the anonymisation log".
 *
 * Per ADR-005 the entity stores ONLY counts and structural detail — never
 * source document content, comment text, metadata values, or hyperlink URLs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/office-document-sanitization/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * AnonymisationLog entity for tracking anonymisation runs against NC Files.
 *
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method int|null getRegisterId()
 * @method void setRegisterId(?int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method string getMimeType()
 * @method void setMimeType(string $mimeType)
 * @method string getEngine()
 * @method void setEngine(string $engine)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getReason()
 * @method void setReason(?string $reason)
 * @method string|null getSanitization()
 * @method void setSanitization(?string $sanitization)
 * @method int getReplacements()
 * @method void setReplacements(int $replacements)
 * @method int|null getDurationMs()
 * @method void setDurationMs(?int $durationMs)
 * @method DateTime getCreated()
 * @method void setCreated(DateTime $created)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class AnonymisationLog extends Entity implements JsonSerializable {

	/**
	 * Status: anonymisation completed successfully.
	 *
	 * @var string
	 */
	public const STATUS_SUCCESS = 'success';

	/**
	 * Status: anonymisation failed (see `reason`).
	 *
	 * @var string
	 */
	public const STATUS_FAILURE = 'failure';

	/**
	 * The Nextcloud file id of the source file.
	 *
	 * @var integer
	 */
	protected int $fileId = 0;

	/**
	 * The originating register object UUID (when applicable).
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The register id (when applicable).
	 *
	 * @var integer|null
	 */
	protected ?int $registerId = null;

	/**
	 * The schema id (when applicable).
	 *
	 * @var integer|null
	 */
	protected ?int $schemaId = null;

	/**
	 * The observed input MIME type.
	 *
	 * @var string
	 */
	protected string $mimeType = '';

	/**
	 * The engine / anonymiser class name.
	 *
	 * @var string
	 */
	protected string $engine = '';

	/**
	 * Outcome status (success | failure).
	 *
	 * @var string
	 */
	protected string $status = self::STATUS_SUCCESS;

	/**
	 * Structured failure-reason code (e.g. `encrypted`, `validation_failed`).
	 *
	 * @var string|null
	 */
	protected ?string $reason = null;

	/**
	 * JSON-serialised SanitizationReport (null for non-Office runs).
	 *
	 * @var string|null
	 */
	protected ?string $sanitization = null;

	/**
	 * Distinct replacements applied during the run.
	 *
	 * @var integer
	 */
	protected int $replacements = 0;

	/**
	 * Wall-clock duration of the run in milliseconds.
	 *
	 * @var integer|null
	 */
	protected ?int $durationMs = null;

	/**
	 * Created timestamp.
	 *
	 * @var DateTime
	 */
	protected DateTime $created;

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'fileId', type: 'integer');
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'registerId', type: 'integer');
		$this->addType(fieldName: 'schemaId', type: 'integer');
		$this->addType(fieldName: 'mimeType', type: 'string');
		$this->addType(fieldName: 'engine', type: 'string');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'reason', type: 'string');
		$this->addType(fieldName: 'sanitization', type: 'string');
		$this->addType(fieldName: 'replacements', type: 'integer');
		$this->addType(fieldName: 'durationMs', type: 'integer');
		$this->addType(fieldName: 'created', type: 'datetime');

		$this->created = new DateTime();
	}//end __construct()

	/**
	 * Decode the sanitisation JSON payload.
	 *
	 * @return array<string, mixed>|null The decoded report or null when absent.
	 */
	public function getSanitizationArray(): ?array {
		if ($this->sanitization === null) {
			return null;
		}

		$decoded = json_decode($this->sanitization, true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end getSanitizationArray()

	/**
	 * JSON serialize the entity.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'fileId' => $this->fileId,
			'objectUuid' => $this->objectUuid,
			'registerId' => $this->registerId,
			'schemaId' => $this->schemaId,
			'mimeType' => $this->mimeType,
			'engine' => $this->engine,
			'status' => $this->status,
			'reason' => $this->reason,
			'sanitization' => $this->getSanitizationArray(),
			'replacements' => $this->replacements,
			'durationMs' => $this->durationMs,
			'created' => $this->created->format('c'),
		];
	}//end jsonSerialize()
}//end class
