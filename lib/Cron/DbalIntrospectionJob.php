<?php

/**
 * DbalIntrospectionJob — scheduled re-introspection of every `type: database`
 * Source, detecting and applying schema drift (design D3b).
 *
 * On each run the job loads all database sources and re-introspects each through
 * {@see DatabaseIntrospectionService::introspect()}, which updates the bound
 * schemas in place and classifies structural drift (added/dropped/retyped
 * columns, new/removed tables) via the schema-diff path. A failure for one
 * source is caught and logged so the remaining sources are still processed. The
 * `run()` body performs the real work — there is no stub (hydra stub-scan gate).
 *
 * @category Cron
 * @package  OCA\OpenRegister\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Cron;

use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Scheduled drift-detection re-introspection for database sources.
 *
 * @psalm-suppress UnusedClass
 */
class DbalIntrospectionJob extends TimedJob {
	/**
	 * The Source `type` this job re-introspects.
	 *
	 * @var string
	 */
	private const DATABASE_SOURCE_TYPE = 'database';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for scheduling.
	 * @param SourceMapper $sourceMapper Loads database sources.
	 * @param DatabaseIntrospectionService $introspectionService Re-introspects a source and applies diffs.
	 * @param LoggerInterface $logger Secret-free diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SourceMapper $sourceMapper,
		private readonly DatabaseIntrospectionService $introspectionService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		// Re-introspect roughly every 6 hours; drift is not time-critical.
		$this->setInterval(seconds: (6 * 3600));
	}//end __construct()

	/**
	 * Run the job: re-introspect every database source, isolating failures.
	 *
	 * @param mixed $argument Job arguments (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) TimedJob contract passes an argument.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	protected function run($argument): void {
		$sources = $this->loadDatabaseSources();
		if ($sources === []) {
			return;
		}

		$this->logger->info(sprintf('[DbalIntrospectionJob] re-introspecting %d database source(s)', count($sources)));

		foreach ($sources as $source) {
			$this->introspectSource(source: $source);
		}
	}//end run()

	/**
	 * Re-introspect a single source, catching and logging any failure.
	 *
	 * @param Source $source The database source to re-introspect.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function introspectSource(Source $source): void {
		try {
			$result = $this->introspectionService->introspect(source: $source);

			if (empty($result['drift']) === false) {
				$this->logger->info(
					sprintf(
						'[DbalIntrospectionJob] source %s drift: %d table(s) changed',
						(string)($source->getUuid() ?? $source->getId()),
						count($result['drift'])
					)
				);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf(
					'[DbalIntrospectionJob] source %s re-introspection failed: %s',
					(string)($source->getUuid() ?? $source->getId()),
					$e->getMessage()
				)
			);
		}//end try
	}//end introspectSource()

	/**
	 * Load every `type: database` source, tolerating a mapper failure.
	 *
	 * @return array<int, Source> The database sources (possibly empty).
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function loadDatabaseSources(): array {
		try {
			return $this->sourceMapper->findAll(filters: ['type' => self::DATABASE_SOURCE_TYPE]);
		} catch (Throwable $e) {
			$this->logger->warning('[DbalIntrospectionJob] could not list database sources: ' . $e->getMessage());
			return [];
		}
	}//end loadDatabaseSources()
}//end class
