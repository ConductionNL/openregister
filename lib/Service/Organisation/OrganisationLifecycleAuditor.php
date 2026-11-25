<?php

/**
 * Records an organisation's lifecycle on the audit trail.
 *
 * An organisation is not a register object, so suspending, reactivating, ending
 * or renaming one left no entry on the hash-chained audit trail: the status and
 * its timestamp were the only trace, and they say what is, not who did it. Each
 * change now writes one entry keyed by the organisation's uuid (`object_uuid`),
 * so the admin audit list can be asked for one organisation's history.
 *
 * FAIL-SOFT, like the settings and grant auditors: the change has been stored
 * when this runs, and failing here would report a failed change that in fact
 * happened. A missing entry is logged at ERROR instead.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Organisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Organisation;

use DateTimeImmutable;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntry;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntryWriterInterface;
use OCA\OpenRegister\Db\Organisation;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Turns a change of an organisation into an audit-trail entry.
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */
class OrganisationLifecycleAuditor implements OrganisationEditAuditorInterface {

	/**
	 * The typed action a change of an organisation's status writes.
	 *
	 * @var string
	 */
	public const ACTION_STATUS = 'organisation.status_changed';

	/**
	 * The typed action an edit of an organisation's details writes.
	 *
	 * @var string
	 */
	public const ACTION_UPDATED = 'organisation.updated';

	/**
	 * The typed action a configuration promotion writes, in the source and in the target.
	 *
	 * @var string
	 */
	public const ACTION_PROMOTED = 'organisation.configuration_promoted';

	/**
	 * Constructor.
	 *
	 * @param AuditEntryWriterInterface $auditEntries The audit trail, which seals every entry it writes.
	 * @param IUserSession              $userSession  Who made the change.
	 * @param LoggerInterface           $logger       The logger.
	 */
	public function __construct(
		private readonly AuditEntryWriterInterface $auditEntries,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record a change of an organisation's status.
	 *
	 * @param Organisation $organisation The organisation, after the change.
	 * @param string       $before       The status before.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function recordStatus(Organisation $organisation, string $before): void {
		$after = (string) ($organisation->getStatus() ?? '');
		if ($after === $before) {
			return;
		}

		$this->write(
			organisation: $organisation,
			change: new OrganisationChange(action: self::ACTION_STATUS, changed: ['status' => ['old' => $before, 'new' => $after]])
		);
	}//end recordStatus()

	/**
	 * Record an edit of an organisation's details.
	 *
	 * @param Organisation     $organisation The organisation, after the edit.
	 * @param OrganisationEdit $edit         The edited fields as they were and as they are now, by name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function recordUpdate(Organisation $organisation, OrganisationEdit $edit): void {
		$changed = self::changedFields(before: $edit->before, after: $edit->after);
		if ($changed !== []) {
			$this->write(organisation: $organisation, change: new OrganisationChange(action: self::ACTION_UPDATED, changed: $changed));
		}
	}//end recordUpdate()

	/**
	 * Record a configuration promotion, once in the source and once in the target organisation.
	 *
	 * Both entries name the draft set the configuration landed in, the other
	 * organisation and the number of components per kind, so either
	 * organisation's history answers where its configuration went or came from.
	 *
	 * @param Organisation       $source  The organisation whose configuration was promoted.
	 * @param Organisation       $target  The organisation it landed in as a draft set.
	 * @param string             $setUuid The draft set.
	 * @param array<string, int> $counts  Components per kind.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md
	 */
	public function recordPromotion(Organisation $source, Organisation $target, string $setUuid, array $counts): void {
		$promotion = ['draftSet' => $setUuid, 'counts' => $counts];

		$this->write(
			organisation: $source,
			change: new OrganisationChange(action: self::ACTION_PROMOTED, changed: ['promotedTo' => $target->getUuid()] + $promotion)
		);
		$this->write(
			organisation: $target,
			change: new OrganisationChange(action: self::ACTION_PROMOTED, changed: ['promotedFrom' => $source->getUuid()] + $promotion)
		);
	}//end recordPromotion()

	/**
	 * The fields whose value differs, by name: `{old, new}`.
	 *
	 * @param array<string, mixed> $before The edited fields as they were, by name.
	 * @param array<string, mixed> $after  The same fields as they are now.
	 *
	 * @return array<string, array{old: mixed, new: mixed}> What changed.
	 */
	private static function changedFields(array $before, array $after): array {
		$changed = [];
		foreach ($after as $field => $value) {
			$old = ($before[$field] ?? null);
			if ($old !== $value) {
				$changed[$field] = ['old' => $old, 'new' => $value];
			}
		}

		return $changed;
	}//end changedFields()

	/**
	 * Write one sealed entry; never throws.
	 *
	 * @param Organisation       $organisation The organisation.
	 * @param OrganisationChange $change       The typed action and what changed.
	 *
	 * @return void
	 */
	private function write(Organisation $organisation, OrganisationChange $change): void {
		$entry = $this->entryFor(organisation: $organisation, change: $change);

		try {
			$this->auditEntries->writeAuditEntries(entries: [$entry]);
		} catch (Throwable $e) {
			$this->reportUnwritten(entry: $entry, error: $e);
		}
	}//end write()

	/**
	 * The audit entry for one change, attributed to the session user or the system.
	 *
	 * @param Organisation       $organisation The organisation.
	 * @param OrganisationChange $change       The typed action and what changed.
	 *
	 * @return AuditEntry The entry to write.
	 */
	private function entryFor(Organisation $organisation, OrganisationChange $change): AuditEntry {
		$actor = $this->actor();

		return new AuditEntry(
			uuid: Uuid::v4()->toRfc4122(),
			action: $change->action,
			user: $actor['id'],
			userName: $actor['name'],
			changed: $change->changed,
			created: new DateTimeImmutable(),
			objectUuid: $organisation->getUuid(),
		);
	}//end entryFor()

	/**
	 * Who made the change: the session user, or the system when there is none.
	 *
	 * @return array{id: string, name: string} The actor's uid and display name.
	 */
	private function actor(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return ['id' => 'system', 'name' => 'System'];
		}

		return ['id' => $user->getUID(), 'name' => $user->getDisplayName()];
	}//end actor()

	/**
	 * Log an entry that could not be written; the change itself was stored.
	 *
	 * @param AuditEntry $entry The entry that was not written.
	 * @param Throwable  $error Why.
	 *
	 * @return void
	 */
	private function reportUnwritten(AuditEntry $entry, Throwable $error): void {
		$this->logger->error(
			message: '[OrganisationLifecycleAuditor] Could not record a change of an organisation; the change itself was stored',
			context: [
				'file'      => __FILE__,
				'line'      => __LINE__,
				'action'    => $entry->action,
				'uuid'      => $entry->objectUuid,
				'exception' => $error->getMessage(),
			]
		);
	}//end reportUnwritten()
}//end class
