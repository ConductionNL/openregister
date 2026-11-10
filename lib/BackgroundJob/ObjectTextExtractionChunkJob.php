<?php

/**
 * OpenRegister ObjectTextExtractionChunkJob
 *
 * Actor-forwarded background job carrying the deferred text extraction of
 * ObjectChangeListener: one job per chunk of saved objects instead of one
 * ObjectTextExtractionJob per object. A flow that writes 700 objects item by
 * item used to leave 1,375 queued jobs that one cron pass worked through
 * before any other job got its turn (openregister#4241); with a chunk size of
 * 100 it leaves at most 7 to 14, and every other cron job gets its turn
 * between two of them.
 *
 * Entries carry the object id only, the key TextExtractionService works with,
 * so a chunk of 100 stays under the 4,000-character job argument limit of
 * Nextcloud 32 and 33. Stale-safe: an object deleted between the save and
 * this job is skipped by the extraction service; the extraction itself is
 * idempotent (an up-to-date object is left alone), so a re-run converges.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\Support\ActorForwardingServices;
use OCA\OpenRegister\Kernel\Contracts\TextExtractionServiceInterface;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * Deferred object text extraction for a chunk of objects, under the forwarded actor.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-object-text-extraction-in-background-mode-is-deferred-in-chunks
 */
class ObjectTextExtractionChunkJob extends ActorForwardedJob {
	/**
	 * Wire the extraction collaborators on top of the actor plumbing.
	 *
	 * @param ITimeFactory                   $time          Time factory for the parent job class.
	 * @param ActorForwardingServices        $actor         The identity plumbing the parent job runs the work as.
	 * @param IAppConfig                     $config        App config holding objectManagement.objectExtractionMode.
	 * @param TextExtractionServiceInterface $textExtractor Extracts the text of one object.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		ActorForwardingServices $actor,
		private readonly IAppConfig $config,
		private readonly TextExtractionServiceInterface $textExtractor,
	) {
		parent::__construct(
			time: $time,
			userSession: $actor->userSession,
			userManager: $actor->userManager,
			organisation: $actor->organisation,
			logger: $actor->logger
		);
	}//end __construct()

	/**
	 * Extract the text of every object in the chunk, one by one.
	 *
	 * Object extraction switched off (`objectExtractionMode` `none`) makes the
	 * whole chunk a no-op, as it does for ObjectTextExtractionJob. A failure
	 * on one object is logged and does not stop the rest of the chunk.
	 *
	 * @param DeferredListenerContext $context The captured dispatch-time context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-object-text-extraction-in-background-mode-is-deferred-in-chunks
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		if ($this->isEnabled() === false) {
			$this->logger->info(
				message: '[ObjectTextExtractionChunkJob] Object extraction is disabled. Not extracting text from objects.',
				context: ['file' => __FILE__, 'line' => __LINE__, 'entries' => count($context->getEntries())]
			);
			return;
		}

		$extracted = 0;
		foreach ($context->getEntries() as $entry) {
			$objectId = (int)($entry['id'] ?? 0);
			if ($objectId <= 0) {
				continue;
			}

			if ($this->extractOne(objectId: $objectId) === true) {
				$extracted++;
			}
		}

		$this->logger->info(
			message: '[ObjectTextExtractionChunkJob] Chunk processed',
			context: [
				'file'      => __FILE__,
				'line'      => __LINE__,
				'entries'   => count($context->getEntries()),
				'extracted' => $extracted,
			]
		);
	}//end runDeferred()

	/**
	 * Extract one object; a failure is logged and reported as false.
	 *
	 * @param int $objectId The object id.
	 *
	 * @return bool Whether the extraction ran without an exception.
	 */
	private function extractOne(int $objectId): bool {
		try {
			$this->textExtractor->extractObject(objectId: $objectId, forceReExtract: false);
			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[ObjectTextExtractionChunkJob] Text extraction failed for one object of the chunk',
				context: ['file' => __FILE__, 'line' => __LINE__, 'object_id' => $objectId, 'error' => $e->getMessage()]
			);
			return false;
		}
	}//end extractOne()

	/**
	 * Whether object text extraction is switched on in the objectManagement setting.
	 *
	 * The same reading as ObjectTextExtractionJob::isEnabled().
	 *
	 * @return bool
	 */
	private function isEnabled(): bool {
		$objectSettings = json_decode($this->config->getValueString(app: 'openregister', key: 'objectManagement', default: '{}'), true);
		if (is_array($objectSettings) === false) {
			return true;
		}

		return ($objectSettings['objectExtractionMode'] ?? 'background') !== 'none';
	}//end isEnabled()
}//end class
