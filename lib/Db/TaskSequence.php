<?php

/**
 * One ordered approval cycle: an object's attempt at a gated transition.
 *
 * A sequence is the runtime half of what `ApprovalChain` used to conflate
 * with configuration (flow-approval-consolidation design D-3). The
 * configuration is the task TEMPLATE, compiled from the schema's
 * `x-openregister-approval-chains` entry and FROZEN onto this row as
 * `templateSnapshot` at provisioning, together with the resolved amount tier.
 * A schema edit or an amount edit after provisioning therefore cannot
 * re-shape or re-route an approval that is already half-decided.
 *
 * The positions are ordinary tasks carrying this sequence's uuid and an
 * ordinal; exactly one of them is enabled at a time. A sequence is terminal
 * in exactly one way per outcome (completed, rejected, terminated) and is
 * never re-opened: a further attempt at the same approval opens a NEW
 * sequence, and the old one, its decisions and its audit stay readable
 * (design D-5).
 *
 * `runUuid` and `nodeId` are optional provenance, exactly as on a task: a
 * sequence opened by a schema-declared gate has neither, and is first-class.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class TaskSequence
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getTemplateId()
 * @method void setTemplateId(?string $templateId)
 * @method integer|null getTemplateVersion()
 * @method void setTemplateVersion(?int $templateVersion)
 * @method array|null getTemplateSnapshot()
 * @method void setTemplateSnapshot(?array $templateSnapshot)
 * @method string|null getAnchorObjectUuid()
 * @method void setAnchorObjectUuid(?string $anchorObjectUuid)
 * @method integer|null getRegisterId()
 * @method void setRegisterId(?int $registerId)
 * @method integer|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method string|null getChainKey()
 * @method void setChainKey(?string $chainKey)
 * @method string|null getRequesterId()
 * @method void setRequesterId(?string $requesterId)
 * @method array|null getResolvedTier()
 * @method void setResolvedTier(?array $resolvedTier)
 * @method integer|null getPositionCursor()
 * @method void setPositionCursor(?int $positionCursor)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method string|null getOutcome()
 * @method void setOutcome(?string $outcome)
 * @method string|null getRunUuid()
 * @method void setRunUuid(?string $runUuid)
 * @method string|null getNodeId()
 * @method void setNodeId(?string $nodeId)
 * @method DateTime|null getOpenedAt()
 * @method void setOpenedAt(?DateTime $openedAt)
 * @method DateTime|null getClosedAt()
 * @method void setClosedAt(?DateTime $closedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 *
 * @SuppressWarnings(PHPMD.TooManyFields) One property per column of the
 * sequence table, the same rule Task itself carries: an Entity mirrors its
 * row, and hiding columns in a JSON bag would trade a countable shape for
 * an invisible one.
 */
class TaskSequence extends Entity implements JsonSerializable {

	/**
	 * A sequence with a running position.
	 *
	 * @var string
	 */
	public const STATUS_RUNNING = 'running';

	/**
	 * Every position completed with an approving outcome.
	 *
	 * @var string
	 */
	public const STATUS_COMPLETED = 'completed';

	/**
	 * A position completed with a rejecting outcome.
	 *
	 * @var string
	 */
	public const STATUS_REJECTED = 'rejected';

	/**
	 * Ended without a decision (a task was cancelled or terminated).
	 *
	 * @var string
	 */
	public const STATUS_TERMINATED = 'terminated';

	/**
	 * The terminal statuses. A sequence in one of these is never re-opened.
	 *
	 * @var array<int, string>
	 */
	public const TERMINAL_STATUSES = [
		self::STATUS_COMPLETED,
		self::STATUS_REJECTED,
		self::STATUS_TERMINATED,
	];

	/**
	 * Public identifier.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The compiled template this sequence was provisioned from.
	 *
	 * @var string|null
	 */
	protected ?string $templateId = null;

	/**
	 * The template version at provisioning.
	 *
	 * @var integer|null
	 */
	protected ?int $templateVersion = null;

	/**
	 * The template content FROZEN at provisioning. All later evaluation
	 * reads this, never the live schema annotation.
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $templateSnapshot = null;

	/**
	 * The object this approval is about.
	 *
	 * @var string|null
	 */
	protected ?string $anchorObjectUuid = null;

	/**
	 * The anchor's register.
	 *
	 * @var integer|null
	 */
	protected ?int $registerId = null;

	/**
	 * The anchor's schema.
	 *
	 * @var integer|null
	 */
	protected ?int $schemaId = null;

	/**
	 * The declarative chain key this sequence executes.
	 *
	 * @var string|null
	 */
	protected ?string $chainKey = null;

	/**
	 * Who attempted the gated transition. Separation of duties refuses this
	 * identity as a decider unless the declaration opts out.
	 *
	 * @var string|null
	 */
	protected ?string $requesterId = null;

	/**
	 * The amount tier the sequence was opened under, FROZEN at provisioning
	 * so a mid-cycle amount edit cannot re-route a running approval.
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $resolvedTier = null;

	/**
	 * The ordinal of the currently enabled position.
	 *
	 * @var integer|null
	 */
	protected ?int $positionCursor = 1;

	/**
	 * running, completed, rejected or terminated.
	 *
	 * @var string|null
	 */
	protected ?string $status = null;

	/**
	 * The recorded outcome, resolved from the frozen snapshot.
	 *
	 * @var string|null
	 */
	protected ?string $outcome = null;

	/**
	 * Optional provenance: the run that opened this sequence.
	 *
	 * @var string|null
	 */
	protected ?string $runUuid = null;

	/**
	 * Optional provenance: the node that opened this sequence.
	 *
	 * @var string|null
	 */
	protected ?string $nodeId = null;

	/**
	 * When the sequence opened. A new attempt after a rejection is
	 * distinguishable from the rejected one by this timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $openedAt = null;

	/**
	 * When the sequence reached a terminal status.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $closedAt = null;

	/**
	 * Constructor: registers the column types.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'templateId', type: 'string');
		$this->addType(fieldName: 'templateVersion', type: 'integer');
		$this->addType(fieldName: 'templateSnapshot', type: 'json');
		$this->addType(fieldName: 'anchorObjectUuid', type: 'string');
		$this->addType(fieldName: 'registerId', type: 'integer');
		$this->addType(fieldName: 'schemaId', type: 'integer');
		$this->addType(fieldName: 'chainKey', type: 'string');
		$this->addType(fieldName: 'requesterId', type: 'string');
		$this->addType(fieldName: 'resolvedTier', type: 'json');
		$this->addType(fieldName: 'positionCursor', type: 'integer');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'outcome', type: 'string');
		$this->addType(fieldName: 'runUuid', type: 'string');
		$this->addType(fieldName: 'nodeId', type: 'string');
		$this->addType(fieldName: 'openedAt', type: 'datetime');
		$this->addType(fieldName: 'closedAt', type: 'datetime');
	}//end __construct()

	/**
	 * Whether this sequence is in a terminal status.
	 *
	 * @return bool True when terminal.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
	 */
	public function isTerminal(): bool {
		return in_array((string)$this->status, self::TERMINAL_STATUSES, true);
	}//end isTerminal()

	/**
	 * Serialize for API and log consumption.
	 *
	 * @return array<string, mixed> The sequence as an array.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'templateId' => $this->templateId,
			'templateVersion' => $this->templateVersion,
			'templateSnapshot' => $this->templateSnapshot,
			'anchorObjectUuid' => $this->anchorObjectUuid,
			'registerId' => $this->registerId,
			'schemaId' => $this->schemaId,
			'chainKey' => $this->chainKey,
			'requesterId' => $this->requesterId,
			'resolvedTier' => $this->resolvedTier,
			'positionCursor' => $this->positionCursor,
			'status' => $this->status,
			'outcome' => $this->outcome,
			'runUuid' => $this->runUuid,
			'nodeId' => $this->nodeId,
			'openedAt' => $this->openedAt?->format('c'),
			'closedAt' => $this->closedAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
