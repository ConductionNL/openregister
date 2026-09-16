<?php

/**
 * SubjectExportService — the data subject takes their own copy.
 *
 * Article 20 portability and article 15 access are both answered within a term,
 * and the answer today is a manual database export. This is the door: a
 * subject, or a handler acting for them, asks once and gets a machine readable
 * file of everything the instance holds about that person.
 *
 * D-4 IN THREE PARTS, AND THE THIRD IS NOT WHAT IT FIRST LOOKS LIKE.
 *
 * It runs as a background job, because assembling a person across every
 * register is slow and a request that times out is a request nobody answers.
 *
 * It is delivered with an expiry, asked BEFORE anything is assembled, so a link
 * that outlived its term produces a refusal rather than the most sensitive
 * bytes the instance can produce.
 *
 * And those bytes are never written to rest. The obvious reading of "delivered
 * as a file" is a file saved somewhere with a cleanup job behind it, and that
 * leaves a complete dossier on one person sitting in storage waiting for the
 * cleanup to work. Instead the row records WHAT was assembled (a count and a
 * hash) and the download re-assembles under the requester's own RBAC scope, the
 * same shape {@see ExportBundleService} already uses for the case bundle. The
 * subject still receives a file; the instance simply never keeps one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Gdpr\Export
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Export;

use DateTime;
use OCA\OpenRegister\BackgroundJob\SubjectExportJob;
use OCA\OpenRegister\Db\SubjectExport;
use OCA\OpenRegister\Db\SubjectExportMapper;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Requests, assembles and delivers a data subject's own export.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Export
 *
 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
 */
class SubjectExportService {
	/**
	 * The audit action an export is recorded under.
	 *
	 * @var string
	 */
	public const AUDIT_ACTION = 'gdpr.subject-export';

	/**
	 * Wire the service.
	 *
	 * @param SubjectExportMapper       $mapper       The export rows.
	 * @param DataSubjectRequestService $subjects     The RBAC scoped assembler.
	 * @param IJobList                  $jobList      Where the assembly is queued.
	 * @param IUserSession              $userSession  The requester.
	 * @param IGroupManager             $groupManager Administrator reach.
	 * @param LoggerInterface           $logger       PSR logger, which carries the audit line.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SubjectExportMapper $mapper,
		private readonly DataSubjectRequestService $subjects,
		private readonly IJobList $jobList,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Ask for a subject's own export. Queues the assembly, writes nothing else.
	 *
	 * @param string      $subject   The data subject.
	 * @param string|null $type      Optional PII type filter.
	 * @param string|null $requestId The data subject request this answers.
	 *
	 * @return SubjectExport The recorded request.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function request(string $subject, ?string $type = null, ?string $requestId = null): SubjectExport {
		$export = $this->mapper->createFromArray(
			[
				'subject' => trim($subject),
				'subjectType' => $type,
				'requestId' => $requestId,
				'status' => SubjectExport::STATUS_PENDING,
				'requestedBy' => $this->actor(),
			]
		);

		$this->jobList->add(SubjectExportJob::class, ['uuid' => $export->getUuid()]);

		$this->audit(export: $export, event: 'requested');

		return $export;
	}//end request()

	/**
	 * Assemble one pending export. Called by the background job.
	 *
	 * @param string $uuid The export uuid.
	 *
	 * @return SubjectExport|null The export as it now stands, or null when it is gone.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function assemble(string $uuid): ?SubjectExport {
		try {
			$export = $this->mapper->findByUuid(uuid: $uuid);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[SubjectExport] The queued export no longer exists',
				context: ['uuid' => $uuid, 'error' => $e->getMessage()]
			);
			return null;
		}

		try {
			$bundle = $this->subjects->assembleAccessExport(
				subjectId: (string)$export->getSubject(),
				type: $export->getSubjectType()
			);

			$now = new DateTime();
			$export->setStatus(SubjectExport::STATUS_READY);
			$export->setObjectCount((int)($bundle['objectCount'] ?? 0));
			$export->setContentHash($this->hash(bundle: $bundle));
			$export->setReadyAt($now);
			$export->setExpiresAt((clone $now)->modify('+' . SubjectExport::DEFAULT_TTL_SECONDS . ' seconds'));
			$export->setError(null);
		} catch (Throwable $e) {
			// A failed assembly is recorded as failed and says why. Leaving it
			// pending would make a request nobody can act on look like one that
			// is merely slow.
			$export->setStatus(SubjectExport::STATUS_FAILED);
			$export->setError($e->getMessage());
		}//end try

		$saved = $this->mapper->save(export: $export);
		$this->audit(export: $saved, event: $saved->getStatus() ?? '');

		return $saved;
	}//end assemble()

	/**
	 * Load an export the caller may see, or null.
	 *
	 * @param string $uuid The export uuid.
	 *
	 * @return SubjectExport|null The export, or null when absent or not theirs.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function load(string $uuid): ?SubjectExport {
		try {
			$export = $this->mapper->findByUuid(uuid: $uuid);
		} catch (Throwable $e) {
			return null;
		}

		if ($this->mayReach(export: $export) === false) {
			// Answered as absent rather than forbidden, on the same terms as the
			// erasure preview: confirming the row exists confirms somebody asked
			// about this data subject.
			return null;
		}

		return $export;
	}//end load()

	/**
	 * The export's bytes, or null when the delivery may not happen.
	 *
	 * The expiry is asked BEFORE the assembly, so an expired link costs nothing
	 * and reveals nothing.
	 *
	 * @param string        $uuid The export uuid.
	 * @param DateTime|null $now  The moment, or null for the real one.
	 *
	 * @return string|null The machine readable export, or null when refused.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function download(string $uuid, ?DateTime $now = null): ?string {
		$export = $this->load(uuid: $uuid);
		if ($export === null || $export->isDownloadableAt($now) === false) {
			return null;
		}

		$bundle = $this->subjects->assembleAccessExport(
			subjectId: (string)$export->getSubject(),
			type: $export->getSubjectType()
		);

		$export->setDeliveredAt(new DateTime());
		$this->mapper->save(export: $export);
		$this->audit(export: $export, event: 'delivered');

		return (string)json_encode($bundle, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}//end download()

	/**
	 * The stable hash of an assembled bundle.
	 *
	 * Excludes `generatedAt`, so re-assembling an unchanged world at download
	 * time produces the hash the row recorded and a reader can tell whether the
	 * answer moved between the request and the delivery.
	 *
	 * @param array<string, mixed> $bundle The assembled export.
	 *
	 * @return string A hex sha256.
	 */
	private function hash(array $bundle): string {
		unset($bundle['generatedAt']);

		return hash('sha256', (string)json_encode($bundle));
	}//end hash()

	/**
	 * Record the export on the audit trail, naming requester and subject.
	 *
	 * @param SubjectExport $export The export.
	 * @param string        $event  What happened to it.
	 *
	 * @return void
	 */
	private function audit(SubjectExport $export, string $event): void {
		$this->logger->info(
			message: '[SubjectExport] ' . $event,
			context: [
				'action' => self::AUDIT_ACTION,
				'event' => $event,
				'export' => $export->getUuid(),
				// BOTH NAMES, ALWAYS. An export line that records only the
				// requester cannot answer "who has been asked about", and one
				// that records only the subject cannot answer "who asked".
				'requestedBy' => $export->getRequestedBy(),
				'dataSubject' => $export->getSubject(),
				'dataSubjectRequest' => $export->getRequestId(),
				'objectCount' => $export->getObjectCount(),
				'expiresAt' => $export->getExpiresAt()?->format(DateTime::ATOM),
			]
		);
	}//end audit()

	/**
	 * Whether the caller may see this export at all.
	 *
	 * @param SubjectExport $export The export.
	 *
	 * @return bool True for its requester and for an administrator.
	 */
	private function mayReach(SubjectExport $export): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return true;
		}

		return ($export->getRequestedBy() === $user->getUID());
	}//end mayReach()

	/**
	 * The acting principal's uid, or `system`.
	 *
	 * @return string The actor.
	 */
	private function actor(): string {
		return ($this->userSession->getUser()?->getUID() ?? 'system');
	}//end actor()
}//end class
