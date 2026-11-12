<?php

/**
 * ErasureProtectionResolver — the ground an object is protected from erasure under.
 *
 * Asks the two authorities that can hold a record back, in a fixed order: the
 * archival guard (legal hold, Archiefwet obligation, unresolvable schema) and
 * then the retention service (immutable archival status). The first that holds
 * wins and the second is not asked.
 *
 * D-2 IS THE WHOLE POINT OF THE CATCH. The archival guard already answers
 * `SCHEMA_UNRESOLVED` for a question it can ask and not answer. What it cannot
 * report is a failure to ask at all — a database that will not answer, a schema
 * row that throws on read. That case counts as protected and it is named.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Names the ground an object may not be erased under, or answers null.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
class ErasureProtectionResolver {
	/**
	 * Wire the authorities.
	 *
	 * @param ArchivalRetentionGuard    $archivalGuard Retention and legal-hold refusal, with its wording.
	 * @param RetentionServiceInterface $retention     Immutable archival status.
	 * @param LoggerInterface           $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ArchivalRetentionGuard $archivalGuard,
		private readonly RetentionServiceInterface $retention,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The ground this object is protected under, or null when it is not.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, mixed>|null The ground block, or null when erasable.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function protectionFor(ObjectEntity $object): ?array {
		try {
			return ($this->archivalGround(object: $object) ?? $this->immutableGround(object: $object));
		} catch (Throwable $e) {
			return $this->unresolvable(object: $object, error: $e);
		}
	}//end protectionFor()

	/**
	 * The archival guard's refusal as a ground block, or null.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, string>|null The ground block.
	 */
	private function archivalGround(ObjectEntity $object): ?array {
		$refusal = $this->archivalGuard->erasureRefusal(object: $object);
		if ($refusal === null) {
			return null;
		}

		return [
			'ground' => (string)($refusal['ground'] ?? ArchivalRetentionGuard::GROUND_UNRESOLVED),
			'message' => (string)($refusal['message'] ?? ''),
			'basis' => (string)($refusal['basis'] ?? ''),
			'action' => (string)($refusal['action'] ?? ''),
		];
	}//end archivalGround()

	/**
	 * The immutable archival status as a ground block, or null.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, string>|null The ground block.
	 */
	private function immutableGround(ObjectEntity $object): ?array {
		$immutable = $this->retention->validateNotImmutable(object: $object);
		if ($immutable === null) {
			return null;
		}

		return [
			'ground' => ErasureBucket::GROUND_IMMUTABLE,
			'message' => 'This record is in an archival status that cannot be changed, so we did not erase it.',
			'basis' => 'Archiefwet: status ' . $immutable . '.',
			'action' => 'Name this record in your answer to the requester.',
		];
	}//end immutableGround()

	/**
	 * The ground block for a hold nobody could resolve.
	 *
	 * @param ObjectEntity $object The object.
	 * @param Throwable    $error  What went wrong resolving it.
	 *
	 * @return array<string, string> The ground block.
	 */
	private function unresolvable(ObjectEntity $object, Throwable $error): array {
		$this->logger->warning(
			message: '[ErasurePreview] Could not resolve the hold on an object, counting it as protected',
			context: ['uuid' => $object->getUuid(), 'error' => $error->getMessage()]
		);

		return [
			'ground' => ErasureBucket::GROUND_UNRESOLVABLE,
			'message' => 'We could not establish whether this record is held, so we left it alone.',
			'basis' => 'Precaution: erasing on an unknown is the one failure that cannot be undone.',
			'action' => 'Ask an administrator to repair this record, then run the preview again.',
		];
	}//end unresolvable()
}//end class
