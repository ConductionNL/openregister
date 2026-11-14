<?php

/**
 * The stored text signature of one file (`openregister_text_signatures`).
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class TextSignature
 *
 * One row per extracted file. A null signature with a shingle count means the
 * text was too short to sign; a count of 0 means the file had no text.
 *
 * @method int|null getFileId()
 * @method void setFileId(?int $fileId)
 * @method string|null getSignature()
 * @method void setSignature(?string $signature)
 * @method int|null getShingleCount()
 * @method void setShingleCount(?int $shingleCount)
 * @method string|null getTextSha256()
 * @method void setTextSha256(?string $textSha256)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
 */
class TextSignature extends Entity implements JsonSerializable {

	/**
	 * The Nextcloud file id (filecache fileid).
	 *
	 * @var integer|null
	 */
	protected ?int $fileId = null;

	/**
	 * The MinHash values as a JSON list, or null when the text was too short.
	 *
	 * @var string|null
	 */
	protected ?string $signature = null;

	/**
	 * The number of distinct word shingles in the normalised text.
	 *
	 * @var integer|null
	 */
	protected ?int $shingleCount = null;

	/**
	 * The SHA-256 of the normalised text, lowercase hex.
	 *
	 * @var string|null
	 */
	protected ?string $textSha256 = null;

	/**
	 * When the signature was computed.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Constructor: registers the field types.
	 */
	public function __construct() {
		$this->addType(fieldName: 'fileId', type: 'integer');
		$this->addType(fieldName: 'signature', type: 'string');
		$this->addType(fieldName: 'shingleCount', type: 'integer');
		$this->addType(fieldName: 'textSha256', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');

	}//end __construct()

	/**
	 * Build a row from a signature record.
	 *
	 * @param int                                                                         $fileId  The file id.
	 * @param array{signature: list<int>|null, shingleCount: int, textSha256: string}    $record  The record from the signature service.
	 * @param DateTime                                                                    $created When it was computed.
	 *
	 * @return self The row.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
	 */
	public static function fromRecord(int $fileId, array $record, DateTime $created): self {
		$row = new self();
		$row->setFileId($fileId);
		$signature = null;
		if ($record['signature'] !== null) {
			$signature = json_encode($record['signature'], JSON_THROW_ON_ERROR);
		}

		$row->setSignature($signature);
		$row->setShingleCount($record['shingleCount']);
		$row->setTextSha256($record['textSha256']);
		$row->setCreated($created);

		return $row;
	}//end fromRecord()

	/**
	 * The MinHash values, or null when there is no signature or it does not decode to a list of integers.
	 *
	 * @return list<int>|null The values.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
	 */
	public function getSignatureValues(): ?array {
		if ($this->signature === null) {
			return null;
		}

		$values = json_decode($this->signature, true);
		if (is_array($values) === false || array_is_list($values) === false) {
			return null;
		}

		foreach ($values as $value) {
			if (is_int($value) === false) {
				return null;
			}
		}

		return $values;
	}//end getSignatureValues()

	/**
	 * JSON serialisation, dates as ISO 8601.
	 *
	 * @return array<string, mixed> The serialised record.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
	 */
	public function jsonSerialize(): array {
		return (new TypedFieldsJson())->serialise(fieldTypes: $this->getFieldTypes(), values: get_object_vars($this));

	}//end jsonSerialize()
}//end class
