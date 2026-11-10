<?php

/**
 * OpenRegister Bulk Save Outcome DTO
 *
 * Value object that answers one question about a bulk save: was every
 * submitted object accounted for, and if not, which ones were not and why.
 *
 * It exists because the bulk endpoint used to answer that question with the
 * constant `true` (issue #2778). The save path already knew better — the
 * validator writes a message for every row it refuses — and the response threw
 * that knowledge away, so a batch where 27 of 58 objects were rejected for a
 * malformed datetime reported "completed successfully" and left the shortfall
 * visible only to a caller that thought to compare `saved_count` against its
 * own request size.
 *
 * @category Dto
 * @package  OCA\OpenRegister\Dto
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Dto;

use OCA\OpenRegister\Service\Object\BatchOperationStatusInterface;

/**
 * Per-object failure summary for one bulk save call.
 *
 * @category Dto
 * @package  OCA\OpenRegister\Dto
 */
class BulkSaveOutcome implements BulkSaveOutcomeInterface {

	/**
	 * How many batch-level (non per-object) error messages the summary repeats.
	 *
	 * A batch that fails wholesale can record one error per row; the point of
	 * the suffix is to name the cause, not to mirror the whole log.
	 *
	 * @var integer
	 */
	private const MAX_REPORTED_BATCH_ERRORS = 5;

	/**
	 * Number of submitted OBJECTS that were not written.
	 *
	 * Deliberately independent of the length of {@see $failures}: rows can
	 * disappear without a recorded reason, and that gap gets one collective
	 * entry rather than being rounded away to zero.
	 *
	 * @var integer
	 */
	public readonly int $failedCount;

	/**
	 * Per-object explanations, each `{index, uuid, error, type}`.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public readonly array $failures;

	/**
	 * Constructor.
	 *
	 * @param int $failedCount Number of submitted objects that were not written.
	 * @param array $failures Per-object explanations.
	 *
	 * @return void
	 */
	public function __construct(int $failedCount, array $failures) {
		$this->failedCount = $failedCount;
		$this->failures = $failures;

	}//end __construct()

	/**
	 * Number of submitted objects that were not written.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function failedCount(): int {
		return $this->failedCount;

	}//end failedCount()

	/**
	 * Per-object explanations, each `{index, uuid, error, type}`.
	 *
	 * @return array<int, array<string, mixed>> The explanations.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function failures(): array {
		return $this->failures;

	}//end failures()

	/**
	 * Whether every submitted object was accounted for.
	 *
	 * @return bool True when nothing was lost.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function isComplete(): bool {
		return ($this->failedCount === 0);

	}//end isComplete()

	/**
	 * Summarise a raw `ObjectService::saveObjects()` result.
	 *
	 * The rejected rows arrive in `invalid[]`, each already carrying the
	 * validator's own message — this is the detail the endpoint used to
	 * discard, and rediscovering it cost a manual bisect over individual
	 * fields.
	 *
	 * @param array $bulkResult Raw result from ObjectService::saveObjects().
	 * @param int $requestedCount Rows the caller submitted.
	 * @param int $accountedCount Rows the result accounts for (saved + updated + unchanged).
	 *
	 * @return self The summarised outcome.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public static function fromBulkResult(array $bulkResult, int $requestedCount, int $accountedCount): self {
		return self::fromSaveResult(bulkResult: $bulkResult, rows: new BulkRowCount(requested: $requestedCount, accounted: $accountedCount));

	}//end fromBulkResult()

	/**
	 * Summarise a raw `ObjectService::saveObjects()` result against the row counts.
	 *
	 * Every rejected row in `invalid[]` is reported with the validator's own
	 * message; rows neither accounted for nor rejected become one
	 * `UnaccountedObject` entry carrying the batch-level errors.
	 *
	 * @param array        $bulkResult Raw result from ObjectService::saveObjects().
	 * @param BulkRowCount $rows       Rows submitted and rows the result accounts for.
	 *
	 * @return self The summarised outcome.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public static function fromSaveResult(array $bulkResult, BulkRowCount $rows): self {
		$failures = self::rejections(bulkResult: $bulkResult);
		$rejected = count($failures);
		$unaccounted = $rows->unaccounted(rejected: $rejected);
		if ($unaccounted > 0) {
			$failures[] = self::unaccountedFailure(unaccounted: $unaccounted, bulkResult: $bulkResult);
		}

		return new self(failedCount: ($rejected + $unaccounted), failures: $failures);

	}//end fromSaveResult()

	/**
	 * The one failure that stands for rows the save neither wrote nor rejected.
	 *
	 * @param int   $unaccounted How many rows vanished without a reason.
	 * @param array $bulkResult  Raw result, for its batch-level errors.
	 *
	 * @return array<string, mixed> The failure.
	 */
	private static function unaccountedFailure(int $unaccounted, array $bulkResult): array {
		return [
			'index' => null,
			'uuid' => null,
			'error' => sprintf(
				'%d object(s) were not written and the save path recorded no per-object reason.%s',
				$unaccounted,
				self::describeUnattributedErrors(bulkResult: $bulkResult)
			),
			'type' => 'UnaccountedObject',
		];

	}//end unaccountedFailure()

	/**
	 * One failure per rejected row in `invalid[]`; entries that are not arrays are skipped.
	 *
	 * @param array $bulkResult Raw result from ObjectService::saveObjects().
	 *
	 * @return array<int, array<string, mixed>> The failures.
	 */
	private static function rejections(array $bulkResult): array {
		$invalid = ($bulkResult['invalid'] ?? []);
		if (is_array($invalid) === false) {
			return [];
		}

		$failures = [];
		foreach ($invalid as $entry) {
			if (is_array($entry) === true) {
				$failures[] = self::rejection(entry: $entry);
			}
		}

		return $failures;

	}//end rejections()

	/**
	 * The failure for one rejected row, with defaults for a missing reason or type.
	 *
	 * @param array $entry One `invalid[]` entry.
	 *
	 * @return array<string, mixed> The failure.
	 */
	private static function rejection(array $entry): array {
		$row = ($entry['object'] ?? []);
		if (is_array($row) === false) {
			$row = [];
		}

		$index = null;
		if (isset($entry['index']) === true && is_numeric($entry['index']) === true) {
			$index = (int)$entry['index'];
		}

		return [
			'index' => $index,
			'uuid' => self::extractRowUuid(row: $row),
			'error' => (string)($entry['error'] ?? 'Object was rejected without a recorded reason'),
			'type' => (string)($entry['type'] ?? 'BulkSaveRejection'),
		];

	}//end rejection()

	/**
	 * Summarise a streaming batch status into the same shape.
	 *
	 * The streaming path already derived its `success` from the failed count,
	 * but reported the detail under its own key layout, so a caller had to know
	 * which path it had asked for before it could read the answer.
	 *
	 * @param BatchOperationStatusInterface $status Per-row outcomes from the streaming save.
	 * @param int $requestedCount Rows the caller submitted.
	 * @param int $accountedCount Rows the status accounts for (created + updated + unchanged).
	 *
	 * @return self The summarised outcome.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public static function fromBatchStatus(
		BatchOperationStatusInterface $status,
		int $requestedCount,
		int $accountedCount
	): self {
		return self::fromStreamedBatch(status: $status, rows: new StreamedRowCount(requested: $requestedCount, accounted: $accountedCount));
	}//end fromBatchStatus()

	/**
	 * Summarise a streaming batch status, given the rows asked for and accounted for.
	 *
	 * @param BatchOperationStatusInterface $status Per-row outcomes from the streaming save.
	 * @param StreamedRowCount              $rows   Rows submitted and rows the status accounts for.
	 *
	 * @return self The summarised outcome.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public static function fromStreamedBatch(BatchOperationStatusInterface $status, StreamedRowCount $rows): self {
		$failures = [];
		foreach ($status->getFailed() as $failure) {
			$failures[] = [
				'index' => ($failure['index'] ?? null),
				'uuid' => ($failure['uuid'] ?? null),
				// No `??` fallback on these two: the source array's shape declares
				// both as non-nullable `string`, so the fallback is unreachable and
				// PHPStan reports it. A defaulted read that can never default is
				// worse than none — it reads as a handled edge case that does not
				// exist, and hides the day the shape actually changes.
				'error' => (string)$failure['message'],
				'type' => (string)$failure['exceptionClass'],
			];
		}

		$unaccounted = max(0, ($rows->requested - $rows->accounted - count($failures)));
		if ($unaccounted > 0) {
			$failures[] = [
				'index' => null,
				'uuid' => null,
				'error' => sprintf(
					'%d object(s) were not written and the streaming path recorded no per-row reason.',
					$unaccounted
				),
				'type' => 'UnaccountedObject',
			];
		}

		return new self(
			failedCount: ($status->getFailedCount() + $unaccounted),
			failures: $failures
		);

	}//end fromStreamedBatch()

	/**
	 * Collect batch-level errors that are not already attributed to a row.
	 *
	 * `recordSafeguardRejection()` writes every per-row refusal into BOTH
	 * `invalid` and `errors`, so echoing `errors` wholesale would repeat what
	 * the per-object list already says. Only the entries belonging to no row —
	 * "no objects were successfully prepared", and friends — add information.
	 *
	 * @param array $bulkResult Raw result from ObjectService::saveObjects().
	 *
	 * @return string Empty string, or a ' Reported errors: ...' suffix.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private static function describeUnattributedErrors(array $bulkResult): string {
		$errors = ($bulkResult['errors'] ?? []);
		if (is_array($errors) === false) {
			return '';
		}

		$messages = [];
		foreach ($errors as $error) {
			if (is_array($error) === false || ($error['type'] ?? '') === 'BulkSafeguardException') {
				continue;
			}

			$messages[] = (string)($error['error'] ?? '');
			if (count($messages) >= self::MAX_REPORTED_BATCH_ERRORS) {
				break;
			}
		}

		if ($messages === []) {
			return '';
		}

		return ' Reported errors: ' . implode('; ', $messages);

	}//end describeUnattributedErrors()

	/**
	 * Pull the caller-supplied identifier out of a rejected row.
	 *
	 * A rejected row is only actionable if the caller can find it again, and
	 * the bulk payload spells its identifier several different ways depending
	 * on which client wrote it.
	 *
	 * @param array $row The rejected row as submitted (or as transformed).
	 *
	 * @return string|null The row identifier, or null when it carried none.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private static function extractRowUuid(array $row): ?string {
		$candidate = (
			$row['@self']['id']
			?? $row['@self']['uuid']
			?? $row['id']
			?? $row['uuid']
			?? null
		);

		if (is_string($candidate) === true || is_int($candidate) === true) {
			return (string)$candidate;
		}

		return null;

	}//end extractRowUuid()
}//end class
