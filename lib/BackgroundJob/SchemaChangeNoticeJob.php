<?php

/**
 * Sends the notice of one acknowledged breaking schema change.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-an-acknowledged-breaking-change-tells-the-people-who-build-on-the-schema
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Schema\SchemaChangeNoticeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Queued after the schema save, never inline in it (hydra ADR-069).
 * Argument: `{changelogId, note}`.
 *
 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-an-acknowledged-breaking-change-tells-the-people-who-build-on-the-schema
 */
class SchemaChangeNoticeJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory              $time    The clock.
	 * @param SchemaChangeNoticeService $notices Sends the notice.
	 * @param LoggerInterface           $logger  The log.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-an-acknowledged-breaking-change-tells-the-people-who-build-on-the-schema
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SchemaChangeNoticeService $notices,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Send the notice of the changelog entry the argument names.
	 *
	 * @param mixed $argument `{changelogId, note}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-an-acknowledged-breaking-change-tells-the-people-who-build-on-the-schema
	 */
	protected function run($argument): void {
		$changelogId = 0;
		$note = null;
		if (is_array($argument) === true) {
			$changelogId = (int)($argument['changelogId'] ?? 0);
			$note = SchemaChangeNoticeService::note(note: ($argument['note'] ?? null));
		}

		if ($changelogId <= 0) {
			$this->logger->warning(message: '[SchemaChangeNoticeJob] No changelogId; nothing sent', context: ['argument' => $argument]);
			return;
		}

		try {
			$told = $this->notices->send(changelogId: $changelogId, note: $note);
		} catch (DoesNotExistException $e) {
			$this->logger->warning(
				message: '[SchemaChangeNoticeJob] The changelog entry or its schema no longer exists; nothing sent',
				context: ['changelogId' => $changelogId]
			);
			return;
		}

		$this->logger->info(message: '[SchemaChangeNoticeJob] Breaking-change notice sent', context: ['changelogId' => $changelogId, 'told' => $told]);
	}//end run()
}//end class
