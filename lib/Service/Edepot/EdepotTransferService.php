<?php

/**
 * OpenRegister e-Depot Transfer Service
 *
 * Orchestrates the full e-Depot transfer pipeline: SIP package building,
 * transport, object status tracking, and audit trail logging.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-log-all-transfer-actions-in-the-audit-trail
 * @spec openspec/specs/edepot-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use DateTime;
use OCA\OpenRegister\Service\Edepot\Transport\TransportInterface;
use OCA\OpenRegister\Service\Edepot\Transport\TransportResult;
use Psr\Log\LoggerInterface;

/**
 * Orchestrator for e-Depot transfer operations.
 *
 * Coordinates SIP package building, transport execution, per-object status
 * tracking, audit trail logging, and notifications. One run performs a single
 * transport attempt per outstanding package; durable retry/backoff is owned by
 * TransferExecutionJob (no in-process wait).
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
 */
class EdepotTransferService implements EdepotTransferServiceInterface {

	/**
	 * Available SIP profiles.
	 *
	 * @var array<string, string>
	 */
	public const AVAILABLE_PROFILES = [
		'nationaal-archief-v2' => 'Nationaal Archief v2',
		'tresoar-v1' => 'Tresoar v1',
		'default' => 'Default MDTO Profile',
	];

	/**
	 * Constructor.
	 *
	 * @param SipPackageBuilder $sipBuilder The SIP package builder.
	 * @param TransferPipeline  $pipeline   The steps of a transfer attempt.
	 * @param LoggerInterface   $logger     Logger.
	 */
	public function __construct(
		private readonly SipPackageBuilder $sipBuilder,
		private readonly TransferPipeline $pipeline,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Execute a transfer for an approved transfer list (single attempt).
	 *
	 * Backward-compatible entry point: runs one transport attempt (attempt 1).
	 * Long-horizon cross-request retry is orchestrated by
	 * {@see \OCA\OpenRegister\BackgroundJob\TransferExecutionJob}, which calls
	 * {@see self::executeAttempt()} directly with the current attempt number.
	 *
	 * @param array<string,mixed> $transferList The approved transfer list data.
	 * @param TransportInterface  $transport    The transport to use.
	 *
	 * @return array<string,mixed> The updated transfer list with results.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 *   (Requirement: One job run performs one transport attempt per package)
	 */
	public function executeTransfer(array $transferList, TransportInterface $transport): array {
		return $this->executeAttempt(transferList: $transferList, transport: $transport, attempt: 1);
	}//end executeTransfer()

	/**
	 * Run ONE transport attempt for a transfer list.
	 *
	 * No in-process `sleep()`: exactly one transport send per outstanding
	 * package. Objects already confirmed on a prior attempt (a transferred
	 * record state) are excluded from the rebuild/resend so a retry never
	 * re-ingests them (partial-success awareness). The attempt (number,
	 * timestamp, transport, per-package outcome, error) is appended to the
	 * list's append-only `attempts[]`; the caller (the job) decides whether to
	 * re-enqueue or escalate.
	 *
	 * @param array<string,mixed> $transferList The transfer list data.
	 * @param TransportInterface  $transport    The transport to use.
	 * @param int                 $attempt      The 1-based attempt number.
	 *
	 * @return array<string,mixed> The updated transfer list (status, attempts[], transferResult).
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 *   (Requirement: One job run performs one transport attempt per package)
	 */
	public function executeAttempt(array $transferList, TransportInterface $transport, int $attempt): array {
		$run          = new TransferAttempt(number: $attempt, transport: $transport);
		$transferList = $this->begin(transferList: $transferList, run: $run);

		// Only objects NOT yet confirmed are (re)built/sent — no double ingest.
		$gatherer         = $this->pipeline->gatherer;
		$objectsWithFiles = $gatherer->gather(objectRefs: $gatherer->outstanding(transferList: $transferList));
		if (empty($objectsWithFiles) === true) {
			return $this->nothingOutstanding(transferList: $transferList, run: $run);
		}

		return $this->packageAndDeliver(transferList: $transferList, objectsWithFiles: $objectsWithFiles, run: $run);
	}//end executeAttempt()

	/**
	 * Get the transport configuration from app settings.
	 *
	 * `''` for a credential means NOT CONFIGURED: both transports refuse to
	 * send in that state, and the missing value is logged. The empty values
	 * are still returned: the settings screen has to render the unconfigured
	 * form. See {@see TransferSettings}.
	 *
	 * @return array<string,mixed> The transport configuration.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
	 */
	public function getTransportConfig(): array {
		return $this->pipeline->settings->getTransportConfig();
	}//end getTransportConfig()

	/**
	 * Get available SIP profile names.
	 *
	 * @return array<string, string> Map of profile ID to display name.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
	 */
	public function getAvailableProfiles(): array {
		return self::AVAILABLE_PROFILES;
	}//end getAvailableProfiles()

	/**
	 * Validate a SIP profile name.
	 *
	 * @param string $profileName The profile name to validate.
	 *
	 * @return bool True if valid.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
	 */
	public function isValidProfile(string $profileName): bool {
		return isset(self::AVAILABLE_PROFILES[$profileName]);
	}//end isValidProfile()

	/**
	 * Start an attempt: log it, put the list in progress and, on the first
	 * attempt, audit the initiation.
	 *
	 * @param array<string,mixed> $transferList The transfer list data.
	 * @param TransferAttempt     $run          The attempt.
	 *
	 * @return array<string,mixed> The transfer list, in progress.
	 */
	private function begin(array $transferList, TransferAttempt $run): array {
		$this->logAttempt(transferList: $transferList, run: $run);
		$transferList['status'] = TransferListService::STATUS_IN_PROGRESS;
		if ($run->number === 1) {
			$this->pipeline->auditLog->initiated(transferList: $transferList, transport: $run->transportName());
		}

		return $transferList;
	}//end begin()

	/**
	 * Log the start of an attempt.
	 *
	 * @param array<string,mixed> $transferList The transfer list data.
	 * @param TransferAttempt     $run          The attempt.
	 *
	 * @return void
	 */
	private function logAttempt(array $transferList, TransferAttempt $run): void {
		$this->logger->info(
			message: '[EdepotTransferService] Transfer attempt',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'transferId' => ($transferList['uuid'] ?? ''),
				'transport' => $run->transportName(),
				'attempt' => $run->number,
			]
		);
	}//end logAttempt()

	/**
	 * Build the packages and deliver them; a build failure fails the list.
	 *
	 * @param array<string,mixed>            $transferList     The transfer list data.
	 * @param array<int,array<string,mixed>> $objectsWithFiles The objects with files.
	 * @param TransferAttempt                $run              The attempt.
	 *
	 * @return array<string,mixed> The updated transfer list.
	 */
	private function packageAndDeliver(array $transferList, array $objectsWithFiles, TransferAttempt $run): array {
		try {
			$sipFiles = $this->buildPackages(transferList: $transferList, objectsWithFiles: $objectsWithFiles);
		} catch (\Exception $e) {
			return $this->buildFailed(transferList: $transferList, error: $e, run: $run);
		}

		return $this->deliver(transferList: $transferList, sipFiles: $sipFiles, objectsWithFiles: $objectsWithFiles, run: $run);
	}//end packageAndDeliver()

	/**
	 * Nothing outstanding: either everything already confirmed (completed)
	 * or no loadable objects (failed).
	 *
	 * @param array<string,mixed> $transferList The transfer list data.
	 * @param TransferAttempt     $run          The attempt.
	 *
	 * @return array<string,mixed> The transfer list with its terminal status and the attempt.
	 */
	private function nothingOutstanding(array $transferList, TransferAttempt $run): array {
		$transferList['status'] = $this->pipeline->gatherer->terminalStatus(transferList: $transferList);

		return $run->appendTo(transferList: $transferList, outcome: $transferList['status'], error: null);
	}//end nothingOutstanding()

	/**
	 * Build the SIP package(s) in the list's chosen format (zip default | bagit).
	 *
	 * @param array<string,mixed>            $transferList     The transfer list data.
	 * @param array<int,array<string,mixed>> $objectsWithFiles The objects with files.
	 *
	 * @return array<int, string> The package paths.
	 *
	 * @throws \Exception When a package cannot be built.
	 */
	private function buildPackages(array $transferList, array $objectsWithFiles): array {
		return $this->sipBuilder->build(
			transferId: (string)$transferList['uuid'],
			objectsWithFiles: $objectsWithFiles,
			maxPackageSize: 0,
			format: TransferContext::formatOf(transferList: $transferList)
		);
	}//end buildPackages()

	/**
	 * The package build failed: the list fails and no object is marked.
	 *
	 * @param array<string,mixed> $transferList The transfer list data.
	 * @param \Exception          $error        Why the build failed.
	 * @param TransferAttempt     $run          The attempt.
	 *
	 * @return array<string,mixed> The failed transfer list with the attempt.
	 */
	private function buildFailed(array $transferList, \Exception $error, TransferAttempt $run): array {
		$this->logger->error(
			message: '[EdepotTransferService] SIP package build failed: '.$error->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		$transferList['status']         = TransferListService::STATUS_FAILED;
		$transferList['transferResult'] = [
			'error' => 'SIP build failed: '.$error->getMessage(),
			'timestamp' => (new DateTime())->format('c'),
		];
		$this->pipeline->auditLog->failed(transferList: $transferList, error: $error->getMessage(), transport: $run->transportName());

		return $run->appendTo(transferList: $transferList, outcome: TransferListService::STATUS_FAILED, error: 'SIP build failed: '.$error->getMessage());
	}//end buildFailed()

	/**
	 * Send every package once, record each object's outcome, append the attempt and notify.
	 *
	 * @param array<string,mixed>            $transferList     The transfer list data.
	 * @param array<int, string>             $sipFiles         The package paths.
	 * @param array<int,array<string,mixed>> $objectsWithFiles The objects with files.
	 * @param TransferAttempt                $run              The attempt.
	 *
	 * @return array<string,mixed> The updated transfer list.
	 */
	private function deliver(array $transferList, array $sipFiles, array $objectsWithFiles, TransferAttempt $run): array {
		$results      = $this->sendAll(transport: $run->transport, sipFiles: $sipFiles);
		$context      = TransferContext::now(transferList: $transferList, transportName: $run->transportName());
		$transferList = $this->pipeline->processor->process(
			transferList: $transferList,
			results: $results,
			objectsWithFiles: $objectsWithFiles,
			context: $context
		);
		$error        = TransferResultProcessor::firstError(results: $results);
		$transferList = $run->appendTo(transferList: $transferList, outcome: (string)$transferList['status'], error: $error);
		$this->pipeline->auditLog->notifyCompletion(transferList: $transferList);

		return $transferList;
	}//end deliver()

	/**
	 * One transport send per package — no retry loop, no sleep. Each package
	 * file is removed once sent.
	 *
	 * @param TransportInterface $transport The transport to use.
	 * @param array<int, string> $sipFiles  The package paths.
	 *
	 * @return array<int, TransportResult> One result per package.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 *   (Requirement: One job run performs one transport attempt per package)
	 */
	private function sendAll(TransportInterface $transport, array $sipFiles): array {
		$config  = $this->getTransportConfig();
		$results = [];
		foreach ($sipFiles as $sipFile) {
			$results[] = $transport->send($sipFile, $config);
			if (file_exists($sipFile) === true) {
				unlink($sipFile);
			}
		}

		return $results;
	}//end sendAll()
}//end class
