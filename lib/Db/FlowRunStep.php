<?php

/**
 * One node execution within a flow run.
 *
 * The run row already carried this history as a `log` JSON blob, which answers
 * "what happened in this run" and nothing else. Promoting each hop to a row is
 * what makes the questions people actually ask answerable: which node type fails
 * most, every failed step for one flow, what a given node output on a given day.
 * It also gives retention something it can prune per flow rather than per
 * instance.
 *
 * Steps are APPENDED across a resume, never renumbered. A run that suspends on a
 * wait node and resumes later must read as one ordered history, so `sequence`
 * continues from where the walk stopped.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class FlowRunStep
 *
 * @method string|null getRunUuid()
 * @method void setRunUuid(?string $runUuid)
 * @method string|null getFlowId()
 * @method void setFlowId(?string $flowId)
 * @method string|null getNodeId()
 * @method void setNodeId(?string $nodeId)
 * @method string|null getNodeType()
 * @method void setNodeType(?string $nodeType)
 * @method integer|null getSequence()
 * @method void setSequence(?int $sequence)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method DateTime|null getStarted()
 * @method void setStarted(?DateTime $started)
 * @method DateTime|null getFinished()
 * @method void setFinished(?DateTime $finished)
 * @method integer|null getDurationMs()
 * @method void setDurationMs(?int $durationMs)
 * @method array|null getOutput()
 * @method void setOutput(?array $output)
 * @method string|null getError()
 * @method void setError(?string $error)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
 */
class FlowRunStep extends Entity implements JsonSerializable {
	/**
	 * The step completed without error.
	 *
	 * @var string
	 */
	public const STATUS_OK = 'ok';

	/**
	 * The step threw, or its node could not be resolved.
	 *
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * The step suspended the run (a wait node).
	 *
	 * @var string
	 */
	public const STATUS_SUSPENDED = 'suspended';

	/**
	 * The step stopped the walk (a stop node, or a limit ceiling).
	 *
	 * @var string
	 */
	public const STATUS_STOPPED = 'stopped';

	/**
	 * The run this step belongs to, by uuid.
	 *
	 * @var string|null
	 */
	protected ?string $runUuid = null;

	/**
	 * The flow this step's run executes, denormalised so per-flow history and
	 * per-flow retention do not have to join through the run table.
	 *
	 * @var string|null
	 */
	protected ?string $flowId = null;

	/**
	 * The node's id within the graph.
	 *
	 * @var string|null
	 */
	protected ?string $nodeId = null;

	/**
	 * The node's catalogue id, exactly as the registry publishes it
	 * (`{app}.{node}`).
	 *
	 * @var string|null
	 */
	protected ?string $nodeType = null;

	/**
	 * Position in the walk; continues across a resume.
	 *
	 * @var integer|null
	 */
	protected ?int $sequence = 0;

	/**
	 * Outcome of this hop.
	 *
	 * @var string|null
	 */
	protected ?string $status = null;

	/**
	 * When the hop started.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $started = null;

	/**
	 * When the hop finished.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $finished = null;

	/**
	 * How long the hop took, in milliseconds.
	 *
	 * @var integer|null
	 */
	protected ?int $durationMs = null;

	/**
	 * What the node produced.
	 *
	 * @var array|null
	 */
	protected ?array $output = null;

	/**
	 * The failure message, when this hop failed.
	 *
	 * @var string|null
	 */
	protected ?string $error = null;

	/**
	 * Row creation timestamp; retention prunes on this.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Constructor: declare field types so the mapper hydrates them correctly.
	 */
	public function __construct() {
		$this->addType(fieldName: 'runUuid', type: 'string');
		$this->addType(fieldName: 'flowId', type: 'string');
		$this->addType(fieldName: 'nodeId', type: 'string');
		$this->addType(fieldName: 'nodeType', type: 'string');
		$this->addType(fieldName: 'sequence', type: 'integer');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'started', type: 'datetime');
		$this->addType(fieldName: 'finished', type: 'datetime');
		$this->addType(fieldName: 'durationMs', type: 'integer');
		$this->addType(fieldName: 'output', type: 'json');
		$this->addType(fieldName: 'error', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');

	}//end __construct()

	/**
	 * Serialise for the API.
	 *
	 * @return array<string, mixed> The step as plain data.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	public function jsonSerialize(): array {
		$started = null;
		if ($this->started !== null) {
			$started = $this->started->format('c');
		}

		$finished = null;
		if ($this->finished !== null) {
			$finished = $this->finished->format('c');
		}

		$created = null;
		if ($this->created !== null) {
			$created = $this->created->format('c');
		}

		return [
			'id' => $this->id,
			'runUuid' => $this->runUuid,
			'flowId' => $this->flowId,
			'nodeId' => $this->nodeId,
			'nodeType' => $this->nodeType,
			'sequence' => (int)$this->sequence,
			'status' => $this->status,
			'started' => $started,
			'finished' => $finished,
			'durationMs' => $this->durationMs,
			'output' => $this->output,
			'error' => $this->error,
			'created' => $created,
		];

	}//end jsonSerialize()
}//end class
