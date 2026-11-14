<?php

/**
 * OpenRegister RecordTypeAuditListener
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/capability-rows-verified-c/specs/enhanced-audit-trail/spec.md#requirement-changes-to-authorisation-rules-api-tokens-and-record-types-are-on-the-audit-trail
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\AbstractRegisterEvent;
use OCA\OpenRegister\Event\AbstractSchemaEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Service\Rbac\AuditRowWriter;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Writes one audit entry when a schema or a register is created, changed or deleted.
 *
 * The entry names the actor, the item (id, uuid, slug, title) and, for a
 * change, the fields that changed with their values before and after. The
 * authorisation block is left out of a schema change: its own entry
 * (SchemaAuthorizationAuditListener) records it, so a change of the rules
 * alone writes one entry, not two. A save that changes nothing but its
 * timestamps writes nothing. A failing write is logged and never fails the
 * save, which has already happened.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/capability-rows-verified-c/specs/enhanced-audit-trail/spec.md#requirement-changes-to-authorisation-rules-api-tokens-and-record-types-are-on-the-audit-trail
 */
class RecordTypeAuditListener implements IEventListener {

	/**
	 * The audit action per event class.
	 *
	 * @var array<class-string, string>
	 */
	public const ACTIONS = [
		SchemaCreatedEvent::class => 'schema.created',
		SchemaUpdatedEvent::class => 'schema.updated',
		SchemaDeletedEvent::class => 'schema.deleted',
		RegisterCreatedEvent::class => 'register.created',
		RegisterUpdatedEvent::class => 'register.updated',
		RegisterDeletedEvent::class => 'register.deleted',
	];

	/**
	 * Fields a change never reports: bookkeeping, and the rules that have their own entry.
	 *
	 * @var array<int, string>
	 */
	private const IGNORED = ['id', 'uuid', 'created', 'updated', 'stats', 'authorization'];

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapperInterface $mapper      The audit trail.
	 * @param IUserSession              $userSession The session, for the actor.
	 * @param LoggerInterface           $logger      The logger.
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $mapper,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record the creation, change or deletion of a schema or register.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-verified-c/specs/enhanced-audit-trail/spec.md#requirement-changes-to-authorisation-rules-api-tokens-and-record-types-are-on-the-audit-trail
	 */
	public function handle(Event $event): void {
		$action = self::ACTIONS[get_class($event)] ?? null;
		if ($action === null) {
			return;
		}

		$found = $this->itemAndChanges(event: $event);
		if ($found === null || $found[1] === []) {
			return;
		}

		[$item, $changes] = $found;

		(new AuditRowWriter(mapper: $this->mapper, logger: $this->logger))->write(
			rows: [$this->row(action: $action, item: $item, changes: $changes)],
			failure: '[RecordTypeAuditListener] Could not record a schema or register change; the change itself was saved'
		);
	}//end handle()

	/**
	 * The item the event is about, and what changed: `null` for a creation or
	 * deletion, which names the item only; for a change, its changed fields,
	 * an empty list when nothing did.
	 *
	 * @param Event $event One of the six events.
	 *
	 * @return array{0: Schema|Register, 1: array<string, mixed>|null}|null Null for any other event.
	 */
	private function itemAndChanges(Event $event): ?array {
		if ($event instanceof SchemaUpdatedEvent) {
			$before = $event->getOldSchema()->jsonSerialize();
			return [$event->getNewSchema(), $this->diff(before: $before, after: $event->getNewSchema()->jsonSerialize())];
		}

		if ($event instanceof RegisterUpdatedEvent) {
			$before = $event->getOldRegister()->jsonSerialize();
			return [$event->getNewRegister(), $this->diff(before: $before, after: $event->getNewRegister()->jsonSerialize())];
		}

		if ($event instanceof AbstractSchemaEvent) {
			return [$event->getSchema(), null];
		}

		if ($event instanceof AbstractRegisterEvent) {
			return [$event->getRegister(), null];
		}

		return null;
	}//end itemAndChanges()

	/**
	 * The fields that differ, with their values before and after.
	 *
	 * @param array<string, mixed> $before The item before the save.
	 * @param array<string, mixed> $after  The item after the save.
	 *
	 * @return array<string, array{old: mixed, new: mixed}>
	 */
	private function diff(array $before, array $after): array {
		$changes = [];
		foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
			if (in_array($key, self::IGNORED, true) === true) {
				continue;
			}

			$old = ($before[$key] ?? null);
			$new = ($after[$key] ?? null);
			if ($this->canonical(value: $old) !== $this->canonical(value: $new)) {
				$changes[$key] = ['old' => $old, 'new' => $new];
			}
		}

		return $changes;
	}//end diff()

	/**
	 * The audit row: the actor, the item and what changed.
	 *
	 * @param string                    $action  The audit action.
	 * @param Schema|Register           $item    The schema or register.
	 * @param array<string, mixed>|null $changes The changed fields, or null for a creation or deletion.
	 *
	 * @return AuditTrail The row.
	 */
	private function row(string $action, Schema|Register $item, ?array $changes): AuditTrail {
		$user = $this->userSession->getUser();
		$kind = 'register';
		if ($item instanceof Schema) {
			$kind = 'schema';
		}

		$row = new AuditTrail();
		$row->setUuid(Uuid::v4()->toRfc4122());
		$row->setAction($action);
		$row->setUser($user?->getUID() ?? 'system');
		$row->setUserName($user?->getDisplayName() ?? 'System');
		if ($item instanceof Schema) {
			$row->setSchema($item->getId());
			$row->setSchemaUuid($item->getUuid());
		} else {
			$row->setRegister($item->getId());
			$row->setRegisterUuid($item->getUuid());
		}

		$changed = [$kind => ['id' => $item->getId(), 'uuid' => $item->getUuid(), 'slug' => $item->getSlug(), 'title' => $item->getTitle()]];
		if ($changes !== null) {
			$changed['changes'] = $changes;
		}

		$row->setChanged($changed);
		$row->setCreated(new DateTime());

		return $row;
	}//end row()

	/**
	 * A key-order-independent form of a value, so a re-ordered save is not a change.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The canonical JSON.
	 */
	private function canonical(mixed $value): string {
		return (string)json_encode($this->sorted(value: $value));
	}//end canonical()

	/**
	 * The value with every associative array sorted by key, lists kept in order.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The sorted value.
	 */
	private function sorted(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$sorted = array_map(fn (mixed $entry): mixed => $this->sorted(value: $entry), $value);
		if (array_is_list($sorted) === false) {
			ksort($sorted);
		}

		return $sorted;
	}//end sorted()
}//end class
