<?php

/**
 * OpenRegister ConflictTrail
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;


use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use Psr\Log\LoggerInterface;

/**
 * What a version conflict reads from an object's trail and writes to it.
 *
 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-every-write-path-asserts-the-expected-version-req-cso-003
 */
final class ConflictTrail {
	/**
	 * Hold the trail.
	 *
	 * @param AuditTrailMapperInterface $auditTrail The trail.
	 * @param LoggerInterface           $logger     The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-every-write-path-asserts-the-expected-version-req-cso-003
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $auditTrail,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The changes made to this object since the caller read it.
	 *
	 * @param VersionMismatch $mismatch The versions that disagree.
	 *
	 * @return array<int, AuditTrail> The entries.
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-a-refused-write-names-the-values-that-conflict-req-cso-001
	 */
	public function interveningChanges(VersionMismatch $mismatch): array {
		try {
			$read = new DateTime($mismatch->expected);
		} catch (\Throwable $e) {
			return [];
		}

		$entries = $this->auditTrail->findTrail(
			query: new ListQuery(limit: 25, filters: ['object_uuid' => $mismatch->stored->getUuid()], sort: ['created' => 'DESC'])
		);

		return array_values(array_filter($entries, fn ($entry): bool => $this->isAfter(entry: $entry, read: $read)));
	}//end interveningChanges()

	/**
	 * Whether a trail entry was written after the caller read the object.
	 *
	 * @param mixed    $entry The trail entry.
	 * @param DateTime $read  When the caller read it.
	 *
	 * @return bool True for a dated entry after that moment.
	 */
	private function isAfter(mixed $entry, DateTime $read): bool {
		if (($entry instanceof AuditTrail) === false) {
			return false;
		}

		$created = $entry->getCreated();

		return $created !== null && $created->getTimestamp() > $read->getTimestamp();
	}//end isAfter()

	/**
	 * Record the refusal on the object's trail.
	 *
	 * Never throws: a trail that cannot be written must not turn a correct
	 * refusal into a 500. The property NAMES only: the values are in the
	 * response the caller got, filtered for them, while the trail is read by
	 * other people.
	 *
	 * @param VersionMismatch      $mismatch The versions that disagree.
	 * @param array<string, mixed> $body     The conflict body.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-every-write-path-asserts-the-expected-version-req-cso-003
	 */
	public function recordRefusal(VersionMismatch $mismatch, array $body): void {
		try {
			$this->auditTrail->recordObjectEvent(
				event: new ObjectAuditEvent(
					object: $mismatch->stored,
					action: 'refused',
					context: $this->refusalContext(mismatch: $mismatch, body: $body)
				)
			);
		} catch (\Throwable $e) {
			$this->warn(message: 'a refused write could not be recorded: ' . $e->getMessage());
		}
	}//end recordRefusal()

	/**
	 * What the trail keeps of a refusal.
	 *
	 * @param VersionMismatch      $mismatch The versions that disagree.
	 * @param array<string, mixed> $body     The conflict body.
	 *
	 * @return array<string, mixed> The entry's context.
	 */
	private function refusalContext(VersionMismatch $mismatch, array $body): array {
		return [
			'reason' => 'version-conflict',
			'expectedUpdated' => $mismatch->expected,
			'currentUpdated' => $mismatch->current,
			'properties' => array_keys(($body['conflicts'] ?? [])),
		];
	}//end refusalContext()

	/**
	 * Log a degraded refusal.
	 *
	 * @param string $message What could not be done.
	 *
	 * @return void
	 */
	private function warn(string $message): void {
		$this->logger->warning(
			message: '[ObjectsController] ' . $message,
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end warn()
}//end class
