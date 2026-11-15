<?php

/**
 * QualityAuditFailure — one record that failed a quality audit run, with the rules it failed.
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

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A failing record of a run, so the failures are workable (REQ-RCN-004).
 *
 * @method int|null getRunId()
 * @method void setRunId(?int $runId)
 * @method string|null getRecord()
 * @method void setRecord(?string $record)
 * @method array|null getRules()
 * @method void setRules(?array $rules)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */
class QualityAuditFailure extends Entity implements JsonSerializable {

	/**
	 * The run it failed in.
	 *
	 * @var int|null
	 */
	protected ?int $runId = null;

	/**
	 * The failing record's uuid.
	 *
	 * @var string|null
	 */
	protected ?string $record = null;

	/**
	 * The labels of the rules it failed.
	 *
	 * @var array<int, string>|null
	 */
	protected ?array $rules = null;

	/**
	 * Constructor — registers field types for hydration.
	 */
	public function __construct() {
		$this->addType(fieldName: 'runId', type: 'integer');
		$this->addType(fieldName: 'record', type: 'string');
		$this->addType(fieldName: 'rules', type: 'json');

	}//end __construct()

	/**
	 * JSON serialisation.
	 *
	 * @return array{record: string|null, rules: array<int, string>} The failure.
	 */
	public function jsonSerialize(): array {
		return [
			'record' => $this->record,
			'rules' => ($this->rules ?? []),
		];
	}//end jsonSerialize()
}//end class
