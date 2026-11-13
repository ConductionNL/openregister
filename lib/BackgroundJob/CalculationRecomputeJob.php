<?php

/**
 * A recompute above the synchronous limit, run in the background and listed on the operations console.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Calculation\CalculationRematerialiser;
use OCA\OpenRegister\Service\Calculation\RecomputeAccess;
use OCA\OpenRegister\Service\Calculation\RecomputeSelection;
use OCA\OpenRegister\Service\Operations\JobRunRecorderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Argument: register and schema ids, `ids` or `filters`, `dryRun`, and the `actor` whose update rights apply.
 *
 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
 */
class CalculationRecomputeJob extends RecordedQueuedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory              $time           Time factory.
	 * @param JobRunRecorderInterface   $recorder       Writes the run row.
	 * @param CalculationRematerialiser $rematerialiser Re-derives the objects.
	 * @param RecomputeAccess           $access         The actor's update check.
	 * @param RegisterMapperInterface            $registers      Finds the register.
	 * @param SchemaMapperInterface              $schemas        Finds the schema.
	 * @param LoggerInterface           $logger         Logs the counts.
	 */
	public function __construct(
		ITimeFactory $time,
		JobRunRecorderInterface $recorder,
		private readonly CalculationRematerialiser $rematerialiser,
		private readonly RecomputeAccess $access,
		private readonly RegisterMapperInterface $registers,
		private readonly SchemaMapperInterface $schemas,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time, recorder: $recorder);
	}//end __construct()

	/**
	 * Re-derive the selection with the actor's update rights.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
	 */
	protected function runRecorded(mixed $argument): void {
		$argument = (array)$argument;
		$schema   = $this->schemas->find(id: (int)($argument['schema'] ?? 0), _rbac: false, _multitenancy: false);
		$register = $this->registers->find(id: (int)($argument['register'] ?? 0), _rbac: false, _multitenancy: false);

		$selection = RecomputeSelection::byFilters(filters: (array)($argument['filters'] ?? []));
		if (is_array($argument['ids'] ?? null) === true) {
			$selection = RecomputeSelection::byIds(ids: $argument['ids']);
		}

		$result = $this->rematerialiser->run(
			table: new RegisterSchema(register: $register, schema: $schema),
			selection: $selection,
			dryRun: (bool)($argument['dryRun'] ?? false),
			mayUpdate: $this->access->forUser(schema: $schema, userId: (string)($argument['actor'] ?? ''))
		);

		$this->logger->info(
			message: '[CalculationRecomputeJob] Recompute finished',
			context: ['register' => $register->getId(), 'schema' => $schema->getId(), 'result' => $result->jsonSerialize()]
		);
	}//end runRecorded()
}//end class
