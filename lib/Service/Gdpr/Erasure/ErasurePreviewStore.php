<?php

/**
 * ErasurePreviewStore — records a preview, takes its approval, spends it once.
 *
 * The preview itself writes nothing, which is the point of a preview. This is
 * the deliberate second act: the handler asks for the answer to be KEPT, so
 * there is something to approve and something an erasure can be checked
 * against. Three refusals live here and each names itself:
 *
 *   - `erasure-preview-unknown`  — no such preview.
 *   - `erasure-preview-not-yours` — it belongs to another handler.
 *   - `erasure-not-approved`      — it exists, and nobody approved it.
 *   - `erasure-already-run`       — it was approved, and already spent.
 *
 * WHY THE OWNERSHIP CHECK IS HERE AND NOT IN THE CONTROLLER. The endpoints are
 * `@NoAdminRequired`, so any authenticated user can reach them with any uuid.
 * A preview holds the names of a data subject's records, which is exactly the
 * thing a stranger must not be able to read by guessing an id.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Gdpr\Erasure
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Erasure;

use DateTime;
use OCA\OpenRegister\Db\ErasurePreview;
use OCA\OpenRegister\Db\ErasurePreviewMapper;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The lifecycle of a recorded erasure preview: record, approve, spend.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
 *
 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
 */
class ErasurePreviewStore {
	/**
	 * Wire the store.
	 *
	 * @param ErasurePreviewMapper $mapper       The preview rows.
	 * @param IUserSession         $userSession  The acting principal.
	 * @param IGroupManager        $groupManager Administrator reach.
	 * @param LoggerInterface      $logger       PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ErasurePreviewMapper $mapper,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Keep a computed preview, so it can be approved and run from.
	 *
	 * @param array<string, mixed> $preview   The preview from ErasurePreviewService.
	 * @param string|null          $requestId The data subject request it answers, when there is one.
	 *
	 * @return ErasurePreview The recorded preview.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function record(array $preview, ?string $requestId = null): ErasurePreview {
		return $this->mapper->createFromArray(
			[
				'subject' => (string)($preview['subject'] ?? ''),
				'subjectType' => ($preview['type'] ?? null),
				'eraseMode' => (string)($preview['eraseMode'] ?? ''),
				'requestId' => $requestId,
				'digest' => (string)($preview['digest'] ?? ''),
				'report' => $preview,
				'status' => ErasurePreview::STATUS_PENDING,
				'createdBy' => $this->actor(),
			]
		);
	}//end record()

	/**
	 * Load a preview the caller may see, or refuse naming the rule.
	 *
	 * @param string $uuid The preview uuid.
	 *
	 * @return ErasurePreview The preview.
	 *
	 * @throws ErasureRefusedException When it does not exist or is not the caller's.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function load(string $uuid): ErasurePreview {
		try {
			$preview = $this->mapper->findByUuid(uuid: $uuid);
		} catch (Throwable $e) {
			throw new ErasureRefusedException(
				rule: 'erasure-preview-unknown',
				reason: 'No erasure preview with that identifier exists.',
				statusCode: 404,
				previous: $e
			);
		}

		if ($this->mayReach(preview: $preview) === false) {
			// SAME ANSWER AS "UNKNOWN", DELIBERATELY. Telling a stranger that the
			// preview exists but is not theirs confirms that somebody asked about
			// this data subject, which is itself the disclosure.
			throw new ErasureRefusedException(
				rule: 'erasure-preview-unknown',
				reason: 'No erasure preview with that identifier exists.',
				statusCode: 404
			);
		}

		return $preview;
	}//end load()

	/**
	 * Approve a preview, so an erasure may run from it.
	 *
	 * @param string $uuid The preview uuid.
	 *
	 * @return ErasurePreview The approved preview.
	 *
	 * @throws ErasureRefusedException When it is already spent, or not the caller's.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function approve(string $uuid): ErasurePreview {
		$preview = $this->load(uuid: $uuid);

		if ($preview->getStatus() === ErasurePreview::STATUS_CONSUMED) {
			throw new ErasureRefusedException(
				rule: 'erasure-already-run',
				reason: 'This preview has already been run. Take a new preview before erasing again.',
				statusCode: 409,
				context: ['consumedAt' => $preview->getConsumedAt()?->format(DateTime::ATOM)]
			);
		}

		$preview->setStatus(ErasurePreview::STATUS_APPROVED);
		$preview->setApprovedBy($this->actor());
		$preview->setApprovedAt(new DateTime());

		return $this->mapper->save(preview: $preview);
	}//end approve()

	/**
	 * Load a preview an erasure may actually run from, or refuse.
	 *
	 * @param string $uuid The preview uuid.
	 *
	 * @return ErasurePreview The runnable preview.
	 *
	 * @throws ErasureRefusedException When it is unapproved or already spent.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function requireRunnable(string $uuid): ErasurePreview {
		$preview = $this->load(uuid: $uuid);

		if ($preview->getStatus() === ErasurePreview::STATUS_CONSUMED) {
			throw new ErasureRefusedException(
				rule: 'erasure-already-run',
				reason: 'This preview has already been run. Take a new preview before erasing again.',
				statusCode: 409,
				context: ['consumedAt' => $preview->getConsumedAt()?->format(DateTime::ATOM)]
			);
		}

		if ($preview->isRunnable() === false) {
			throw new ErasureRefusedException(
				rule: 'erasure-not-approved',
				reason: 'This erasure preview has not been approved, so nothing was erased. '
					. 'Approve the preview first, and check its protected records before you do.',
				statusCode: 409,
				context: ['status' => $preview->getStatus()]
			);
		}

		return $preview;
	}//end requireRunnable()

	/**
	 * Mark a preview spent and keep what the run actually did.
	 *
	 * @param ErasurePreview       $preview The preview that was run.
	 * @param array<string, mixed> $outcome The run report.
	 *
	 * @return ErasurePreview The consumed preview.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function consume(ErasurePreview $preview, array $outcome): ErasurePreview {
		$preview->setStatus(ErasurePreview::STATUS_CONSUMED);
		$preview->setConsumedAt(new DateTime());
		$preview->setOutcome($outcome);

		try {
			return $this->mapper->save(preview: $preview);
		} catch (Throwable $e) {
			// The erasure has already happened. Losing the stamp would let it be
			// run a second time, so it is loud rather than swallowed.
			$this->logger->error(
				message: '[ErasurePreview] Could not stamp the preview as consumed after an erasure ran',
				context: ['uuid' => $preview->getUuid(), 'error' => $e->getMessage()]
			);

			return $preview;
		}
	}//end consume()

	/**
	 * Whether the caller may see this preview at all.
	 *
	 * @param ErasurePreview $preview The preview.
	 *
	 * @return bool True for its author and for an administrator.
	 */
	private function mayReach(ErasurePreview $preview): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return true;
		}

		return ($preview->getCreatedBy() === $user->getUID());
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
