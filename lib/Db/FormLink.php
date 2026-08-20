<?php

/**
 * FormLink entity for linking NC Forms forms and submissions to
 * OpenRegister objects.
 *
 * One row models either a form-level link (`submissionId === null`) or
 * a per-submission link (`submissionId !== null`) so a single object
 * can carry both a "this is the intake form" pointer and "alice
 * submitted on 2026-01-02" pointers. The composite unique index in
 * the migration `(object_uuid, form_id, submission_id)` enforces this
 * dual shape.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class FormLink
 *
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method int getFormId()
 * @method void setFormId(int $formId)
 * @method string|null getFormHash()
 * @method void setFormHash(?string $formHash)
 * @method int|null getSubmissionId()
 * @method void setSubmissionId(?int $submissionId)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(?DateTime $expiresAt)
 * @method string getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime getLinkedAt()
 * @method void setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class FormLink extends Entity implements JsonSerializable {

	/**
	 * The OR object uuid.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The OR register id.
	 *
	 * @var integer|null
	 */
	protected ?int $registerId = null;

	/**
	 * The OR schema id.
	 *
	 * @var integer|null
	 */
	protected ?int $schemaId = null;

	/**
	 * The NC Forms form id (numeric primary key in `forms_v2_forms`).
	 *
	 * @var integer|null
	 */
	protected ?int $formId = null;

	/**
	 * The NC Forms form hash (URL-safe public identifier).
	 *
	 * @var string|null
	 */
	protected ?string $formHash = null;

	/**
	 * The NC Forms submission id (numeric primary key in
	 * `forms_v2_submissions`), or null for form-level links.
	 *
	 * @var integer|null
	 */
	protected ?int $submissionId = null;

	/**
	 * Form title cached at link-time.
	 *
	 * @var string|null
	 */
	protected ?string $title = null;

	/**
	 * Form status snapshot at link-time (`open` / `closed` / `draft`).
	 *
	 * @var string|null
	 */
	protected ?string $status = null;

	/**
	 * Form expiry timestamp snapshot.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $expiresAt = null;

	/**
	 * The user id of the user that created the link.
	 *
	 * @var string|null
	 */
	protected ?string $linkedBy = null;

	/**
	 * The link creation timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $linkedAt = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'registerId', type: 'integer');
		$this->addType(fieldName: 'schemaId', type: 'integer');
		$this->addType(fieldName: 'formId', type: 'integer');
		$this->addType(fieldName: 'formHash', type: 'string');
		$this->addType(fieldName: 'submissionId', type: 'integer');
		$this->addType(fieldName: 'title', type: 'string');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'expiresAt', type: 'datetime');
		$this->addType(fieldName: 'linkedBy', type: 'string');
		$this->addType(fieldName: 'linkedAt', type: 'datetime');

	}//end __construct()

	/**
	 * JSON serialization.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'objectUuid' => $this->objectUuid,
			'registerId' => $this->registerId,
			'schemaId' => $this->schemaId,
			'formId' => $this->formId,
			'formHash' => $this->formHash,
			'submissionId' => $this->submissionId,
			'title' => $this->title,
			'status' => $this->status,
			'expiresAt' => $this->expiresAt?->format(DateTime::ATOM),
			'linkedBy' => $this->linkedBy,
			'linkedAt' => $this->linkedAt?->format(DateTime::ATOM),
		];

	}//end jsonSerialize()
}//end class
