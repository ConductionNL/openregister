<?php

/**
 * OpenRegister RedactionReviewMark
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A person's statement that they checked a file's detection run.
 *
 * Bound to the run by its fingerprint: a re-detection that finds something
 * else no longer matches, and the file needs a new check.
 *
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getRunFingerprint()
 * @method void setRunFingerprint(string $runFingerprint)
 * @method string getCheckedBy()
 * @method void setCheckedBy(string $checkedBy)
 * @method DateTime|null getCheckedAt()
 * @method void setCheckedAt(DateTime $checkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class RedactionReviewMark extends Entity implements JsonSerializable {

	/**
	 * The checked file.
	 *
	 * @var integer
	 */
	protected int $fileId = 0;

	/**
	 * The fingerprint of the detection run that was checked.
	 *
	 * @var string
	 */
	protected string $runFingerprint = '';

	/**
	 * The UID of the person who checked.
	 *
	 * @var string
	 */
	protected string $checkedBy = '';

	/**
	 * When they checked.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $checkedAt = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'fileId', type: 'integer');
		$this->addType(fieldName: 'runFingerprint', type: 'string');
		$this->addType(fieldName: 'checkedBy', type: 'string');
		$this->addType(fieldName: 'checkedAt', type: 'datetime');
	}//end __construct()

	/**
	 * The mark as JSON.
	 *
	 * @return array{id: int|null, fileId: int, runFingerprint: string, checkedBy: string, checkedAt: string|null}
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.2
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'fileId' => $this->fileId,
			'runFingerprint' => $this->runFingerprint,
			'checkedBy' => $this->checkedBy,
			'checkedAt' => $this->checkedAt?->format(DATE_ATOM),
		];
	}//end jsonSerialize()
}//end class
