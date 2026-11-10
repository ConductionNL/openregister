<?php

/**
 * Rebuilds the state-history projection a batch at a time.
 *
 * 🔑 RESUMABLE AND BOUNDED, because the trail it reads has millions of rows.
 * Each run takes the next batch of objects after a stored cursor and stops; the
 * next run starts where this one ended. A rebuild that tried to finish in one
 * pass would be a job Nextcloud kills halfway, leaving a table that is neither
 * the old projection nor the new one.
 *
 * 🔑 IT RUNS ONLY WHEN ASKED. The projection is written forward from the
 * transition that produced it, so a rebuild is a repair: an administrator sets
 * `stateHistoryRebuild` and the job works through the instance once, clearing
 * the flag when it reaches the end. Running unasked, it would re-derive
 * millions of rows on every instance nightly for nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\History\StateHistoryRebuildInterface;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\BackgroundJob\Support\StateHistoryRebuildSources;

/**
 * Walks the instance once, rebuilding the projection.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */
class StateHistoryRebuildJob extends TimedJob {

	/**
	 * The switch an administrator sets to ask for a rebuild.
	 *
	 * @var string
	 */
	public const FLAG = 'stateHistoryRebuild';

	/**
	 * Where the last run stopped.
	 *
	 * @var string
	 */
	public const CURSOR = 'stateHistoryRebuildCursor';

	/**
	 * Objects rebuilt per run.
	 *
	 * @var int
	 */
	public const BATCH = 200;

	/**
	 * How often a run may happen, in seconds.
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 300;

	/**
	 * Derives the intervals.
	 *
	 * @var StateHistoryRebuildInterface
	 */
	private readonly StateHistoryRebuildInterface $rebuild;

	/**
	 * Lists the objects to walk.
	 *
	 * @var AuditTrailMapperInterface
	 */
	private readonly AuditTrailMapperInterface $audit;

	/**
	 * Resolves each object's declared lifecycle property.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemas;

	/**
	 * AppConfig, taken from the ConfiguredJobRuntime.
	 *
	 * @var IAppConfig
	 */
	private readonly IAppConfig $appConfig;

	/**
	 * Logger, taken from the ConfiguredJobRuntime.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param ConfiguredJobRuntime $runtime The job runtime: scheduler clock, app settings and run log.
	 * @param StateHistoryRebuildSources $sources The rebuild, the audit trail and the schemas.
	 */
	public function __construct(
		ConfiguredJobRuntime $runtime,
		StateHistoryRebuildSources $sources,
	) {
		$this->logger = $runtime->logger;
		$this->appConfig = $runtime->appConfig;
		$this->rebuild = $sources->rebuild;
		$this->audit = $sources->audit;
		$this->schemas = $sources->schemas;
		parent::__construct(time: $runtime->time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Rebuild the next batch, or do nothing.
	 *
	 * 🔑 IT NEVER THROWS. A background job that raises is one Nextcloud retries
	 * and eventually disables, and a projection that is one batch behind is a
	 * smaller problem than a rebuild that can never run again.
	 *
	 * @param mixed $argument The job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is the
	 * QueuedJob/TimedJob contract; this job sweeps on a clock and takes no
	 * argument.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	protected function run($argument): void {
		try {
			if ($this->appConfig->getValueBool('openregister', self::FLAG, false) === false) {
				return;
			}

			$cursor = $this->appConfig->getValueString('openregister', self::CURSOR, '');
			$uuids = $this->audit->findObjectUuidsAfter(afterUuid: $cursor, limit: self::BATCH);

			if ($uuids === []) {
				// The end. Clearing the flag is what makes this a repair rather
				// than a nightly re-derivation of the whole instance.
				$this->appConfig->setValueBool('openregister', self::FLAG, false);
				$this->appConfig->setValueString('openregister', self::CURSOR, '');
				$this->logger->info('[StateHistoryRebuildJob] Rebuild finished');
				return;
			}

			$written = 0;
			foreach ($uuids as $uuid) {
				$written += $this->rebuildOne(objectUuid: $uuid);
			}

			// The cursor moves even when a batch wrote nothing: most objects
			// have no lifecycle property, and a cursor that only advanced on
			// success would walk the same batch forever.
			$this->appConfig->setValueString('openregister', self::CURSOR, (string)end($uuids));

			$this->logger->info(
				'[StateHistoryRebuildJob] Rebuilt {objects} objects, {written} intervals',
				['objects' => count($uuids), 'written' => $written]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[StateHistoryRebuildJob] Batch failed, the cursor stands: {error}',
				['error' => $e->getMessage(), 'exception' => $e]
			);
		}//end try
	}//end run()

	/**
	 * Rebuild one object, if its schema declares a lifecycle property.
	 *
	 * @param string $objectUuid The object.
	 *
	 * @return int Intervals written.
	 */
	private function rebuildOne(string $objectUuid): int {
		$context = $this->contextFor(objectUuid: $objectUuid);
		if ($context === null) {
			return 0;
		}

		return $this->rebuild->rebuild(
			ref: new ObjectRef(register: $context['register'], schema: $context['schema'], id: $objectUuid),
			property: $context['property']
		);
	}//end rebuildOne()

	/**
	 * The declared lifecycle property of the object's schema, with its slugs.
	 *
	 * Resolved from the SCHEMA, the same rule the live projection follows. A
	 * rebuild reading "whatever changed in the trail" would file intervals
	 * under keys no schema declares as states.
	 *
	 * @param string $objectUuid The object.
	 *
	 * @return array{property: string, register: string, schema: string}|null The context.
	 */
	private function contextFor(string $objectUuid): ?array {
		$row = $this->audit->findForObjectByAction(objectUuid: $objectUuid, limit: 1);
		$entry = ($row[0] ?? null);
		if ($entry === null) {
			return null;
		}

		try {
			$schema = $this->schemas->find((int)$entry->getSchema(), _multitenancy: false, _rbac: false);
		} catch (Throwable) {
			return null;
		}

		$annotation = (($schema->getConfiguration() ?? [])['x-openregister-lifecycle'] ?? null);
		if (is_array($annotation) === false) {
			return null;
		}

		$property = (string)($annotation['field'] ?? ($annotation['property'] ?? ''));
		if ($property === '') {
			return null;
		}

		return [
			'property' => $property,
			'register' => (string)$entry->getRegisterUuid(),
			'schema' => (string)$schema->getSlug(),
		];
	}//end contextFor()
}//end class
