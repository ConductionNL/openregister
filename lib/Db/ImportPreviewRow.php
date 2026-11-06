<?php

/**
 * ImportPreviewRow entity — what one source row would do, and why.
 *
 * One row per row of the source file, carrying the decision the conflict
 * policy reached, the reason when that decision is a skip or a refusal, the
 * object the row would write to, every candidate the match key hit, and the
 * mapped payload the commit writes. The commit applies these decisions rather
 * than deciding again, so the preview an operator approved is the import that
 * runs (D-1).
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
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class ImportPreviewRow
 *
 * @method int|null getPreviewId()
 * @method void setPreviewId(?int $previewId)
 * @method int getRowNumber()
 * @method void setRowNumber(int $rowNumber)
 * @method string|null getDecision()
 * @method void setDecision(?string $decision)
 * @method string|null getReason()
 * @method void setReason(?string $reason)
 * @method string|null getTargetUuid()
 * @method void setTargetUuid(?string $targetUuid)
 * @method array|null getCandidates()
 * @method void setCandidates(?array $candidates)
 * @method array|null getPayload()
 * @method void setPayload(?array $payload)
 * @method DateTime|null getAppliedAt()
 * @method void setAppliedAt(?DateTime $appliedAt)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class ImportPreviewRow extends Entity implements JsonSerializable {

	/**
	 * The row would create a new object.
	 *
	 * @var string
	 */
	public const DECISION_CREATE = 'create';

	/**
	 * The row would update the object it matched.
	 *
	 * @var string
	 */
	public const DECISION_UPDATE = 'update';

	/**
	 * The policy leaves the row alone, with a reason.
	 *
	 * @var string
	 */
	public const DECISION_SKIP = 'skip';

	/**
	 * The row is refused, with a reason, and nothing is written for it.
	 *
	 * @var string
	 */
	public const DECISION_REFUSE = 'refuse';

	/**
	 * Every decision a row may carry.
	 *
	 * @var array<int, string>
	 */
	public const DECISIONS = [
		self::DECISION_CREATE,
		self::DECISION_UPDATE,
		self::DECISION_SKIP,
		self::DECISION_REFUSE,
	];

	/**
	 * The preview this row belongs to.
	 *
	 * @var integer|null
	 */
	protected ?int $previewId = null;

	/**
	 * The 1-based row number in the source file.
	 *
	 * @var integer
	 */
	protected int $rowNumber = 0;

	/**
	 * The decision.
	 *
	 * @var string|null
	 */
	protected ?string $decision = null;

	/**
	 * Why, when the decision is a skip or a refusal.
	 *
	 * @var string|null
	 */
	protected ?string $reason = null;

	/**
	 * The object this row would write to, when there is exactly one.
	 *
	 * @var string|null
	 */
	protected ?string $targetUuid = null;

	/**
	 * Every object the match key hit, so a refusal names them (D-3).
	 *
	 * @var array<int, string>|null
	 */
	protected ?array $candidates = null;

	/**
	 * The mapped object the commit writes.
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $payload = null;

	/**
	 * Stamped only by a real write, so a retried commit does not write twice.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $appliedAt = null;

	/**
	 * Creation timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Constructor — registers field types for hydration.
	 */
	public function __construct() {
		$this->addType(fieldName: 'previewId', type: 'integer');
		$this->addType(fieldName: 'rowNumber', type: 'integer');
		$this->addType(fieldName: 'decision', type: 'string');
		$this->addType(fieldName: 'reason', type: 'string');
		$this->addType(fieldName: 'targetUuid', type: 'string');
		$this->addType(fieldName: 'candidates', type: 'json');
		$this->addType(fieldName: 'payload', type: 'json');
		$this->addType(fieldName: 'appliedAt', type: 'datetime');
		$this->addType(fieldName: 'created', type: 'datetime');

	}//end __construct()

	/**
	 * Whether this decision writes anything at commit.
	 *
	 * @return bool True for a create or an update.
	 */
	public function writes(): bool {
		return in_array($this->decision, [self::DECISION_CREATE, self::DECISION_UPDATE], true);
	}//end writes()

	/**
	 * JSON serialisation.
	 *
	 * @return array<string, mixed> The serialised row.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'previewId' => $this->previewId,
			'row' => $this->rowNumber,
			'decision' => $this->decision,
			'reason' => $this->reason,
			'targetUuid' => $this->targetUuid,
			'candidates' => ($this->candidates ?? []),
			'payload' => ($this->payload ?? []),
			'appliedAt' => $this->appliedAt?->format('c'),
			'created' => $this->created?->format('c'),
		];
	}//end jsonSerialize()
}//end class
