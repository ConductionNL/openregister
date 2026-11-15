<?php

/**
 * QualityAudit — a named data quality audit: a population, a rule set, a tolerance, a schedule.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A quality audit declares what it measures; its runs are kept apart (REQ-RCN-003).
 *
 * The population is a register, a schema and optional field filters; the
 * rules are the scorer's own rule shape, so one rule cannot mean two things
 * (D-3); the tolerance is what was agreed, and every run keeps the value it
 * was judged against (D-4).
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getRegisterRef()
 * @method void setRegisterRef(?string $registerRef)
 * @method string|null getSchemaRef()
 * @method void setSchemaRef(?string $schemaRef)
 * @method array|null getFilters()
 * @method void setFilters(?array $filters)
 * @method array|null getRules()
 * @method void setRules(?array $rules)
 * @method float|null getTolerance()
 * @method void setTolerance(?float $tolerance)
 * @method string|null getSchedule()
 * @method void setSchedule(?string $schedule)
 * @method DateTime|null getLastRunAt()
 * @method void setLastRunAt(?DateTime $lastRunAt)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */
class QualityAudit extends Entity implements JsonSerializable {

	/**
	 * Run once a day.
	 *
	 * @var string
	 */
	public const SCHEDULE_DAILY = 'daily';

	/**
	 * Run once a week.
	 *
	 * @var string
	 */
	public const SCHEDULE_WEEKLY = 'weekly';

	/**
	 * Run once a month.
	 *
	 * @var string
	 */
	public const SCHEDULE_MONTHLY = 'monthly';

	/**
	 * Every schedule an audit may declare; none means on demand only. The
	 * columns are `register_ref` and `schema_ref` because `schema` is a reserved
	 * word on MySQL.
	 *
	 * @var array<int, string>
	 */
	public const SCHEDULES = [self::SCHEDULE_DAILY, self::SCHEDULE_WEEKLY, self::SCHEDULE_MONTHLY];

	/**
	 * The audit's identity.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The audit's name.
	 *
	 * @var string|null
	 */
	protected ?string $name = null;

	/**
	 * The register of the population: id, uuid or slug.
	 *
	 * @var string|null
	 */
	protected ?string $registerRef = null;

	/**
	 * The schema of the population: id, uuid or slug.
	 *
	 * @var string|null
	 */
	protected ?string $schemaRef = null;

	/**
	 * Field filters narrowing the population.
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $filters = null;

	/**
	 * The rules, in the scorer's shape.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	protected ?array $rules = null;

	/**
	 * The tolerance a run is judged against, 0 to 1.
	 *
	 * @var float|null
	 */
	protected ?float $tolerance = null;

	/**
	 * `daily`, `weekly`, `monthly`, or null for on demand only.
	 *
	 * @var string|null
	 */
	protected ?string $schedule = null;

	/**
	 * When the audit last ran.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $lastRunAt = null;

	/**
	 * Who declared the audit.
	 *
	 * @var string|null
	 */
	protected ?string $createdBy = null;

	/**
	 * Creation timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Last-update timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updated = null;

	/**
	 * Constructor — registers field types for hydration.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'name', type: 'string');
		$this->addType(fieldName: 'registerRef', type: 'string');
		$this->addType(fieldName: 'schemaRef', type: 'string');
		$this->addType(fieldName: 'filters', type: 'json');
		$this->addType(fieldName: 'rules', type: 'json');
		$this->addType(fieldName: 'tolerance', type: 'float');
		$this->addType(fieldName: 'schedule', type: 'string');
		$this->addType(fieldName: 'lastRunAt', type: 'datetime');
		$this->addType(fieldName: 'createdBy', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');

	}//end __construct()

	/**
	 * The audit as the definition the evaluator checks and runs.
	 *
	 * @return array{name: string|null, population: array{register: string|null, schema: string|null, filters: array<string, mixed>},
	 *     rules: array<int, array<string, mixed>>, tolerance: float|null, schedule: string|null}
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-declares-a-population-a-rule-set-and-a-tolerance-req-rcn-003
	 */
	public function definition(): array {
		return [
			'name' => $this->name,
			'population' => [
				'register' => $this->registerRef,
				'schema' => $this->schemaRef,
				'filters' => ($this->filters ?? []),
			],
			'rules' => ($this->rules ?? []),
			'tolerance' => $this->tolerance,
			'schedule' => $this->schedule,
		];
	}//end definition()

	/**
	 * Whether a scheduled audit is due at a moment.
	 *
	 * @param DateTime $now The moment.
	 *
	 * @return bool True when it declares a schedule and has not run within its period.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-declares-a-population-a-rule-set-and-a-tolerance-req-rcn-003
	 */
	public function isDue(DateTime $now): bool {
		$period = match ($this->schedule) {
			self::SCHEDULE_DAILY => '-1 day',
			self::SCHEDULE_WEEKLY => '-7 days',
			self::SCHEDULE_MONTHLY => '-1 month',
			default => null,
		};

		if ($period === null) {
			return false;
		}

		if ($this->lastRunAt === null) {
			return true;
		}

		return $this->lastRunAt <= (clone $now)->modify($period);
	}//end isDue()

	/**
	 * JSON serialisation.
	 *
	 * @return array<string, mixed> The audit.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'name' => $this->name,
			'population' => [
				'register' => $this->registerRef,
				'schema' => $this->schemaRef,
				'filters' => ($this->filters ?? []),
			],
			'rules' => ($this->rules ?? []),
			'tolerance' => $this->tolerance,
			'schedule' => $this->schedule,
			'lastRunAt' => $this->lastRunAt?->format('c'),
			'createdBy' => $this->createdBy,
			'created' => $this->created?->format('c'),
			'updated' => $this->updated?->format('c'),
		];
	}//end jsonSerialize()
}//end class
