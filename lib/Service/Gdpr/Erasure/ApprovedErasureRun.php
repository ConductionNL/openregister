<?php

/**
 * ApprovedErasureRun — one run of an approved erasure preview, in progress.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Erasure;

use OCA\OpenRegister\Db\ErasurePreview;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;

/**
 * The approved preview being run, its discovery hits and the report so far.
 *
 * Built by ErasureRunner::run() and never handed out of it.
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
final class ApprovedErasureRun {
	/**
	 * The subject's discovery hits, indexed by object uuid.
	 *
	 * @var array<string, array>
	 */
	public array $hits = [];

	/**
	 * The run report, filled per item.
	 *
	 * @var array<string, mixed>
	 */
	public array $outcome = [];

	/**
	 * Open the run.
	 *
	 * @param ErasurePreview $record The approved, unspent preview.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function __construct(
		public readonly ErasurePreview $record,
	) {
	}//end __construct()

	/**
	 * The subject value the preview was approved for.
	 *
	 * @return string The subject, empty when the record carries none.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function subject(): string {
		return (string)($this->record->getSubject() ?? '');
	}//end subject()

	/**
	 * The erase mode the preview was approved for.
	 *
	 * @return string The mode, pseudonymise when the record carries none.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function mode(): string {
		return (string)($this->record->getEraseMode() ?? DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE);
	}//end mode()
}//end class
