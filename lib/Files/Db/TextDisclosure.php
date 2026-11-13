<?php

/**
 * OpenRegister TextDisclosure Entity
 *
 * One external call that carried a document's text: which service, which
 * host, when, and how many bytes. Never the text itself.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Files\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-external-call-that-carries-a-documents-text-is-recorded-per-document-req-adi-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Db;

use DateTime;
use JsonSerializable;
use OCA\OpenRegister\Db\TypedFieldsJsonTrait;
use OCP\AppFramework\Db\Entity;

/**
 * A disclosure of a document's text to an external service.
 *
 * @method int|null getFileId()
 * @method void setFileId(?int $fileId)
 * @method string getSourceType()
 * @method void setSourceType(string $sourceType)
 * @method int getSourceId()
 * @method void setSourceId(int $sourceId)
 * @method string getService()
 * @method void setService(string $service)
 * @method string getHost()
 * @method void setHost(string $host)
 * @method DateTime getDisclosedAt()
 * @method void setDisclosedAt(DateTime $disclosedAt)
 * @method int getBytes()
 * @method void setBytes(int $bytes)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-external-call-that-carries-a-documents-text-is-recorded-per-document-req-adi-005
 */
class TextDisclosure extends Entity implements JsonSerializable {
	// Serialises every registered field in registration order, dates as ISO 8601.
	use TypedFieldsJsonTrait;

	/**
	 * The Nextcloud file id, null when the source is not a file.
	 *
	 * @var integer|null
	 */
	protected ?int $fileId = null;

	/**
	 * The source type: `file`, `object`, ...
	 *
	 * @var string
	 */
	protected string $sourceType = '';

	/**
	 * The source id.
	 *
	 * @var integer
	 */
	protected int $sourceId = 0;

	/**
	 * The service the text went to, such as Presidio.
	 *
	 * @var string
	 */
	protected string $service = '';

	/**
	 * The host the text went to.
	 *
	 * @var string
	 */
	protected string $host = '';

	/**
	 * The moment the text was sent.
	 *
	 * @var DateTime
	 */
	protected DateTime $disclosedAt;

	/**
	 * The number of bytes sent.
	 *
	 * @var integer
	 */
	protected int $bytes = 0;

	/**
	 * Register the field types.
	 */
	public function __construct() {
		$this->addType(fieldName: 'fileId', type: 'integer');
		$this->addType(fieldName: 'sourceType', type: 'string');
		$this->addType(fieldName: 'sourceId', type: 'integer');
		$this->addType(fieldName: 'service', type: 'string');
		$this->addType(fieldName: 'host', type: 'string');
		$this->addType(fieldName: 'disclosedAt', type: 'datetime');
		$this->addType(fieldName: 'bytes', type: 'integer');

		$this->disclosedAt = new DateTime();
	}//end __construct()
}//end class
