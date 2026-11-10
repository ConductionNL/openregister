<?php

/**
 * OpenRegister e-Depot Transfer Result Processor
 *
 * Turns the transport results of one attempt into per-object outcomes and the
 * transfer list's status.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use OCA\OpenRegister\Service\Edepot\Transport\TransportResult;

/**
 * Processes transport results.
 *
 * Only an object whose result says `accepted: true` is recorded as
 * transferred; an object missing from the results is refused. A transport
 * that reports no per-object results decides for every object at once.
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
 */
final class TransferResultProcessor {

	/**
	 * The result of an object the transport did not report on.
	 *
	 * @var array{accepted: bool, reference: null, error: string}
	 */
	private const NO_RESULT = ['accepted' => false, 'reference' => null, 'error' => 'No result'];

	/**
	 * Constructor.
	 *
	 * @param TransferOutcomeRecorder $recorder Records each object's outcome.
	 * @param TransferAuditLog        $auditLog The transfer audit log.
	 */
	public function __construct(
		private readonly TransferOutcomeRecorder $recorder,
		private readonly TransferAuditLog $auditLog,
	) {
	}//end __construct()

	/**
	 * Record every object's outcome and set the list's status and transferResult.
	 *
	 * @param array<string,mixed> $transferList The transfer list.
	 * @param TransferDelivery    $delivery     The results, the objects sent and the delivery.
	 *
	 * @return array<string,mixed> Updated transfer list.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
	 *   (Scenario: Proof created on confirmed transfer)
	 */
	public function process(array $transferList, TransferDelivery $delivery): array {
		$merged = self::mergedResults(results: $delivery->results, objectsWithFiles: $delivery->objectsWithFiles);

		$transferList['status'] = $this->applyOutcomes(delivery: $delivery, merged: $merged);
		if ($transferList['status'] === TransferListService::STATUS_FAILED) {
			$failure = new TransferFailure(error: implode('; ', self::errorsOf(results: $delivery->results)), transport: '');
			$this->auditLog->failed(transferList: $transferList, failure: $failure);
		}

		$transferList['transferResult'] = [
			'completedAt' => $delivery->context->confirmedAt,
			'objectResults' => $merged,
		];

		return $transferList;
	}//end process()

	/**
	 * The first error message among the results, or null.
	 *
	 * @param array<int,TransportResult> $results Transport results.
	 *
	 * @return string|null The error.
	 */
	public static function firstError(array $results): ?string {
		return (self::errorsOf(results: $results)[0] ?? null);
	}//end firstError()

	/**
	 * Record each object's outcome and derive the list's status from them.
	 *
	 * @param TransferDelivery                    $delivery The objects sent and the delivery.
	 * @param array<string, array<string, mixed>> $merged   Per-object results by uuid.
	 *
	 * @return string The status.
	 */
	private function applyOutcomes(TransferDelivery $delivery, array $merged): string {
		$context    = $delivery->context;
		$allSuccess = true;
		$anySuccess = false;
		foreach ($delivery->objectsWithFiles as $item) {
			$objResult = ($merged[$item['object']->getUuid()] ?? self::NO_RESULT);
			if ($objResult['accepted'] === true) {
				$anySuccess = true;
				$this->recorder->recordAccepted(item: $item, objResult: $objResult, context: $context);
				continue;
			}

			$allSuccess = false;
			$this->recorder->recordRefused(object: $item['object'], error: ($objResult['error'] ?? 'Unknown error'), timestamp: $context->confirmedAt);
		}

		return self::statusOf(allSuccess: $allSuccess, anySuccess: $anySuccess);
	}//end applyOutcomes()

	/**
	 * The list status for an attempt's outcomes.
	 *
	 * @param bool $allSuccess Whether every object was accepted.
	 * @param bool $anySuccess Whether any object was accepted.
	 *
	 * @return string The status.
	 */
	private static function statusOf(bool $allSuccess, bool $anySuccess): string {
		return match (true) {
			$allSuccess === true => TransferListService::STATUS_COMPLETED,
			$anySuccess === true => TransferListService::STATUS_PARTIALLY_FAILED,
			default => TransferListService::STATUS_FAILED,
		};
	}//end statusOf()

	/**
	 * Every per-object result, or — when the transport reported none — the
	 * overall outcome for every object.
	 *
	 * @param array<int,TransportResult>     $results          Transport results.
	 * @param array<int,array<string,mixed>> $objectsWithFiles The objects with files.
	 *
	 * @return array<string, array<string, mixed>> Per-object results by uuid.
	 */
	private static function mergedResults(array $results, array $objectsWithFiles): array {
		$merged = [];
		foreach ($results as $result) {
			foreach ($result->getObjectResults() as $uuid => $objResult) {
				$merged[$uuid] = $objResult;
			}
		}

		if (empty($merged) === false) {
			return $merged;
		}

		return self::overallResults(results: $results, objectsWithFiles: $objectsWithFiles);
	}//end mergedResults()

	/**
	 * The overall outcome for every object, from a transport that reported none per object.
	 *
	 * @param array<int,TransportResult>     $results          Transport results.
	 * @param array<int,array<string,mixed>> $objectsWithFiles The objects with files.
	 *
	 * @return array<string, array<string, mixed>> Per-object results by uuid.
	 */
	private static function overallResults(array $results, array $objectsWithFiles): array {
		$merged  = [];
		$success = self::allSucceeded(results: $results);
		foreach ($objectsWithFiles as $item) {
			$merged[$item['object']->getUuid()] = self::overallResult(results: $results, success: $success);
		}

		return $merged;
	}//end overallResults()

	/**
	 * The result every object gets from a transport without per-object results.
	 *
	 * @param array<int,TransportResult> $results Transport results.
	 * @param bool                       $success Whether every send succeeded.
	 *
	 * @return array{accepted: bool, reference: string|null, error: string|null} The result.
	 */
	private static function overallResult(array $results, bool $success): array {
		$reference = ($results[0]->getTransferReference() ?? '');
		if ($success === true) {
			return ['accepted' => true, 'reference' => $reference, 'error' => null];
		}

		return ['accepted' => false, 'reference' => null, 'error' => ($results[0]->getErrorMessage() ?? 'Transfer failed')];
	}//end overallResult()

	/**
	 * Whether every send succeeded.
	 *
	 * @param array<int,TransportResult> $results Transport results.
	 *
	 * @return bool True when none failed.
	 */
	private static function allSucceeded(array $results): bool {
		foreach ($results as $result) {
			if ($result->isSuccess() === false) {
				return false;
			}
		}

		return true;
	}//end allSucceeded()

	/**
	 * The error messages of the results, in order.
	 *
	 * @param array<int,TransportResult> $results Transport results.
	 *
	 * @return list<string> The messages.
	 */
	private static function errorsOf(array $results): array {
		$errors = [];
		foreach ($results as $result) {
			if ($result->getErrorMessage() !== null) {
				$errors[] = $result->getErrorMessage();
			}
		}

		return $errors;
	}//end errorsOf()
}//end class
