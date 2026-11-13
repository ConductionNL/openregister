<?php

/**
 * SchemaAuthorizationAuditListener: a changed authorisation block goes on the audit trail.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Service\Rbac\AuditRowWriter;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Writes one audit entry when a schema's authorisation rules change.
 *
 * The entry names the actor, the schema and the rules before and after, so
 * "who widened this read rule, and when" has an answer. A save that leaves the
 * rules as they were writes nothing. A failing write is logged and never
 * fails the schema save, which has already happened.
 *
 * @template-implements IEventListener<SchemaUpdatedEvent>
 *
 * @spec openspec/changes/capability-rows-verified-c/specs/enhanced-audit-trail/spec.md#requirement-changes-to-authorisation-rules-api-tokens-and-record-types-are-on-the-audit-trail
 */
class SchemaAuthorizationAuditListener implements IEventListener {

	/**
	 * The audit action of a changed authorisation block.
	 *
	 * @var string
	 */
	public const ACTION = 'schema.authorization.updated';

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
	 * Record the change of a schema's authorisation block, if it changed.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-verified-c/specs/enhanced-audit-trail/spec.md#requirement-changes-to-authorisation-rules-api-tokens-and-record-types-are-on-the-audit-trail
	 */
	public function handle(Event $event): void {
		if ($event instanceof SchemaUpdatedEvent === false) {
			return;
		}

		$before = ($event->getOldSchema()->getAuthorization() ?? []);
		$after = ($event->getNewSchema()->getAuthorization() ?? []);
		if ($this->canonical(value: $before) === $this->canonical(value: $after)) {
			return;
		}

		(new AuditRowWriter(mapper: $this->mapper, logger: $this->logger))->write(
			rows: [$this->row(schema: $event->getNewSchema(), before: $before, after: $after)],
			failure: '[SchemaAuthorizationAuditListener] Could not record an authorisation change; the schema itself was saved'
		);
	}//end handle()

	/**
	 * The audit row: the actor, the schema and the rules before and after.
	 *
	 * @param Schema               $schema The schema as saved.
	 * @param array<string, mixed> $before The rules before the save.
	 * @param array<string, mixed> $after  The rules after the save.
	 *
	 * @return AuditTrail The row.
	 */
	private function row(Schema $schema, array $before, array $after): AuditTrail {
		$user = $this->userSession->getUser();

		$row = new AuditTrail();
		$row->setUuid(Uuid::v4()->toRfc4122());
		$row->setAction(self::ACTION);
		$row->setUser($user?->getUID() ?? 'system');
		$row->setUserName($user?->getDisplayName() ?? 'System');
		$row->setSchema($schema->getId());
		$row->setSchemaUuid($schema->getUuid());
		$row->setChanged(
			[
				'schema' => ($schema->getSlug() ?? $schema->getTitle()),
				'authorization' => ['old' => $before, 'new' => $after],
			]
		);
		$row->setCreated(new DateTime());

		return $row;
	}//end row()

	/**
	 * A key-order-independent form of a rules block, so a re-ordered save is not a change.
	 *
	 * @param mixed $value The rules, or part of them.
	 *
	 * @return string The canonical JSON.
	 */
	private function canonical(mixed $value): string {
		return (string)json_encode($this->sorted(value: $value));
	}//end canonical()

	/**
	 * The value with every map's keys sorted; list order is kept, as it can matter.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The sorted value.
	 */
	private function sorted(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$sorted = array_map(fn (mixed $item): mixed => $this->sorted(value: $item), $value);
		if (array_is_list($sorted) === false) {
			ksort($sorted);
		}

		return $sorted;
	}//end sorted()
}//end class
