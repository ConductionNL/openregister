<?php

/**
 * IdentifierSchemeRun entity — one change of a generated identifier's scheme.
 *
 * While it runs, `activeSequence` holds the sequence name; a unique index
 * on that column is what refuses a second migration of the same sequence.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One scheme migration of one generated identifier property.
 *
 * @method string|null getSequence()
 * @method void setSequence(string $sequence)
 * @method string|null getActiveSequence()
 * @method void setActiveSequence(?string $activeSequence)
 * @method int|null getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(int $schemaId)
 * @method string|null getProperty()
 * @method void setProperty(string $property)
 * @method string|null getPreviousFormat()
 * @method void setPreviousFormat(string $previousFormat)
 * @method string|null getState()
 * @method void setState(string $state)
 * @method int getTotal()
 * @method void setTotal(int $total)
 * @method int getProcessed()
 * @method void setProcessed(int $processed)
 * @method int getRewritten()
 * @method void setRewritten(int $rewritten)
 * @method int getSkipped()
 * @method void setSkipped(int $skipped)
 * @method int getFailed()
 * @method void setFailed(int $failed)
 * @method string|null getStartedBy()
 * @method void setStartedBy(?string $startedBy)
 * @method DateTime|null getStarted()
 * @method void setStarted(?DateTime $started)
 * @method DateTime|null getFinished()
 * @method void setFinished(?DateTime $finished)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
 */
class IdentifierSchemeRun extends Entity implements JsonSerializable {

	/**
	 * The run is rewriting identifiers.
	 */
	public const STATE_RUNNING = 'running';

	/**
	 * Every object was visited.
	 */
	public const STATE_COMPLETED = 'completed';

	/**
	 * The run stopped on an error.
	 */
	public const STATE_FAILED = 'failed';

	/**
	 * The sequence whose identifiers are rewritten.
	 *
	 * @var string|null
	 */
	protected ?string $sequence = null;

	/**
	 * The sequence name while the run is running, null once it is not.
	 *
	 * @var string|null
	 */
	protected ?string $activeSequence = null;

	/**
	 * The register of the objects.
	 *
	 * @var integer|null
	 */
	protected ?int $registerId = null;

	/**
	 * The schema of the objects.
	 *
	 * @var integer|null
	 */
	protected ?int $schemaId = null;

	/**
	 * The generated identifier property.
	 *
	 * @var string|null
	 */
	protected ?string $property = null;

	/**
	 * The format the identifiers were issued in.
	 *
	 * @var string|null
	 */
	protected ?string $previousFormat = null;

	/**
	 * Running, completed or failed.
	 *
	 * @var string|null
	 */
	protected ?string $state = null;

	/**
	 * The objects of the schema when the run started.
	 *
	 * @var integer
	 */
	protected int $total = 0;

	/**
	 * The objects visited so far.
	 *
	 * @var integer
	 */
	protected int $processed = 0;

	/**
	 * The objects whose identifier was rewritten.
	 *
	 * @var integer
	 */
	protected int $rewritten = 0;

	/**
	 * The objects left as they were.
	 *
	 * @var integer
	 */
	protected int $skipped = 0;

	/**
	 * The objects that could not be rewritten.
	 *
	 * @var integer
	 */
	protected int $failed = 0;

	/**
	 * Who started the run.
	 *
	 * @var string|null
	 */
	protected ?string $startedBy = null;

	/**
	 * When the run started.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $started = null;

	/**
	 * When the run stopped.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $finished = null;

	/**
	 * The last failure, for the administrator.
	 *
	 * @var string|null
	 */
	protected ?string $lastError = null;

	/**
	 * Declare the column types.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
	 */
	public function __construct() {
		foreach (['sequence', 'activeSequence', 'property', 'previousFormat', 'state', 'startedBy', 'lastError'] as $field) {
			$this->addType(fieldName: $field, type: 'string');
		}

		foreach (['registerId', 'schemaId', 'total', 'processed', 'rewritten', 'skipped', 'failed'] as $field) {
			$this->addType(fieldName: $field, type: 'integer');
		}

		$this->addType(fieldName: 'started', type: 'datetime');
		$this->addType(fieldName: 'finished', type: 'datetime');
	}//end __construct()

	/**
	 * The run as JSON: what it rewrites and how far it got.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
	 */
	public function jsonSerialize(): array {
		return [
			'id'             => $this->id,
			'sequence'       => $this->sequence,
			'registerId'     => $this->registerId,
			'schemaId'       => $this->schemaId,
			'property'       => $this->property,
			'previousFormat' => $this->previousFormat,
			'state'          => $this->state,
			'total'          => $this->total,
			'processed'      => $this->processed,
			'rewritten'      => $this->rewritten,
			'skipped'        => $this->skipped,
			'failed'         => $this->failed,
			'startedBy'      => $this->startedBy,
			'started'        => $this->started?->format(DATE_ATOM),
			'finished'       => $this->finished?->format(DATE_ATOM),
			'lastError'      => $this->lastError,
		];
	}//end jsonSerialize()
}//end class
