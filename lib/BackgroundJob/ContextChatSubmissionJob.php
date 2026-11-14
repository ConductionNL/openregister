<?php

/**
 * OpenRegister ContextChatSubmissionJob
 *
 * Actor-forwarded background job carrying the deferred work of
 * `ContextChatSubmissionListener`: submit an object's content to Context
 * Chat, or remove it, after the object write has returned.
 *
 * The entry carries the object's uuid and schema id, not the operation. The
 * job reads the object as it is now and acts on that state:
 *
 * - the object resolves: submit it when it is eligible (the listener removes
 *   it instead when it is no longer publicly readable);
 * - the object is gone or soft-deleted: remove its content.
 *
 * So a delete whose uuid was re-created before the job ran does not remove
 * the new object's content (the same-uuid re-create race), and at-least-once
 * delivery or a deduped create-then-update converge on the same result.
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
use OCA\OpenRegister\ContextChat\Listener\ContextChatSubmissionListenerInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolverInterface;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

/**
 * Deferred Context Chat submit or remove under the forwarded actor.
 *
 * @spec openspec/changes/object-event-sync-async-split/specs/event-driven-architecture/spec.md#requirement-deferred-entries-must-carry-every-value-that-cannot-be-re-fetched-at-job-time
 */
class ContextChatSubmissionJob extends ActorForwardedJob {
	/**
	 * Wire the collaborators on top of the actor plumbing.
	 *
	 * @param ITimeFactory                           $time       Time factory for the parent job class.
	 * @param ActorForwardingServices                $actor      The identity plumbing the parent job runs the work as.
	 * @param DeferredEntryObjectResolverInterface   $resolver   Re-reads the entry's object; null when gone or soft-deleted.
	 * @param ContextChatSubmissionListenerInterface $submission The submit and remove logic the listener runs inline.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		ActorForwardingServices $actor,
		private readonly DeferredEntryObjectResolverInterface $resolver,
		private readonly ContextChatSubmissionListenerInterface $submission,
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
	 * Submit or remove each entry's object, by its state now.
	 *
	 * One entry's failure is logged and does not abort the chunk.
	 *
	 * @param DeferredListenerContext $context The captured dispatch-time context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-event-sync-async-split/specs/event-driven-architecture/spec.md#requirement-deferred-entries-must-carry-every-value-that-cannot-be-re-fetched-at-job-time
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		foreach ($context->getEntries() as $entry) {
			$uuid = (string)($entry['uuid'] ?? '');
			if ($uuid === '') {
				continue;
			}

			try {
				$this->handleEntry(entry: $entry, uuid: $uuid);
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[ContextChatSubmissionJob] Context Chat update failed for entry',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'uuid' => $uuid,
						'error' => $e->getMessage(),
					]
				);
			}
		}//end foreach
	}//end runDeferred()

	/**
	 * Submit the live object, or remove the content of a gone one.
	 *
	 * @param array<string, mixed> $entry The deferred entry.
	 * @param string               $uuid  The entry's object uuid.
	 *
	 * @return void
	 */
	private function handleEntry(array $entry, string $uuid): void {
		$object = $this->resolver->resolve(entry: ['uuid' => $uuid]);
		if ($object !== null) {
			$this->submission->submitIfEligible(object: $object);
			return;
		}

		// Gone: the uuid and the schema it was on are all the removal needs.
		$gone = new ObjectEntity();
		$gone->setUuid($uuid);
		$gone->setSchema((string)($entry['schemaId'] ?? ''));
		$this->submission->remove(object: $gone);
	}//end handleEntry()
}//end class
