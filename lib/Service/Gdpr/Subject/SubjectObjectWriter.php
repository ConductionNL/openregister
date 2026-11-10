<?php

/**
 * OpenRegister audited write path for the scoped data-subject service.
 *
 * Every fulfilment write of a data-subject request goes through
 * ObjectOperationsInterface::saveObject() with `_rbac` and `_multitenancy`
 * ON, after it has been pinned to the configured DSAR processing activity so
 * the immutable, hash-chained audit trail records it under that activity.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Subject
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

namespace OCA\OpenRegister\Service\Gdpr\Subject;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\DsarService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;

/**
 * Attributed, scoped persistence for data-subject fulfilment writes.
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md#requirement-immutable-audit-of-request-fulfilment
 */
class SubjectObjectWriter {

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objectService Audited write path (saveObject → audit trail).
	 * @param DsarService               $dsarService   Reused only for the configured DSAR activity uuid.
	 * @param IUserSession              $userSession   Current user (erasure / marker metadata).
	 * @param LoggerInterface           $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
		private readonly DsarService $dsarService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Pin the write to the configured DSAR processing activity for the audit.
	 *
	 * @param ObjectEntity $object Object about to be persisted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md#requirement-immutable-audit-of-request-fulfilment
	 */
	public function attribute(ObjectEntity $object): void {
		$activity = $this->dsarService->getDsarProcessingActivityUuid();
		if ($activity !== null) {
			$object->setProcessingActivityId($activity);
		}

	}//end attribute()

	/**
	 * Save an object through the audited path, RBAC + tenant scoped.
	 *
	 * @param ObjectEntity $object Object to persist.
	 *
	 * @return ObjectEntity The saved object.
	 *
	 * @throws \Throwable When the save is refused or fails.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md#requirement-immutable-audit-of-request-fulfilment
	 */
	public function save(ObjectEntity $object): ObjectEntity {
		return $this->objectService->saveObject(
			object: $object,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: $object->getRegister(), schema: $object->getSchema())
			),
			_rbac: true,
			_multitenancy: true
		);

	}//end save()

	/**
	 * Persist an object update and return its serialised envelope, or null.
	 *
	 * @param ObjectEntity      $object Object to persist.
	 * @param SubjectWriteLabel $label  The operation and the identifier a failure is logged under.
	 *
	 * @return array<string, mixed>|null Null when the save failed.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md#requirement-immutable-audit-of-request-fulfilment
	 */
	public function persistAs(ObjectEntity $object, SubjectWriteLabel $label): ?array {
		try {
			return $this->save(object: $object)->jsonSerialize();
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[DSR] ' . $label->op . ' update failed',
				context: ['object' => $label->identifier, 'error' => $e->getMessage()]
			);
			return null;
		}

	}//end persistAs()

	/**
	 * Current acting user id, or `system`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md#requirement-immutable-audit-of-request-fulfilment
	 */
	public function currentUserId(): string {
		return ($this->userSession->getUser()?->getUID() ?? 'system');

	}//end currentUserId()
}//end class
