<?php

/**
 * OpenRegister AbstractTextExtractionJob
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Kernel\Contracts\TextExtractionServiceInterface;
use OCP\BackgroundJob\QueuedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * A queued job that extracts the text of one item, named by ARGUMENT_KEY in
 * the job argument, unless extraction of that kind is switched off.
 *
 * Every log line is prefixed with the job's class name and carries the job's
 * own file (SOURCE_FILE) and the item id under ARGUMENT_KEY.
 */
abstract class AbstractTextExtractionJob extends QueuedJob {
	/**
	 * Job argument key holding the id of the item to extract.
	 *
	 * @var string
	 */
	protected const ARGUMENT_KEY = '';

	/**
	 * The concrete job's file, reported in the log context.
	 *
	 * @var string
	 */
	protected const SOURCE_FILE = __FILE__;

	/**
	 * Log line when extraction of this kind is switched off, without prefix.
	 *
	 * @var string
	 */
	protected const DISABLED_MESSAGE = '';

	/**
	 * Config, taken from the ConfiguredJobRuntime.
	 *
	 * @var IAppConfig
	 */
	protected readonly IAppConfig $config;

	/**
	 * Logger, taken from the ConfiguredJobRuntime.
	 *
	 * @var LoggerInterface
	 */
	protected readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param ConfiguredJobRuntime $runtime The job runtime: scheduler clock, app settings and run log.
	 * @param TextExtractionServiceInterface $textExtractor Text extraction service
	 *
	 * @return void
	 */
	public function __construct(
		ConfiguredJobRuntime $runtime,
		protected readonly TextExtractionServiceInterface $textExtractor,
	) {
		$this->logger = $runtime->logger;
		$this->config = $runtime->appConfig;
		parent::__construct(time: $runtime->time);
	}//end __construct()

	/**
	 * Run the job.
	 *
	 * @param mixed $argument Job argument holding ARGUMENT_KEY.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/search-index/spec.md
	 */
	protected function run($argument): void {
		if ($this->isEnabled() === false) {
			$this->logger->info(message: $this->prefix() . static::DISABLED_MESSAGE, context: ['file' => static::SOURCE_FILE, 'line' => __LINE__]);
			return;
		}

		if (isset($argument[static::ARGUMENT_KEY]) === false) {
			$this->logger->error(
				message: $this->prefix() . 'Missing ' . static::ARGUMENT_KEY . ' in job arguments',
				context: ['file' => static::SOURCE_FILE, 'line' => __LINE__, 'argument' => $argument]
			);
			return;
		}

		$this->extractTimed(id: (int)$argument[static::ARGUMENT_KEY]);
	}//end run()

	/**
	 * Extract one item, logging the start, and the outcome with its duration.
	 *
	 * @param int $id The item id.
	 *
	 * @return void
	 */
	private function extractTimed(int $id): void {
		$where = ['file' => static::SOURCE_FILE, 'line' => __LINE__, static::ARGUMENT_KEY => $id];
		$this->logger->info(message: $this->prefix() . 'Starting text extraction', context: [...$where, 'job_id' => $this->getId()]);
		$startTime = microtime(true);
		try {
			$this->extract(id: $id);
			$elapsed = round((microtime(true) - $startTime) * 1000, 2);
			$this->logger->info(message: $this->prefix() . 'Text extraction completed successfully', context: [...$where, 'processing_time_ms' => $elapsed]);
		} catch (\Exception $e) {
			$elapsed = round((microtime(true) - $startTime) * 1000, 2);
			$failure = ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString(), 'processing_time_ms' => $elapsed];
			$this->logger->error(message: $this->prefix() . 'Exception during text extraction', context: [...$where, ...$failure]);
		}
	}//end extractTimed()

	/**
	 * The log prefix: the job's short class name in brackets.
	 *
	 * @return string
	 */
	private function prefix(): string {
		return '[' . substr((string)strrchr(static::class, '\\'), 1) . '] ';
	}//end prefix()

	/**
	 * Whether extraction of this kind is switched on.
	 *
	 * @return bool
	 */
	abstract protected function isEnabled(): bool;

	/**
	 * Extract the text of one item.
	 *
	 * @param int $id The item id.
	 *
	 * @return void
	 */
	abstract protected function extract(int $id): void;
}//end class
