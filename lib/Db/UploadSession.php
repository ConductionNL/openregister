<?php

/**
 * UploadSession entity: one upload of a large file in parts, to one object.
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
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-a-large-file-uploads-in-parts-with-stated-boundaries-req-lfh-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;

/**
 * An upload session: the declared name, size and SHA-256 of a file, the part
 * size it is cut into, the parts received so far and when it expires.
 *
 * The parts themselves live in the app's data store under `uploads/{uploadId}`,
 * never in the object's folder; this row only states what was declared and
 * what has arrived. `received` is a JSON list of part numbers, from 1.
 *
 * @method string getUploadId()
 * @method void setUploadId(string $uploadId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method string getName()
 * @method void setName(string $name)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method string getSha256()
 * @method void setSha256(string $sha256)
 * @method int getPartSize()
 * @method void setPartSize(int $partSize)
 * @method string getReceived()
 * @method void setReceived(string $received)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(?DateTime $expiresAt)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-a-large-file-uploads-in-parts-with-stated-boundaries-req-lfh-002
 */
class UploadSession extends Entity {

	/**
	 * The public id of the session, a UUID.
	 *
	 * @var string
	 */
	protected string $uploadId = '';

	/**
	 * The user who opened it; nobody else may use it.
	 *
	 * @var string
	 */
	protected string $userId = '';

	/**
	 * The UUID of the object the file is for.
	 *
	 * @var string
	 */
	protected string $objectUuid = '';

	/**
	 * The declared file name.
	 *
	 * @var string
	 */
	protected string $name = '';

	/**
	 * The declared size in bytes.
	 *
	 * @var integer
	 */
	protected int $size = 0;

	/**
	 * The declared SHA-256, lowercase hex.
	 *
	 * @var string
	 */
	protected string $sha256 = '';

	/**
	 * The part size in bytes; the last part may be shorter.
	 *
	 * @var integer
	 */
	protected int $partSize = 0;

	/**
	 * The received part numbers, as a JSON list.
	 *
	 * @var string
	 */
	protected string $received = '[]';

	/**
	 * When the session expires unless a part arrives first.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $expiresAt = null;

	/**
	 * When the session was opened.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Declare the column types.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uploadId', type: 'string');
		$this->addType(fieldName: 'userId', type: 'string');
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'name', type: 'string');
		$this->addType(fieldName: 'size', type: 'integer');
		$this->addType(fieldName: 'sha256', type: 'string');
		$this->addType(fieldName: 'partSize', type: 'integer');
		$this->addType(fieldName: 'received', type: 'string');
		$this->addType(fieldName: 'expiresAt', type: 'datetime');
		$this->addType(fieldName: 'created', type: 'datetime');
	}//end __construct()

	/**
	 * How many parts the declared size is cut into: at least one.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-2-1
	 */
	public function partCount(): int {
		if ($this->partSize <= 0) {
			return 1;
		}

		return max(1, intdiv($this->size + $this->partSize - 1, $this->partSize));
	}//end partCount()

	/**
	 * The byte range of part n: the first byte and the last byte, both inclusive.
	 *
	 * @param int $n The part number, from 1.
	 *
	 * @return array{n: int, start: int, end: int}
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-2-1
	 */
	public function partRange(int $n): array {
		$start = ($n - 1) * $this->partSize;
		$end   = (min($n * $this->partSize, $this->size) - 1);

		return ['n' => $n, 'start' => $start, 'end' => $end];
	}//end partRange()

	/**
	 * The length part n must have.
	 *
	 * @param int $n The part number, from 1.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-2-1
	 */
	public function partLength(int $n): int {
		$range = $this->partRange(n: $n);

		return ($range['end'] - $range['start'] + 1);
	}//end partLength()

	/**
	 * The received part numbers, ascending.
	 *
	 * @return list<int>
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-2-1
	 */
	public function receivedParts(): array {
		$decoded = json_decode($this->received, true);
		if (is_array($decoded) === false) {
			return [];
		}

		$parts = array_values(array_unique(array_map('intval', array_filter($decoded, 'is_int'))));
		sort($parts);

		return $parts;
	}//end receivedParts()

	/**
	 * Record part n as received.
	 *
	 * @param int $n The part number.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-2-1
	 */
	public function markReceived(int $n): void {
		$parts   = $this->receivedParts();
		$parts[] = $n;
		$parts   = array_values(array_unique($parts));
		sort($parts);
		$this->setReceived((string) json_encode($parts));
	}//end markReceived()

	/**
	 * The part numbers not received yet, ascending.
	 *
	 * @return list<int>
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-2-1
	 */
	public function missingParts(): array {
		return array_values(array_diff(range(1, $this->partCount()), $this->receivedParts()));
	}//end missingParts()
}//end class
