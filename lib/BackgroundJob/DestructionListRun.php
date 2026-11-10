<?php

/**
 * One execution of an approved destruction list.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\ExecutedDestruction;
use DateTime;
use Exception;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\DestructionReviewServiceInterface;
use OCA\OpenRegister\Service\Archival\RecordStateInterface;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\DeletionRequest;
use OCA\OpenRegister\Service\Object\RetentionDestructionInterface;
use OCA\OpenRegister\Service\Object\SaveObjectWriterInterface;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\SavePolicy;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Claims an approved list, destroys its entries in batches, and records the outcome.
 *
 * Each entry is re-checked at execution time: a recorded review decision to
 * keep it, or an active legal hold, stops its destruction. The list ends as
 * `executed` with its counters and, when an archival register is configured,
 * a saved destruction certificate.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class DestructionListRun {

	/**
	 * Review answers that forbid destroying the entry carrying them.
	 *
	 * Read from DestructionReviewServiceInterface's own constants rather than copied as
	 * literals: the class that writes the value and the class that refuses to
	 * act on it must not be able to drift apart. The third answer, `destroy`,
	 * is the one this job exists to carry out.
	 *
	 * @var string[]
	 */
	private const WITHHELD_DECISIONS = [
		DestructionReviewServiceInterface::ANSWER_RETAIN,
		DestructionReviewServiceInterface::ANSWER_TRANSFER,
	];

	/**
	 * Legal-hold check and certificate builder.
	 *
	 * @var RetentionServiceInterface
	 */
	private readonly RetentionServiceInterface $retentionService;

	/**
	 * Reads and updates the list and its objects.
	 *
	 * @var MagicMapperInterface
	 */
	private readonly MagicMapperInterface $objectMapper;

	/**
	 * Writes the audit trail entry of each destroyed object.
	 *
	 * @var AuditTrailMapperInterface
	 */
	private readonly AuditTrailMapperInterface $auditMapper;

	/**
	 * Permanently deletes an object.
	 *
	 * @var DeleteObject
	 */
	private readonly DeleteObject $deleteObject;

	/**
	 * Opens the retention-destruction frame, so no lock or archive stops the delete.
	 *
	 * @var RetentionDestructionInterface
	 */
	private readonly RetentionDestructionInterface $retentionDestruction;

	/**
	 * Saves the destruction certificate.
	 *
	 * @var SaveObjectWriterInterface
	 */
	private readonly SaveObjectWriterInterface $saveObject;

	/**
	 * Archival settings.
	 *
	 * @var array<string, mixed>
	 */
	private readonly array $settings;

	/**
	 * Uuid of the list being executed.
	 *
	 * @var string
	 */
	private string $listUuid = '';

	/**
	 * The list data, once claimed.
	 *
	 * @var array<string, mixed>
	 */
	private array $listData = [];

	/**
	 * Outcome counters: destroyed, held, errors and withheld by decision.
	 *
	 * @var array{destroyed: int, held: int, errors: int, withheld: int}
	 */
	private array $count = ['destroyed' => 0, 'held' => 0, 'errors' => 0, 'withheld' => 0];

	/**
	 * Constructor
	 *
	 * @param ContainerInterface $container App container the collaborators are resolved from
	 * @param LoggerInterface    $logger    Logger
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		$this->retentionService     = $container->get(RetentionServiceInterface::class);
		$settingsHandler            = $container->get(ArchivalSettingsReaderInterface::class);
		$this->objectMapper         = $container->get(MagicMapperInterface::class);
		$this->auditMapper          = $container->get(AuditTrailMapperInterface::class);
		$this->deleteObject         = $container->get(DeleteObject::class);
		$this->retentionDestruction = $container->get(RetentionDestructionInterface::class);
		$this->saveObject           = $container->get(SaveObjectWriterInterface::class);
		$this->settings             = $settingsHandler->getArchivalSettingsOnly();
	}//end __construct()

	/**
	 * Execute the list with the given uuid.
	 *
	 * @param string $listUuid Uuid of the destruction list
	 *
	 * @return void
	 *
	 * @throws Exception When the list cannot be read or updated
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function execute(string $listUuid): void {
		$this->listUuid = $listUuid;
		$listObject     = $this->claimList();
		if ($listObject === null) {
			return;
		}

		$batches = array_chunk(($this->listData['objects'] ?? []), $this->batchSize());
		foreach ($batches as $batchIndex => $batch) {
			$this->logger->info('[DestructionExecutionJob] Batch '.($batchIndex + 1).'/'.count($batches));
			foreach ($batch as $objRef) {
				$this->destroyEntry(objRef: $objRef);
			}
		}

		$this->finish(listObject: $listObject);
	}//end execute()

	/**
	 * Load the list and claim it when it is approved.
	 *
	 * Re-entrancy guard: the list moves from approved to executing and is
	 * persisted BEFORE the batch loop. A concurrent or retried run then loads
	 * status `executing`, fails the approved check, and exits, so no object is
	 * deleted twice and no audit row is written twice.
	 *
	 * @return ObjectEntity|null The claimed list, or null when it is not approved
	 */
	private function claimList(): ?ObjectEntity {
		$listObject = $this->objectMapper->find($this->listUuid, null, null, false, false, false);
		$listData   = $listObject->getObject();
		if (($listData['status'] ?? '') !== 'approved') {
			$this->logger->warning('[DestructionExecutionJob] List not approved: '.$this->listUuid);
			return null;
		}

		$listData['status']      = 'executing';
		$listData['executingAt'] = (new DateTime())->format('c');
		$listObject->setObject($listData);
		$this->objectMapper->update($listObject);

		$this->listData = $listData;
		return $listObject;
	}//end claimList()

	/**
	 * Destroy one list entry, unless it has no uuid or a decision withholds it.
	 *
	 * @param array<string, mixed> $objRef List entry
	 *
	 * @return void
	 */
	private function destroyEntry(array $objRef): void {
		$uuid = $objRef['uuid'] ?? null;
		if ($uuid === null) {
			$this->count['errors']++;
			return;
		}

		if ($this->isWithheld(objRef: $objRef) === false) {
			$this->tryDestroy(objRef: $objRef);
		}
	}//end destroyEntry()

	/**
	 * Destroy the object of an entry; a failure is counted and logged.
	 *
	 * @param array<string, mixed> $objRef List entry with a uuid
	 *
	 * @return void
	 */
	private function tryDestroy(array $objRef): void {
		try {
			$this->destroyObject(objRef: $objRef);
		} catch (Exception $e) {
			$this->count['errors']++;
			$this->logger->warning('[DestructionExecutionJob] Failed: '.$objRef['uuid'].': '.$e->getMessage());
		}
	}//end tryDestroy()

	/**
	 * Configured batch size, or the job's default.
	 *
	 * @return integer
	 */
	private function batchSize(): int {
		return (int)($this->settings['destructionBatchSize'] ?? DestructionExecutionJob::DEFAULT_BATCH_SIZE);
	}//end batchSize()

	/**
	 * Whether the reviewer's recorded answer forbids destroying the entry.
	 *
	 * A RECORDED "KEEP THIS" IS NEVER DESTROYED, whatever the list says.
	 * DestructionService::approveList() already takes these entries off the
	 * list at approval; this is the second lock on the same door, because the
	 * door opens onto an irreversible statutory act. A list approved before
	 * that fix shipped, a list written by some other path, or a future caller
	 * that queues this job directly all arrive here with the reviewer's answer
	 * still on the entry.
	 *
	 * @param array<string, mixed> $objRef List entry
	 *
	 * @return bool
	 */
	private function isWithheld(array $objRef): bool {
		$decision = $objRef['decision'] ?? null;
		if (in_array($decision, self::WITHHELD_DECISIONS, true) === false) {
			return false;
		}

		$this->count['withheld']++;
		$message = '[DestructionExecutionJob] Refusing to destroy %s: review decision "%s"';
		$this->logger->warning(sprintf($message, (string)$objRef['uuid'], (string)$decision));
		return true;
	}//end isWithheld()

	/**
	 * Destroy the object of an entry, unless an active legal hold keeps it.
	 *
	 * The hold is re-checked at execution time. The object is marked destroyed
	 * and audited before it is permanently deleted.
	 *
	 * @param array<string, mixed> $objRef List entry with a uuid
	 *
	 * @return void
	 *
	 * @throws Exception When the object cannot be read, audited or deleted
	 */
	private function destroyObject(array $objRef): void {
		$uuid   = $objRef['uuid'];
		$object = $this->objectMapper->find($uuid, null, null, false, false, false);
		if ($this->retentionService->hasActiveLegalHold($object) === true) {
			$this->count['held']++;
			return;
		}

		$retention = $object->getRetention() ?? [];
		$retention['archiefstatus'] = RecordStateInterface::DESTROYED;
		$object->setRetention($retention);
		$this->audit(object: $object, objRef: $objRef);

		$request = new DeletionRequest(register: $object->getRegister(), schema: $object->getSchema(), uuid: (string)$uuid);
		$this->retentionDestruction->run(
			fn (): bool => $this->deleteObject->deleteObject($request, false, false)
		);
		$this->count['destroyed']++;
	}//end destroyObject()

	/**
	 * Write the `archival.destroyed` audit trail entry of an object.
	 *
	 * @param ObjectEntity         $object Object about to be destroyed
	 * @param array<string, mixed> $objRef Its list entry
	 *
	 * @return void
	 */
	private function audit(ObjectEntity $object, array $objRef): void {
		$context = [
			'destructionListUuid' => $this->listUuid,
			'classification' => $objRef['classification'] ?? null,
			'approvedBy' => array_column($this->listData['approvals'] ?? [], 'userId'),
		];
		$this->auditMapper->createAuditTrailEntry($object, 'archival.destroyed', $context);
	}//end audit()

	/**
	 * Mark the list executed with its counters and certificate, and report.
	 *
	 * @param ObjectEntity $listObject The claimed list
	 *
	 * @return void
	 */
	private function finish(ObjectEntity $listObject): void {
		$this->listData['status']           = 'executed';
		$this->listData['executedAt']       = (new DateTime())->format('c');
		$this->listData['destroyedCount']   = $this->count['destroyed'];
		$this->listData['skippedHolds']     = $this->count['held'];
		$this->listData['skippedErrors']    = $this->count['errors'];
		$this->listData['skippedDecisions'] = $this->count['withheld'];

		$this->saveCertificate();
		$listObject->setObject($this->listData);
		$this->objectMapper->update($listObject);

		if ($this->count['held'] > 0) {
			$this->notifySkippedHolds();
		}

		$this->logDone();
	}//end finish()

	/**
	 * Generate the destruction certificate and save it when an archival register is configured.
	 *
	 * @return void
	 */
	private function saveCertificate(): void {
		$data        = $this->listData;
		$certificate = $this->retentionService->certify(new ExecutedDestruction($data, $this->count['destroyed'], $data['executedAt']));
		if (empty($this->settings['archivalRegister']) === true || empty($this->settings['destructionListSchema']) === true) {
			return;
		}

		try {
			$register  = $this->settings['archivalRegister'];
			$schema    = $this->settings['destructionListSchema'];
			$options   = new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema), policy: new SavePolicy(silent: true));
			$savedCert = $this->saveObject->saveObject(data: $certificate, options: $options, _rbac: false, _multitenancy: false);
			$this->listData['certificateUuid'] = $savedCert->getUuid();
		} catch (Exception $e) {
			$this->logger->warning('[DestructionExecutionJob] Certificate save error: '.$e->getMessage());
		}
	}//end saveCertificate()

	/**
	 * Log the outcome counters.
	 *
	 * @return void
	 */
	private function logDone(): void {
		$message = '[DestructionExecutionJob] Done: %d destroyed, %d held, %d withheld by decision, %d errors';
		$count   = $this->count;
		$this->logger->info(sprintf($message, $count['destroyed'], $count['held'], $count['withheld'], $count['errors']));
	}//end logDone()

	/**
	 * Tell the archivist group that legal holds stopped destructions.
	 *
	 * @return void
	 */
	private function notifySkippedHolds(): void {
		try {
			$notificationManager = $this->container->get(INotificationManager::class);
			foreach ($this->archivists() as $user) {
				$notification = $notificationManager->createNotification();
				$notification->setApp('openregister')->setUser($user->getUID())->setDateTime(new DateTime())
					->setObject('destruction_list', $this->listUuid)
					->setSubject('destruction_holds_skipped', ['listUuid' => $this->listUuid, 'skippedCount' => $this->count['held']]);
				$notificationManager->notify($notification);
			}
		} catch (Exception $e) {
			$this->logger->warning('[DestructionExecutionJob] Hold notify error: '.$e->getMessage());
		}
	}//end notifySkippedHolds()

	/**
	 * Members of the archivist group; none when the group does not exist.
	 *
	 * @return IUser[]
	 */
	private function archivists(): array {
		$group = $this->container->get(IGroupManager::class)->get('archivaris');
		if ($group === null) {
			return [];
		}

		return $group->getUsers();
	}//end archivists()
}//end class
