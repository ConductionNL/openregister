<?php

/**
 * JobRun entity: one background job run, from start to outcome.
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
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One row of the job run log.
 *
 * The row is written by the wrapper around job execution (D-1), never by the
 * job itself, so a job cannot forget to report. It carries the two moments,
 * the duration derived from them, the outcome, and on a failure the message
 * the throwable carried. `cause` says whether the run came from the schedule
 * or from an administrator pressing run now, and `actor` names that
 * administrator, which is what makes the run log answerable after the fact.
 *
 * The same row is what the operations acts are recorded on: a repair and an
 * entry into maintenance mode are runs with a cause of `manual` and an actor,
 * so one record answers "what has been done to this instance, by whom".
 *
 * @method string getJobClass()
 * @method void setJobClass(string $jobClass)
 * @method string|null getArgumentDigest()
 * @method void setArgumentDigest(?string $argumentDigest)
 * @method DateTime|null getStarted()
 * @method void setStarted(?DateTime $started)
 * @method DateTime|null getEnded()
 * @method void setEnded(?DateTime $ended)
 * @method int|null getDurationMs()
 * @method void setDurationMs(?int $durationMs)
 * @method string getOutcome()
 * @method void setOutcome(string $outcome)
 * @method string|null getMessage()
 * @method void setMessage(?string $message)
 * @method string|null getDetails()
 * @method void setDetails(?string $details)
 * @method string getCause()
 * @method void setCause(string $cause)
 * @method string|null getActor()
 * @method void setActor(?string $actor)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
 */
class JobRun extends Entity implements JsonSerializable {

	/**
	 * The run has started and has not reported an end yet.
	 *
	 * @var string
	 */
	public const OUTCOME_RUNNING = 'running';

	/**
	 * The run ended without throwing.
	 *
	 * @var string
	 */
	public const OUTCOME_COMPLETED = 'completed';

	/**
	 * The run ended by throwing, and `message` says what it threw.
	 *
	 * @var string
	 */
	public const OUTCOME_FAILED = 'failed';

	/**
	 * The run came from the cron schedule.
	 *
	 * @var string
	 */
	public const CAUSE_SCHEDULE = 'schedule';

	/**
	 * The run came from an administrator, and `actor` names them.
	 *
	 * @var string
	 */
	public const CAUSE_MANUAL = 'manual';

	/**
	 * The fully qualified class of the job that ran.
	 *
	 * @var string|null
	 */
	protected ?string $jobClass = null;

	/**
	 * A short digest of the job argument, so two queued rows of one class are
	 * distinguishable without storing whatever the argument held.
	 *
	 * @var string|null
	 */
	protected ?string $argumentDigest = null;

	/**
	 * When the run started.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $started = null;

	/**
	 * When the run ended, null while it is still running.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $ended = null;

	/**
	 * How long the run took, in milliseconds, null while it is still running.
	 *
	 * @var integer|null
	 */
	protected ?int $durationMs = null;

	/**
	 * One of the OUTCOME_ constants.
	 *
	 * @var string|null
	 */
	protected ?string $outcome = null;

	/**
	 * The failure message, when the outcome is failed.
	 *
	 * @var string|null
	 */
	protected ?string $message = null;

	/**
	 * What the act concerned, as JSON: the objects a repair touched, the
	 * message maintenance mode holds. Null for an ordinary run.
	 *
	 * @var string|null
	 */
	protected ?string $details = null;

	/**
	 * One of the CAUSE_ constants.
	 *
	 * @var string|null
	 */
	protected ?string $cause = null;

	/**
	 * The uid that caused the run, when a person did.
	 *
	 * @var string|null
	 */
	protected ?string $actor = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'jobClass', type: 'string');
		$this->addType(fieldName: 'argumentDigest', type: 'string');
		$this->addType(fieldName: 'started', type: 'datetime');
		$this->addType(fieldName: 'ended', type: 'datetime');
		$this->addType(fieldName: 'durationMs', type: 'integer');
		$this->addType(fieldName: 'outcome', type: 'string');
		$this->addType(fieldName: 'message', type: 'string');
		$this->addType(fieldName: 'details', type: 'string');
		$this->addType(fieldName: 'cause', type: 'string');
		$this->addType(fieldName: 'actor', type: 'string');

	}//end __construct()

	/**
	 * The short name of the job, for a console row that has no room for a
	 * namespace.
	 *
	 * @return string The class name without its namespace.
	 */
	public function shortName(): string {
		$class = (string)$this->jobClass;
		$cut = strrpos($class, '\\');

		if ($cut === false) {
			return $class;
		}

		return substr($class, ($cut + 1));

	}//end shortName()

	/**
	 * The row as the run log API returns it.
	 *
	 * @return array<string, mixed> The run.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function jsonSerialize(): array {
		$details = null;

		if ($this->details !== null && $this->details !== '') {
			$decoded = json_decode($this->details, true);
			if (is_array($decoded) === true) {
				$details = $decoded;
			}
		}

		return [
			'id' => $this->id,
			'job' => $this->jobClass,
			'name' => $this->shortName(),
			'argumentDigest' => $this->argumentDigest,
			'started' => $this->started?->format(DateTime::ATOM),
			'ended' => $this->ended?->format(DateTime::ATOM),
			'durationMs' => $this->durationMs,
			'outcome' => $this->outcome,
			'message' => $this->message,
			'details' => $details,
			'cause' => $this->cause,
			'actor' => $this->actor,
		];

	}//end jsonSerialize()
}//end class
