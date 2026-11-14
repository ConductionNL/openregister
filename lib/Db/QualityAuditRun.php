<?php

/**
 * QualityAuditRun — one kept run of a quality audit, judged at the tolerance as it stood.
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
 * A run records its moment, who or what started it, the count evaluated, the
 * score, the tolerance AS IT STOOD, the verdict and how many records failed
 * (REQ-RCN-004). The tolerance is a column of the run, not read from the
 * audit, so raising the bar in June does not re-judge March (D-4). The failing
 * records live in their own table, paged, never on this row.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method int|null getAuditId()
 * @method void setAuditId(?int $auditId)
 * @method DateTime|null getStartedAt()
 * @method void setStartedAt(?DateTime $startedAt)
 * @method string|null getStartedBy()
 * @method void setStartedBy(?string $startedBy)
 * @method int getEvaluated()
 * @method void setEvaluated(int $evaluated)
 * @method float|null getScore()
 * @method void setScore(?float $score)
 * @method float|null getTolerance()
 * @method void setTolerance(?float $tolerance)
 * @method string|null getVerdict()
 * @method void setVerdict(?string $verdict)
 * @method int getFailed()
 * @method void setFailed(int $failed)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */
class QualityAuditRun extends Entity implements JsonSerializable {

	/**
	 * The run's identity.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The audit it is a run of.
	 *
	 * @var int|null
	 */
	protected ?int $auditId = null;

	/**
	 * The moment of the run.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $startedAt = null;

	/**
	 * The user id, or `schedule`, that started it.
	 *
	 * @var string|null
	 */
	protected ?string $startedBy = null;

	/**
	 * The number of records evaluated.
	 *
	 * @var int
	 */
	protected int $evaluated = 0;

	/**
	 * The population score.
	 *
	 * @var float|null
	 */
	protected ?float $score = null;

	/**
	 * The tolerance the run was judged against, as it stood at the run.
	 *
	 * @var float|null
	 */
	protected ?float $tolerance = null;

	/**
	 * `pass` or `fail`.
	 *
	 * @var string|null
	 */
	protected ?string $verdict = null;

	/**
	 * The number of records that failed at least one rule.
	 *
	 * @var int
	 */
	protected int $failed = 0;

	/**
	 * Constructor — registers field types for hydration.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'auditId', type: 'integer');
		$this->addType(fieldName: 'startedAt', type: 'datetime');
		$this->addType(fieldName: 'startedBy', type: 'string');
		$this->addType(fieldName: 'evaluated', type: 'integer');
		$this->addType(fieldName: 'score', type: 'float');
		$this->addType(fieldName: 'tolerance', type: 'float');
		$this->addType(fieldName: 'verdict', type: 'string');
		$this->addType(fieldName: 'failed', type: 'integer');

	}//end __construct()

	/**
	 * JSON serialisation.
	 *
	 * @return array<string, mixed> The run.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'auditId' => $this->auditId,
			'startedAt' => $this->startedAt?->format('c'),
			'startedBy' => $this->startedBy,
			'evaluated' => $this->evaluated,
			'score' => $this->score,
			'tolerance' => $this->tolerance,
			'verdict' => $this->verdict,
			'failed' => $this->failed,
		];
	}//end jsonSerialize()
}//end class
