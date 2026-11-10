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

use OCA\OpenRegister\Db\AuditTrail\AuditedObject;
use OCA\OpenRegister\Db\AuditTrail\ExportOutcome;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the export facts to the hash-chained audit trail.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
class ExportAuditRecorder implements ExportAuditRecorderInterface {
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
	 * @param AuditTrailMapperInterface $auditTrailMapper The hash-chained audit trail.
	 * @param LoggerInterface  $logger           PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record a completed export from its fields; see {@see recordCompletion()}.
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
	 * @spec openspec/specs/data-import-export/spec.md
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
		$completion = new ExportCompletion(profile: $profile, rowCount: $rowCount, format: $format, valueMode: $valueMode);
		$this->recordCompletion(completion: $completion, scope: new ExportScope(register: $register, schema: $schema, actorId: $actorId));
	}//end recordCompleted()

	/**
	 * Record a refused export from its fields; see {@see recordRefusal()}.
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
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function recordRefused(
		string $profile,
		string $rule,
		string $reason,
		?int $register = null,
		?int $schema = null,
		?string $actorId = null,
	): void {
		$refusal = new ExportRefusal(profile: $profile, rule: $rule, reason: $reason);
		$this->recordRefusal(refusal: $refusal, scope: new ExportScope(register: $register, schema: $schema, actorId: $actorId));
	}//end recordRefused()

	/**
	 * Record a completed export.
	 *
	 * @param ExportCompletion $completion The profile, the row count, the format and the value mode.
	 * @param ExportScope      $scope      The register and schema exported, and the principal when it is not the session user.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function recordCompletion(ExportCompletion $completion, ExportScope $scope): void {
		$summary = [
			'profile' => $completion->profile,
			'rowCount' => $completion->rowCount,
			'format' => $completion->format,
			'valueMode' => $completion->valueMode,
		];
		$this->write(entry: new ExportAuditEntry(outcome: self::OUTCOME_COMPLETED, summary: $summary, scope: $scope));
	}//end recordCompletion()

	/**
	 * Record a refused export.
	 *
	 * @param ExportRefusal $refusal The profile asked for, the rule that refused and the reason given.
	 * @param ExportScope   $scope   The register and schema asked for, and the principal when it is not the session user.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function recordRefusal(ExportRefusal $refusal, ExportScope $scope): void {
		$summary = [
			'profile' => $refusal->profile,
			'rowCount' => 0,
			'verb' => ExportRightService::ACTION,
			'rule' => $refusal->rule,
			'reason' => $refusal->reason,
		];
		$this->write(entry: new ExportAuditEntry(outcome: self::OUTCOME_REFUSED, summary: $summary, scope: $scope));
	}//end recordRefusal()

	/**
	 * Write one entry, swallowing a ledger failure.
	 *
	 * @param ExportAuditEntry $entry The outcome, summary, register, schema and principal.
	 *
	 * @return void
	 */
	private function write(ExportAuditEntry $entry): void {
		try {
			$target = new AuditedObject(register: $entry->scope->register, schema: $entry->scope->schema);
			$this->auditTrailMapper->recordExport(
				export: new ExportOutcome(outcome: $entry->outcome, summary: $entry->summary, target: $target, actorId: $entry->scope->actorId)
			);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[ExportAudit] Could not record the export',
				context: ['outcome' => $entry->outcome, 'summary' => $entry->summary, 'error' => $e->getMessage()]
			);
		}
	}//end write()
}//end class
