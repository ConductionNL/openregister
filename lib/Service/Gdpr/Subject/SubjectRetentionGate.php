<?php

/**
 * OpenRegister retention gate for the scoped data-subject service.
 *
 * Answers, per object, whether a data-subject write must stand down:
 *
 *   - ERASURE: an archival obligation on the SCHEMA (Archiefwet,
 *     art-17(3)(b)) withholds the record; failing that, an active legal hold
 *     or an immutable archival status on the OBJECT holds it. The archival
 *     question is asked FIRST, so a record that is both is reported withheld.
 *   - RECTIFY / MARKERS: an immutable archival status refuses the write.
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
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\RetentionServiceInterface;

/**
 * Retention / legal-hold / immutability refusals for data-subject writes.
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md#requirement-erasure-honours-legal-hold-and-is-mode-parameterised
 */
class SubjectRetentionGate {

	/**
	 * Summary bucket of a record an archival obligation keeps.
	 *
	 * @var string
	 */
	public const BUCKET_WITHHELD = 'withheld';

	/**
	 * Summary bucket of a record under legal hold or immutable.
	 *
	 * @var string
	 */
	public const BUCKET_HELD = 'held';

	/**
	 * Constructor.
	 *
	 * @param RetentionServiceInterface $retentionService Legal-hold + immutability guard.
	 * @param ArchivalRetentionGuard    $archivalGuard    Refuses erasure of a legally retained record.
	 */
	public function __construct(
		private readonly RetentionServiceInterface $retentionService,
		private readonly ArchivalRetentionGuard $archivalGuard,
	) {

	}//end __construct()

	/**
	 * Why an object must NOT be erased, as a summary bucket and row, or null.
	 *
	 * RETENTION WINS OVER ERASURE, AND THE SCHEMA IS WHERE THE OBLIGATION
	 * LIVES. The hold/immutability check reads the OBJECT's own
	 * `retention.archiefstatus` and the legal hold on it; neither says anything
	 * about `x-openregister-archival`, which is declared on the SCHEMA. So the
	 * archival guard is asked first, through the same definition the four HTTP
	 * delete doors use.
	 *
	 * @param ObjectEntity $object Object about to be erased.
	 *
	 * @return array{0: string, 1: array<string, mixed>}|null [bucket, row], or null when erasable.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md#requirement-erasure-honours-legal-hold-and-is-mode-parameterised
	 */
	public function erasureBlock(ObjectEntity $object): ?array {
		$refusal = $this->archivalGuard->erasureRefusal(object: $object);
		if ($refusal !== null) {
			return [self::BUCKET_WITHHELD, $refusal];
		}

		$holdReason = $this->holdReason(object: $object);
		if ($holdReason !== null) {
			return [self::BUCKET_HELD, ['uuid' => $object->getUuid(), 'reason' => $holdReason]];
		}

		return null;

	}//end erasureBlock()

	/**
	 * Whether the object is in an immutable archival status.
	 *
	 * @param ObjectEntity $object Object about to be written.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md#requirement-erasure-honours-legal-hold-and-is-mode-parameterised
	 */
	public function isImmutable(ObjectEntity $object): bool {
		return $this->retentionService->validateNotImmutable(object: $object) !== null;

	}//end isImmutable()

	/**
	 * A hold/immutability reason that BLOCKS erasure, or null.
	 *
	 * @param ObjectEntity $object Object to check.
	 *
	 * @return string|null `legal-hold`, an immutable status code, or null.
	 */
	private function holdReason(ObjectEntity $object): ?string {
		if ($this->retentionService->hasActiveLegalHold(object: $object) === true) {
			return 'legal-hold';
		}

		return $this->retentionService->validateNotImmutable(object: $object);

	}//end holdReason()
}//end class
