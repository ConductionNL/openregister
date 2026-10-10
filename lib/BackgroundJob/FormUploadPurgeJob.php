<?php

/**
 * Hourly purge of expired form upload tokens and idempotency answers.
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
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Form\FormIdempotencyStore;
use OCA\OpenRegister\Service\Form\FormUploadStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes what a submit never claimed, and says how many, a zero included.
 *
 * A zero in the log is a counted zero: it tells a purge that found nothing
 * apart from a purge that never ran. A failure is logged and rethrown, so
 * the job list does not record a run that did not happen.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
 */
class FormUploadPurgeJob extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory         $time    The clock.
	 * @param FormUploadStore      $uploads The upload tokens.
	 * @param FormIdempotencyStore $keys    The remembered submit answers.
	 * @param LoggerInterface      $logger  Where the counts go.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly FormUploadStore $uploads,
		private readonly FormIdempotencyStore $keys,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 3600);
	}//end __construct()

	/**
	 * Purge both stores and log the counts.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @throws Throwable When a purge fails, after logging it.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) TimedJob's signature.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
	 */
	protected function run(mixed $argument): void {
		try {
			$tokens = $this->uploads->purge();
			$keys = $this->keys->purge();
		} catch (Throwable $exception) {
			$this->logger->error(
				message: '[FormUploadPurgeJob] Purge failed',
				context: ['exception' => $exception->getMessage()]
			);
			throw $exception;
		}

		$this->logger->info(
			message: '[FormUploadPurgeJob] purged expired form uploads and submit keys',
			context: ['uploadTokens' => $tokens, 'idempotencyKeys' => $keys]
		);
	}//end run()
}//end class
