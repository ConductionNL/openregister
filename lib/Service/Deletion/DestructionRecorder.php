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
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records a destruction so the act outlives its object.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
class DestructionRecorder {
	/**
	 * Wire the ledger.
	 *
	 * @param AuditTrailMapperInterface $auditTrailMapper The hash-chained audit trail.
	 * @param IUserSession     $userSession      The acting principal.
	 * @param LoggerInterface  $logger           PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $auditTrailMapper,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record one destruction: who, when, what went and under which rule.
	 *
	 * Facade over {@see self::recordDestruction()} for callers that pass the parts.
	 *
	 * @param ObjectEntity         $object  The object about to be destroyed.
	 * @param array<string, mixed> $scope   The scope report from DestructionScopeService.
	 * @param string               $rule    The rule the act ran under, e.g. `destroy-right-granted`.
	 * @param array<string, mixed> $context Anything else worth keeping, such as the window it ran after.
	 *
	 * @return array<string, mixed> The record as it was written.
	 *
	 * @throws DestructionRefusedException When the record cannot be written.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function record(
		ObjectEntity $object,
		array $scope,
		string $rule,
		array $context=[],
	): array {
		return $this->recordDestruction(command: new RecordDestructionCommand(object: $object, scope: $scope, rule: $rule, context: $context));
	}//end record()

	/**
	 * Record one destruction: who, when, what went and under which rule.
	 *
	 * The context is merged over the statement, so a caller can add to it. A
	 * record that cannot be written refuses the destruction.
	 *
	 * @param RecordDestructionCommand $command The destruction to record.
	 *
	 * @return array<string, mixed> The record as it was written.
	 *
	 * @throws DestructionRefusedException When the record cannot be written.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function recordDestruction(RecordDestructionCommand $command): array {
		$actor  = $this->actor();
		$record = array_merge($this->statement(command: $command, actor: $actor), $command->context);

		try {
			$this->auditTrailMapper->createAuditTrailEntry(
				object: $command->object,
				action: DestructionScope::DESTRUCTION_ACTION,
				context: $record,
				actorId: $actor['id'],
				actorName: $actor['name']
			);
		} catch (Throwable $e) {
			throw $this->unrecordable(object: $command->object, error: $e);
		}

		return $record;
	}//end recordDestruction()

	/**
	 * The session's caller, or the system when there is none.
	 *
	 * @return array{id: string, name: string} The actor's id and display name.
	 */
	private function actor(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return ['id' => 'system', 'name' => 'System'];
		}

		return ['id' => $user->getUID(), 'name' => $user->getDisplayName()];
	}//end actor()

	/**
	 * The statement of the destruction, before the caller's context is merged over it.
	 *
	 * @param RecordDestructionCommand        $command The destruction to record.
	 * @param array{id: string, name: string} $actor   Who destroys.
	 *
	 * @return array<string, mixed> The statement.
	 */
	private function statement(RecordDestructionCommand $command, array $actor): array {
		return [
			'destroyedBy' => $actor['id'],
			'destroyedByName' => $actor['name'],
			'destroyedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			'objectUuid' => (string)$command->object->getUuid(),
			'register' => $command->object->getRegister(),
			'schema' => $command->object->getSchema(),
			'rule' => $command->rule,
			'scope' => ($command->scope['scope'] ?? []),
			'destroyed' => ($command->scope['destroyed'] ?? []),
			'destroyedTotal' => ($command->scope['total'] ?? 0),
			'failed' => ($command->scope['failed'] ?? []),
		];
	}//end statement()

	/**
	 * Log an unwritable record and build the refusal that stops the destruction.
	 *
	 * A record that cannot be written is the one failure that must be loud: an
	 * unrecorded destruction is the thing this change exists to prevent. The
	 * caller refuses on the exception.
	 *
	 * @param ObjectEntity $object The object that was about to be destroyed.
	 * @param Throwable    $error  Why the record could not be written.
	 *
	 * @return DestructionRefusedException The refusal to throw.
	 */
	private function unrecordable(ObjectEntity $object, Throwable $error): DestructionRefusedException {
		$this->logger->error(
			message: '[DestructionRecorder] Could not write the destruction record',
			context: ['uuid' => $object->getUuid(), 'error' => $error->getMessage()]
		);

		return new DestructionRefusedException(
			rule: 'destruction-unrecordable',
			reason: 'The destruction record could not be written, so nothing was destroyed.',
			statusCode: 500,
			previous: $error
		);
	}//end unrecordable()
}//end class
