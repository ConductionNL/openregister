<?php

/**
 * OpenRegister AnnotationNotificationDispatchJob
 *
 * Actor-forwarded background job carrying the deferred work of
 * AnnotationNotificationListener: evaluates and fires the schema-declared
 * `x-openregister-notifications` (which can perform synchronous outbound
 * HTTP and mail — ADR-009 Rule 5 material) off the write path. Runs under
 * the forwarded actor so the dispatcher's RBAC-scoped relation-deeplink
 * resolution (`ObjectService::find(_rbac: true)`) evaluates as the user who
 * made the change instead of failing closed as Anonymous.
 *
 * Entry payload: `trigger` plus trigger-specific context — `transition`
 * carries {action, from, to}; `updated` carries the pre-update data snapshot
 * (`oldData`), because the old state is unrecoverable once the job runs. New
 * data is NOT snapshotted: the job re-fetches the current object, so
 * conditions always evaluate old-snapshot → current state. Duplicate
 * delivery on at-least-once re-runs is suppressed by the dispatcher's
 * dispatch-log dedup.
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolverInterface;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRuleDispatcherInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Deferred x-openregister-notifications dispatch under the forwarded actor.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
class AnnotationNotificationDispatchJob extends ActorForwardedJob {
	/**
	 * Wire the dispatcher on top of the actor plumbing.
	 *
	 * @param ITimeFactory                         $time       Time factory for the parent job class.
	 * @param ActorForwardingServices              $actor      Session, user manager, organisation and logger for the parent job class.
	 * @param DeferredEntryObjectResolverInterface $resolver   Stale-safe entry re-fetch.
	 * @param NotificationRuleDispatcherInterface  $dispatcher Notification dispatcher doing the real work.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		ActorForwardingServices $actor,
		private readonly DeferredEntryObjectResolverInterface $resolver,
		private readonly NotificationRuleDispatcherInterface $dispatcher,
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
	 * Replay the inline listener's dispatch calls for every live entry.
	 *
	 * Per-entry failures are logged and do not abort the chunk.
	 *
	 * @param DeferredListenerContext $context The captured dispatch-time context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		foreach ($this->liveEntries(context: $context, resolver: $this->resolver) as $entry => $object) {
			$this->dispatchSafely(entry: $entry, object: $object);
		}
	}//end runDeferred()

	/**
	 * Dispatch one entry, logging a failure without aborting the chunk.
	 *
	 * @param array<string, mixed> $entry  The deferred entry.
	 * @param ObjectEntity         $object The live object.
	 *
	 * @return void
	 */
	private function dispatchSafely(array $entry, ObjectEntity $object): void {
		try {
			$this->dispatchEntry(entry: $entry, object: $object);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[AnnotationNotificationDispatchJob] Dispatch failed for entry',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => ($entry['uuid'] ?? null),
					'trigger' => ($entry['trigger'] ?? null),
					'error' => $e->getMessage(),
				]
			);
		}
	}//end dispatchSafely()

	/**
	 * Mirror the inline AnnotationNotificationListener dispatch semantics.
	 *
	 * - `created`    → dispatch('created').
	 * - `transition` → dispatch('transition', {action, from, to}).
	 * - `updated`    → dispatch('updated', old/new context when a snapshot
	 *   exists) followed by dispatch('calculatedChange', same context) —
	 *   exactly the pair the inline listener fired.
	 *
	 * @param array<string, mixed> $entry The job entry.
	 * @param ObjectEntity $object The re-fetched current object.
	 *
	 * @return void
	 */
	private function dispatchEntry(array $entry, ObjectEntity $object): void {
		$trigger = (string)($entry['trigger'] ?? '');

		if ($trigger === 'created') {
			$this->dispatcher->dispatch(object: $object, trigger: 'created');
			return;
		}

		if ($trigger === 'transition') {
			$this->dispatchTransition(entry: $entry, object: $object);
			return;
		}

		if ($trigger === 'updated') {
			$this->dispatchUpdated(entry: $entry, object: $object);
		}
	}//end dispatchEntry()

	/**
	 * Dispatch a lifecycle transition with the action and the states it moved between.
	 *
	 * @param array<string, mixed> $entry  The deferred entry.
	 * @param ObjectEntity         $object The live object.
	 *
	 * @return void
	 */
	private function dispatchTransition(array $entry, ObjectEntity $object): void {
		$this->dispatcher->dispatch(
			object: $object,
			trigger: 'transition',
			context: [
				'action' => (string)($entry['action'] ?? ''),
				'from' => (string)($entry['from'] ?? ''),
				'to' => (string)($entry['to'] ?? ''),
			]
		);
	}//end dispatchTransition()

	/**
	 * Dispatch an update, and a calculated change when the entry carries the old data.
	 *
	 * @param array<string, mixed> $entry  The deferred entry.
	 * @param ObjectEntity         $object The live object.
	 *
	 * @return void
	 */
	private function dispatchUpdated(array $entry, ObjectEntity $object): void {
		$oldData = ($entry['oldData'] ?? null);
		if (is_array($oldData) === false) {
			$this->dispatcher->dispatch(object: $object, trigger: 'updated', context: []);
			return;
		}

		$this->dispatcher->dispatch(object: $object, trigger: 'updated', context: self::changeOf(object: $object, oldData: $oldData));
		$this->dispatcher->dispatch(object: $object, trigger: 'calculatedChange', context: self::changeOf(object: $object, oldData: $oldData));
	}//end dispatchUpdated()

	/**
	 * The old/new context of an update, the new data read from the object now.
	 *
	 * @param ObjectEntity         $object  The live object.
	 * @param array<string, mixed> $oldData The data before the update.
	 *
	 * @return array{_newData: array, _oldData: array}
	 */
	private static function changeOf(ObjectEntity $object, array $oldData): array {
		return [
			'_newData' => ($object->getObject() ?? []),
			'_oldData' => $oldData,
		];
	}//end changeOf()
}//end class
