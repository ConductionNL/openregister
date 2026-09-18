<?php

/**
 * TimelineVisibilityService — decides which side of the counter a timeline
 * entry belongs on.
 *
 * Every entry on an object's timeline (a note, an audit row, an NC Activity
 * row, a file event, a mail row) carries a `visibility` of `internal` or
 * `public`. A missing flag reads as `internal`, so a note written before the
 * flag existed can never surface on a citizen's screen.
 *
 * Three things live here, because notes and the merged feed both need them:
 * normalising the value, deciding whether the caller may set it (`update` on
 * the object), and writing the audit entry when it changes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Visibility decisions for timeline entries.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 */
class TimelineVisibilityService {

	/**
	 * The default: an entry nobody flagged stays behind the counter.
	 *
	 * @var string
	 */
	public const INTERNAL = 'internal';

	/**
	 * The flag that lets an entry reach a citizen.
	 *
	 * @var string
	 */
	public const PUBLIC_ENTRY = 'public';

	/**
	 * The audit action written when a note's flag changes.
	 *
	 * @var string
	 */
	public const AUDIT_ACTION = 'note.visibility.changed';

	/**
	 * The leaves whose rows are timeline entries.
	 *
	 * @var array<int,string>
	 */
	public const TIMELINE_INTEGRATIONS = ['notes', 'activity'];

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper     $schemaMapper     Resolves the object's schema for the permission check.
	 * @param PermissionHandler $permissionHandler Canonical RBAC verdict.
	 * @param AuditTrailMapper $auditTrailMapper Writes the audit entry on a change.
	 * @param IUserSession     $userSession      Current caller.
	 * @param LoggerInterface  $logger           Logger for the fail-closed paths.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly PermissionHandler $permissionHandler,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Normalise any incoming value to one of the two flags.
	 *
	 * Anything that is not exactly `public` reads as `internal`: the default is
	 * applied at read time as well as at write time, so an entry stored before
	 * the flag existed, or with a value from a future vocabulary, stays behind
	 * the counter.
	 *
	 * @param string|null $value The raw value, from a payload or from storage.
	 *
	 * @return string Either `internal` or `public`.
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
	 */
	public function normalise(?string $value): string {
		if (is_string($value) === false) {
			return self::INTERNAL;
		}

		if (strtolower(trim($value)) === self::PUBLIC_ENTRY) {
			return self::PUBLIC_ENTRY;
		}

		return self::INTERNAL;
	}//end normalise()

	/**
	 * Whether a raw value names a valid flag at all.
	 *
	 * A payload carrying `visibility: "intern"` is a caller mistake, not a
	 * silent internal: the controller answers 400 rather than storing a value
	 * the caller did not mean.
	 *
	 * @param string|null $value The raw value from a payload.
	 *
	 * @return boolean True when the value is `internal` or `public`.
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
	 */
	public function isKnownValue(?string $value): bool {
		if (is_string($value) === false) {
			return false;
		}

		return in_array(strtolower(trim($value)), [self::INTERNAL, self::PUBLIC_ENTRY], true);
	}//end isKnownValue()

	/**
	 * Whether the caller may set or change the flag on this object's entries.
	 *
	 * The right to publish an entry is the right to update the object: a
	 * reader never holds it, and the portal's subject-scoped reader never
	 * holds it either. Fails CLOSED — a schema that cannot be resolved is a
	 * refusal, never an allow.
	 *
	 * @param ObjectEntity|null $object The object the entry hangs on.
	 *
	 * @return boolean True when the caller holds `update` on the object.
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
	 */
	public function mayManage(?ObjectEntity $object): bool {
		return $this->holds(object: $object, action: 'update');
	}//end mayManage()

	/**
	 * Whether the caller manages this object.
	 *
	 * A second verdict beside {@see mayManage()} because rewriting what a note
	 * SAYS is not the same right as deciding who may read it. A note is a
	 * signed statement by its author: a colleague with `update` moves it
	 * across the counter but never rewrites it, while somebody who manages the
	 * object may. Fails CLOSED for the same reason.
	 *
	 * @param ObjectEntity|null $object The object the note hangs on.
	 *
	 * @return boolean True when the caller holds `manage` on the object.
	 *
	 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
	 */
	public function mayManageObject(?ObjectEntity $object): bool {
		return $this->holds(object: $object, action: 'manage');
	}//end mayManageObject()

	/**
	 * Ask the canonical RBAC handler for one action on one object.
	 *
	 * @param ObjectEntity|null $object The object being acted on.
	 * @param string            $action The permission asked for.
	 *
	 * @return boolean True when the caller holds it.
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
	 */
	private function holds(?ObjectEntity $object, string $action): bool {
		if ($object === null) {
			return false;
		}

		try {
			$schema = $this->schemaMapper->find(
				id: (string)$object->getSchema(),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[TimelineVisibilityService] Schema for object ' . (string)$object->getUuid()
					. ' could not be resolved; refusing ' . $action,
				['exception' => $e]
			);
			return false;
		}

		$userId = null;
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$userId = $user->getUID();
		}

		return $this->permissionHandler->hasPermission(
			schema: $schema,
			action: $action,
			userId: $userId,
			objectOwner: $object->getOwner(),
			_rbac: true,
			object: $object
		);
	}//end holds()

	/**
	 * The filter a read actually gets, whatever it asked for.
	 *
	 * A caller without `update` on the object is served the public view even
	 * when the query parameter is absent or says `internal`, so the filter
	 * cannot be bypassed by omitting it. A caller with `update` gets the
	 * filter it asked for, and no filter when it asked for none.
	 *
	 * @param ObjectEntity|null $object    The object whose timeline is read.
	 * @param string|null       $requested The `visibility` query parameter, when given.
	 *
	 * @return string|null `public`, `internal`, or null for the unfiltered view.
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/integration-activity/spec.md
	 */
	public function effectiveFilter(?ObjectEntity $object, ?string $requested): ?string {
		if ($this->mayManage(object: $object) === false) {
			return self::PUBLIC_ENTRY;
		}

		if ($this->isKnownValue(value: $requested) === false) {
			return null;
		}

		return $this->normalise(value: $requested);
	}//end effectiveFilter()

	/**
	 * Keep only the rows a filter admits.
	 *
	 * Rows are read through {@see normalise()}, so a row that never declared a
	 * flag counts as internal here too.
	 *
	 * @param array<int,array<string,mixed>> $rows   Timeline rows.
	 * @param string|null                    $filter The effective filter, or null for everything.
	 *
	 * @return array<int,mixed> The admitted rows, re-indexed.
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/integration-activity/spec.md
	 */
	public function filterRows(array $rows, ?string $filter): array {
		if ($filter === null) {
			return $rows;
		}

		$wanted = $this->normalise(value: $filter);

		return array_values(
			array_filter(
				$rows,
				function (mixed $row) use ($wanted): bool {
					$value = null;
					if (is_array($row) === true && isset($row['visibility']) === true
						&& is_string($row['visibility']) === true
					) {
						$value = $row['visibility'];
					}

					return $this->normalise(value: $value) === $wanted;
				}
			)
		);
	}//end filterRows()

	/**
	 * Whether a leaf renders timeline entries and is therefore filtered.
	 *
	 * The two leaves that draw the timeline are notes and activity. Every other
	 * leaf answers exactly as it did: a file list or a deck board is not a
	 * timeline entry, and treating one as internal would empty a reader's
	 * screen for no gain.
	 *
	 * @param string $integrationId The leaf id being listed.
	 *
	 * @return boolean True when the leaf's rows carry a timeline visibility.
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/integration-activity/spec.md
	 */
	public function isTimelineIntegration(string $integrationId): bool {
		return in_array(strtolower(trim($integrationId)), self::TIMELINE_INTEGRATIONS, true);
	}//end isTimelineIntegration()

	/**
	 * Record that someone moved a note across the counter.
	 *
	 * Making an internal note public is the act a supervisor will ask about,
	 * so it is written on the object's own audit trail naming the note and
	 * both values. Nothing is written when the value did not move.
	 *
	 * @param ObjectEntity $object The object the note hangs on.
	 * @param integer      $noteId The note that moved.
	 * @param string       $from   The value before.
	 * @param string       $to     The value after.
	 *
	 * @return boolean True when an audit entry was written.
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
	 */
	public function auditVisibilityChange(ObjectEntity $object, int $noteId, string $from, string $to): bool {
		$before = $this->normalise(value: $from);
		$after = $this->normalise(value: $to);
		if ($before === $after) {
			return false;
		}

		try {
			$this->auditTrailMapper->createAuditTrailEntry(
				object: $object,
				action: self::AUDIT_ACTION,
				context: [
					'noteId' => $noteId,
					'from' => $before,
					'to' => $after,
				]
			);
		} catch (Throwable $e) {
			// The flag has already been written; losing the trail entry must
			// not lose the change itself. It is logged loudly instead.
			$this->logger->error(
				'[TimelineVisibilityService] Visibility of note ' . $noteId . ' moved from ' . $before
					. ' to ' . $after . ' but the audit entry could not be written',
				['exception' => $e]
			);
			return false;
		}//end try

		return true;
	}//end auditVisibilityChange()
}//end class
