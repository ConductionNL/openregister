<?php

/**
 * OpenRegister ExternalGrantLapseJob
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
 * @spec openspec/specs/authorization-rbac/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Rbac\ExternalGrantGuard;
use OCA\OpenRegister\Service\Rbac\ExternalGrantLapseNotifierInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily: warns the holders of external grants that end within the warning window.
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */
class ExternalGrantLapseJob extends TimedJob {

	/**
	 * Once a day.
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory               $time     Time factory for TimedJob.
	 * @param LoggerInterface            $logger   Diagnostics.
	 * @param SchemaMapperInterface      $schemas  Lists the schemas whose grants are read.
	 * @param ExternalGrantLapseNotifierInterface $notifier Warns the holders.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly LoggerInterface $logger,
		private readonly SchemaMapperInterface $schemas,
		private readonly ExternalGrantLapseNotifierInterface $notifier,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: self::TIME_INSENSITIVE);
	}//end __construct()

	/**
	 * Warn the holder of every external grant about to lapse.
	 *
	 * It never throws: a job that raises is retried and eventually disabled,
	 * and one missed warning is smaller than a sweep that can never run again.
	 *
	 * @param mixed $argument The job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The TimedJob contract; the sweep takes no argument.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	protected function run($argument): void {
		try {
			$warned = 0;
			foreach ($this->schemas->findAll(_rbac: false, _multitenancy: false) as $schema) {
				$warned += $this->warnFor(schema: $schema);
			}

			$this->logger->info('[ExternalGrantLapseJob] Warned {count} holders of lapsing grants', ['count' => $warned]);
		} catch (Throwable $e) {
			$this->logger->warning('[ExternalGrantLapseJob] Sweep failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
		}
	}//end run()

	/**
	 * Warn the holders of the lapsing external grants on one schema.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return int How many people were warned.
	 */
	private function warnFor(Schema $schema): int {
		$warned = 0;
		foreach ((new ExternalGrantGuard())->lapsingWithin(authorization: $schema->getAuthorization()) as $grant) {
			$warned += $this->notifier->warn(schema: $schema, grant: $grant);
		}

		return $warned;
	}//end warnFor()
}//end class
