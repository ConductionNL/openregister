<?php

/**
 * Fires non-object Nextcloud events into the flow engine.
 *
 * {@see FlowTriggerListener} handles OpenRegister object lifecycle events, whose
 * subject is an object with a register and schema. This handles the other kind
 * of native trigger — a file written, a user created — whose subject is not an
 * OpenRegister object at all. There is nothing to resolve as a subject, so the
 * event's details ride on the run as a `payload` instead, and the worker seeds
 * the run's first item from it ({@see FlowRunWorker}). A flow reads a file's
 * path or a user's id exactly as it would an object's fields.
 *
 * These flows match on the trigger id alone (their register/schema are left
 * empty — a file has neither), so a flow wired to `file.created` runs whenever a
 * file appears, wherever it appears.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-native-triggers/specs/flow-native-triggers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Service\Flow\FlowTriggerServiceInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Node;
use OCP\IUserSession;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\IShare;
use OCP\SystemTag\TagAssignedEvent;
use OCP\SystemTag\TagUnassignedEvent;
use OCP\User\Events\UserCreatedEvent;
use OCP\User\Events\UserDeletedEvent;
use Throwable;

/**
 * Queues flow runs on native file and user events.
 *
 * @template-implements IEventListener<NodeCreatedEvent|NodeWrittenEvent|NodeDeletedEvent|UserCreatedEvent|UserDeletedEvent|ShareCreatedEvent|ShareDeletedEvent|TagAssignedEvent|TagUnassignedEvent>
 */
class NativeFlowTriggerListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param FlowTriggerServiceInterface $triggers Queues the runs.
	 * @param IUserSession $userSession The acting user, for attribution.
	 */
	public function __construct(
		private readonly FlowTriggerServiceInterface $triggers,
		private readonly IUserSession $userSession,
	) {

	}//end __construct()

	/**
	 * Translate a native event into a trigger, with its details as the payload.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-flow-native-triggers/specs/flow-native-triggers/spec.md
	 */
	public function handle(Event $event): void {
		[$eventId, $payload] = $this->describe(event: $event);
		if ($eventId === null) {
			return;
		}

		$this->triggers->fire(
			event: $eventId,
			subject: [],
			user: $this->userSession->getUser()?->getUID(),
			context: ['payload' => $payload]
		);
	}//end handle()

	/**
	 * The trigger id and payload for a native event, or `[null, []]` when the
	 * event is not one this listener fires.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array{0: string|null, 1: array} The trigger id and its payload.
	 */
	private function describe(Event $event): array {
		return $this->describeFile(event: $event)
			?? $this->describeUser(event: $event)
			?? $this->describeShare(event: $event)
			?? $this->describeTag(event: $event)
			?? [null, []];
	}//end describe()

	/**
	 * The trigger id and payload of a file event, or null for another event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array{0: string, 1: array<string, mixed>}|null
	 */
	private function describeFile(Event $event): ?array {
		$id = match (true) {
			$event instanceof NodeCreatedEvent => 'file.created',
			$event instanceof NodeWrittenEvent => 'file.updated',
			$event instanceof NodeDeletedEvent => 'file.deleted',
			default => null,
		};
		if ($id === null) {
			return null;
		}

		return [$id, $this->filePayload(node: $event->getNode())];
	}//end describeFile()

	/**
	 * The trigger id and payload of a user event, or null for another event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array{0: string, 1: array<string, mixed>}|null
	 */
	private function describeUser(Event $event): ?array {
		if ($event instanceof UserCreatedEvent) {
			return ['user.created', ['uid' => $event->getUid()]];
		}

		if ($event instanceof UserDeletedEvent) {
			return ['user.deleted', ['uid' => $event->getUser()->getUID()]];
		}

		return null;
	}//end describeUser()

	/**
	 * The trigger id and payload of a share event, or null for another event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array{0: string, 1: array<string, mixed>}|null
	 */
	private function describeShare(Event $event): ?array {
		if ($event instanceof ShareCreatedEvent) {
			return ['share.created', $this->sharePayload(share: $event->getShare())];
		}

		if ($event instanceof ShareDeletedEvent) {
			return ['share.deleted', $this->sharePayload(share: $event->getShare())];
		}

		return null;
	}//end describeShare()

	/**
	 * The trigger id and payload of a tag event, or null for another event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array{0: string, 1: array<string, mixed>}|null
	 */
	private function describeTag(Event $event): ?array {
		if ($event instanceof TagAssignedEvent) {
			return ['tag.assigned', $this->tagPayload(event: $event)];
		}

		if ($event instanceof TagUnassignedEvent) {
			return ['tag.unassigned', $this->tagPayload(event: $event)];
		}

		return null;
	}//end describeTag()

	/**
	 * The payload describing a share event.
	 *
	 * @param IShare $share The share the event is about.
	 *
	 * @return array<string, mixed> The share payload.
	 */
	private function sharePayload(IShare $share): array {
		return $this->readEach(
			readers: [
				'shareId' => static fn (): string => (string)$share->getId(),
				'nodeId' => static fn (): int => $share->getNodeId(),
				'shareType' => static fn (): int => (int)$share->getShareType(),
				'sharedWith' => static fn (): string => (string)$share->getSharedWith(),
				'path' => static fn (): string => $share->getNode()->getPath(),
			]
		);
	}//end sharePayload()

	/**
	 * The payload describing a tag assign/unassign event.
	 *
	 * @param TagAssignedEvent|TagUnassignedEvent $event The tag event.
	 *
	 * @return array<string, mixed> The tag payload.
	 */
	private function tagPayload(TagAssignedEvent|TagUnassignedEvent $event): array {
		return $this->readEach(
			readers: [
				'objectType' => static fn (): string => $event->getObjectType(),
				'objectIds' => static fn (): array => $event->getObjectIds(),
				'tags' => static fn (): array => array_map(
					static fn ($tag): array => ['id' => $tag->getId(), 'name' => $tag->getName()],
					$event->getTags()
				),
			]
		);
	}//end tagPayload()

	/**
	 * The payload describing a file event.
	 *
	 * Each field is read defensively: a node in mid-delete can throw on some
	 * accessors, and losing the whole trigger over one unreadable field would be
	 * worse than a payload with a gap.
	 *
	 * @param Node $node The file or folder the event is about.
	 *
	 * @return array<string, mixed> The file payload.
	 */
	private function filePayload(Node $node): array {
		return $this->readEach(
			readers: [
				'fileId' => static fn (): int => $node->getId(),
				'path' => static fn (): string => $node->getPath(),
				'name' => static fn (): string => $node->getName(),
				'mimetype' => static fn (): string => $node->getMimetype(),
			]
		);
	}//end filePayload()

	/**
	 * Read each field of a payload, leaving out a field whose reader throws.
	 *
	 * Fields are read defensively: a node in mid-delete can throw on some
	 * accessors, and losing the whole trigger over one unreadable field would be
	 * worse than a payload with a gap.
	 *
	 * @param array<string, callable(): mixed> $readers The field readers, by key.
	 *
	 * @return array<string, mixed> The fields that could be read.
	 */
	private function readEach(array $readers): array {
		$payload = [];
		foreach ($readers as $key => $read) {
			try {
				$payload[$key] = $read();
			} catch (Throwable $e) {
				continue;
			}
		}

		return $payload;
	}//end readEach()
}//end class
