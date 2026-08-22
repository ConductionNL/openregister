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

use OCA\OpenRegister\Service\Object\BatchOperationStatus;

/**
 * Per-object failure summary for one bulk save call.
 *
 * @category Dto
 * @package  OCA\OpenRegister\Dto
 */
class BulkSaveOutcome {

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
		$failures = [];
		$invalid = ($bulkResult['invalid'] ?? []);
		if (is_array($invalid) === true) {
			foreach ($invalid as $entry) {
				if (is_array($entry) === false) {
					continue;
				}

				$row = ($entry['object'] ?? []);
				if (is_array($row) === false) {
					$row = [];
				}

				$index = null;
				if (isset($entry['index']) === true && is_numeric($entry['index']) === true) {
					$index = (int)$entry['index'];
				}

				$failures[] = [
					'index' => $index,
					'uuid' => self::extractRowUuid(row: $row),
					'error' => (string)($entry['error'] ?? 'Object was rejected without a recorded reason'),
					'type' => (string)($entry['type'] ?? 'BulkSaveRejection'),
				];
			}//end foreach
		}//end if

		$rejected = count($failures);
		$unaccounted = max(0, ($requestedCount - $accountedCount - $rejected));
		if ($unaccounted > 0) {
			$failures[] = [
				'index' => null,
				'uuid' => null,
				'error' => sprintf(
					'%d object(s) were not written and the save path recorded no per-object reason.%s',
					$unaccounted,
					self::describeUnattributedErrors(bulkResult: $bulkResult)
				),
				'type' => 'UnaccountedObject',
			];
		}

		return new self(failedCount: ($rejected + $unaccounted), failures: $failures);

	}//end fromBulkResult()

	/**
	 * Summarise a streaming batch status into the same shape.
	 *
	 * The streaming path already derived its `success` from the failed count,
	 * but reported the detail under its own key layout, so a caller had to know
	 * which path it had asked for before it could read the answer.
	 *
	 * @param BatchOperationStatus $status Per-row outcomes from the streaming save.
	 * @param int $requestedCount Rows the caller submitted.
	 * @param int $accountedCount Rows the status accounts for (created + updated + unchanged).
	 *
	 * @return self The summarised outcome.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public static function fromBatchStatus(
		BatchOperationStatus $status,
		int $requestedCount,
		int $accountedCount
	): self {
		$failures = [];
		foreach ($status->getFailed() as $failure) {
			$failures[] = [
				'index' => ($failure['index'] ?? null),
				'uuid' => ($failure['uuid'] ?? null),
				// No `??` fallbacks on these two: getFailed() declares both
				// `message` and `exceptionClass` as required, non-nullable
				// strings, so neither default could ever be reached. `index` and
				// `uuid` above ARE nullable and keep theirs.
				'error' => (string)$failure['message'],
				'type' => (string)$failure['exceptionClass'],
			];
		}

		$unaccounted = max(0, ($requestedCount - $accountedCount - count($failures)));
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

	}//end fromBatchStatus()

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
