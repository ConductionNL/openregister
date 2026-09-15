<?php

/**
 * ImportPreviewRunner — walks a previewed import off the request.
 *
 * A municipal migration file is not small, so neither the deciding nor the
 * writing belongs in an HTTP request that a browser will time out of (D-6).
 * This queued job advances one {@see \OCA\OpenRegister\Db\ImportPreview}
 * through whichever phase it was enqueued for, while the preview record's own
 * counters report progress to anyone polling it.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\ImportPreviewMapper;
use OCA\OpenRegister\Service\Import\ImportPreviewService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background executor for a previewed import.
 *
 * @psalm-suppress UnusedClass Enqueued by ImportPreviewController.
 */
class ImportPreviewRunner extends QueuedJob {

	/**
	 * Decide the rows and write nothing.
	 *
	 * @var string
	 */
	public const PHASE_PREVIEW = 'preview';

	/**
	 * Apply the decisions the preview made.
	 *
	 * @var string
	 */
	public const PHASE_COMMIT = 'commit';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param ImportPreviewMapper $previewMapper Preview persistence.
	 * @param ImportPreviewService $service The preview lifecycle.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ImportPreviewMapper $previewMapper,
		private readonly ImportPreviewService $service,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

	}//end __construct()

	/**
	 * Run one phase of a previewed import.
	 *
	 * @param array<string, mixed> $argument Job arguments: preview_id (required),
	 *                                       phase (preview or commit),
	 *                                       source_hash (commit only).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	protected function run($argument): void {
		$previewId = ($argument['preview_id'] ?? null);

		if ($previewId === null) {
			$this->logger->error(message: '[ImportPreviewRunner] Missing preview_id argument');

			return;
		}

		try {
			$preview = $this->previewMapper->find((int)$previewId);
		} catch (DoesNotExistException $exception) {
			$this->logger->warning(
				message: '[ImportPreviewRunner] Preview '.(string)$previewId.' no longer exists'
			);

			return;
		}

		$phase = (string)($argument['phase'] ?? self::PHASE_PREVIEW);

		try {
			if ($phase === self::PHASE_COMMIT) {
				$this->service->commit(
					preview: $preview,
					sourceHash: ($argument['source_hash'] ?? null)
				);

				return;
			}

			$this->service->runPreview(preview: $preview);
		} catch (Throwable $exception) {
			// The service already moved the preview to failed and recorded the
			// reason on the record, which is where the operator reads it. The
			// log line is for the administrator watching cron.
			$this->logger->error(
				message: '[ImportPreviewRunner] Preview '.(string)$previewId.' failed: '.$exception->getMessage(),
				context: ['phase' => $phase]
			);
		}//end try
	}//end run()
}//end class
