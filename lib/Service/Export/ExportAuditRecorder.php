<?php

/**
 * ExportAuditRecorder — one row per export, and one per refusal.
 *
 * An export is a data transfer. Recording who took it, under which profile, how
 * many rows and when is what lets an incident be reconstructed later, and it
 * costs one row (design D-6). The refusal is recorded on the same terms,
 * because the attempt is the interesting event when somebody was told no.
 *
 * NEVER FAILS THE EXPORT. An audit write that throws is logged and swallowed
 * here, unlike a destruction record, which refuses. The difference is what the
 * two acts do: a destruction that is not recorded is unreconstructable and
 * irreversible, while an export that is not recorded has still only read data
 * the caller was allowed to read. Turning a hash-chain hiccup into a failed
 * monthly aanlevering would be the worse trade.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Export
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

use OCA\OpenRegister\Db\AuditTrailMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the export facts to the hash-chained audit trail.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
 */
class ExportAuditRecorder {
	/**
	 * The outcome of an export that produced a file.
	 *
	 * @var string
	 */
	public const OUTCOME_COMPLETED = 'completed';

	/**
	 * The outcome of an export that was refused before it read anything.
	 *
	 * @var string
	 */
	public const OUTCOME_REFUSED = 'refused';

	/**
	 * Wire the ledger.
	 *
	 * @param AuditTrailMapper $auditTrailMapper The hash-chained audit trail.
	 * @param LoggerInterface  $logger           PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record a completed export.
	 *
	 * @param string      $profile  The profile name, or `ad-hoc` when the caller named no profile.
	 * @param int         $rowCount How many rows left the instance.
	 * @param string      $format   The format written.
	 * @param string|null $valueMode The value mode the file was written in, when a profile chose one.
	 * @param int|null    $register The register exported.
	 * @param int|null    $schema   The schema exported.
	 * @param string|null $actorId  The principal the export ran as, when it is not the session user.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function recordCompleted(
		string $profile,
		int $rowCount,
		string $format,
		?string $valueMode = null,
		?int $register = null,
		?int $schema = null,
		?string $actorId = null,
	): void {
		$this->write(
			outcome: self::OUTCOME_COMPLETED,
			summary: [
				'profile' => $profile,
				'rowCount' => $rowCount,
				'format' => $format,
				'valueMode' => $valueMode,
			],
			register: $register,
			schema: $schema,
			actorId: $actorId
		);
	}//end recordCompleted()

	/**
	 * Record a refused export.
	 *
	 * @param string      $profile  The profile name, or `ad-hoc` when the caller named no profile.
	 * @param string      $rule     The rule that refused.
	 * @param string      $reason   The sentence the caller was given.
	 * @param int|null    $register The register that was asked for.
	 * @param int|null    $schema   The schema that was asked for.
	 * @param string|null $actorId  The principal that was refused, when it is not the session user.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function recordRefused(
		string $profile,
		string $rule,
		string $reason,
		?int $register = null,
		?int $schema = null,
		?string $actorId = null,
	): void {
		$this->write(
			outcome: self::OUTCOME_REFUSED,
			summary: [
				'profile' => $profile,
				'rowCount' => 0,
				'verb' => ExportRightService::ACTION,
				'rule' => $rule,
				'reason' => $reason,
			],
			register: $register,
			schema: $schema,
			actorId: $actorId
		);
	}//end recordRefused()

	/**
	 * Write one entry, swallowing a ledger failure.
	 *
	 * @param string $outcome The outcome.
	 * @param array<string, mixed> $summary The structured summary.
	 * @param int|null $register The register.
	 * @param int|null $schema The schema.
	 * @param string|null $actorId The principal.
	 *
	 * @return void
	 */
	private function write(
		string $outcome,
		array $summary,
		?int $register,
		?int $schema,
		?string $actorId,
	): void {
		try {
			$this->auditTrailMapper->createExportEntry(
				outcome: $outcome,
				summary: $summary,
				register: $register,
				schema: $schema,
				actorId: $actorId
			);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[ExportAudit] Could not record the export',
				context: ['outcome' => $outcome, 'summary' => $summary, 'error' => $e->getMessage()]
			);
		}
	}//end write()
}//end class
