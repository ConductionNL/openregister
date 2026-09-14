<?php

/**
 * DestructionRecorder — writes the record that survives the object.
 *
 * A destruction record with no object is the point. It goes into the
 * hash-chained audit trail rather than a table of its own, because that table
 * already survives a hard delete of the object row, is append-only, and is
 * where an auditor asking in 2029 who approved a destruction will look.
 *
 * The action is `object.destroyed`, named once in
 * {@see DestructionScope::DESTRUCTION_ACTION} and excluded from every
 * destruction scope from that one definition, so the act can never take its own
 * evidence with it.
 *
 * The record is written BEFORE the object row goes, for the reason every
 * append-only ledger writes its intent first: a crash between the two leaves
 * an over-recorded destruction, which is readable, rather than an unrecorded
 * one, which is not.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Deletion
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

use DateTimeImmutable;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records a destruction so the act outlives its object.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 */
class DestructionRecorder {
	/**
	 * Wire the ledger.
	 *
	 * @param AuditTrailMapper $auditTrailMapper The hash-chained audit trail.
	 * @param IUserSession     $userSession      The acting principal.
	 * @param LoggerInterface  $logger           PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record one destruction: who, when, what went and under which rule.
	 *
	 * @param ObjectEntity         $object  The object about to be destroyed.
	 * @param array<string, mixed> $scope   The scope report from DestructionScopeService.
	 * @param string               $rule    The rule the act ran under, e.g. `destroy-right-granted`.
	 * @param array<string, mixed> $context Anything else worth keeping, such as the window it ran after.
	 *
	 * @return array<string, mixed> The record as it was written.
	 *
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function record(
		ObjectEntity $object,
		array $scope,
		string $rule,
		array $context = [],
	): array {
		$user = $this->userSession->getUser();
		$actor = 'system';
		$actorName = 'System';
		if ($user !== null) {
			$actor = $user->getUID();
			$actorName = $user->getDisplayName();
		}

		$record = array_merge(
			[
				'destroyedBy' => $actor,
				'destroyedByName' => $actorName,
				'destroyedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
				'objectUuid' => (string)$object->getUuid(),
				'register' => $object->getRegister(),
				'schema' => $object->getSchema(),
				'rule' => $rule,
				'scope' => ($scope['scope'] ?? []),
				'destroyed' => ($scope['destroyed'] ?? []),
				'destroyedTotal' => ($scope['total'] ?? 0),
				'failed' => ($scope['failed'] ?? []),
			],
			$context
		);

		try {
			$this->auditTrailMapper->createAuditTrailEntry(
				object: $object,
				action: DestructionScope::DESTRUCTION_ACTION,
				context: $record,
				actorId: $actor,
				actorName: $actorName
			);
		} catch (Throwable $e) {
			// A record that cannot be written is the one failure that must be
			// loud: an unrecorded destruction is the thing this change exists
			// to prevent. The caller refuses on the exception.
			$this->logger->error(
				message: '[DestructionRecorder] Could not write the destruction record',
				context: ['uuid' => $object->getUuid(), 'error' => $e->getMessage()]
			);

			throw new DestructionRefusedException(
				rule: 'destruction-unrecordable',
				reason: 'The destruction record could not be written, so nothing was destroyed.',
				statusCode: 500,
				previous: $e
			);
		}//end try

		return $record;
	}//end record()
}//end class
