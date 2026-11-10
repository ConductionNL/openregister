<?php

/**
 * OpenRegister e-Depot Transport Result
 *
 * Value object representing the result of a SIP transport operation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot\Transport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot\Transport;

/**
 * Result of a SIP transport operation.
 *
 * Contains the overall success/failure status, per-object acceptance results,
 * and any error details from the e-Depot system.
 *
 * @psalm-suppress UnusedClass
 */
class TransportResult {

	/**
	 * Whether the transport succeeded overall.
	 *
	 * @var boolean
	 */
	private bool $success = false;

	/**
	 * Per-object results mapping UUID to acceptance status.
	 *
	 * @var array<string, array{accepted: bool, reference: string|null, error: string|null}>
	 */
	private array $objectResults = [];

	/**
	 * Error message if the transport failed entirely.
	 *
	 * @var string|null
	 */
	private ?string $errorMessage = null;

	/**
	 * The e-Depot's reference identifier for the transfer.
	 *
	 * @var string|null
	 */
	private ?string $transferReference = null;

	/**
	 * A transfer the e-Depot took: successful unless it rejected an object.
	 * Use failed() for a transfer that failed as a whole.
	 *
	 * @param string|null                                                                       $transferReference The e-Depot's reference.
	 * @param array<string, array{accepted: bool, reference: string|null, error: string|null}> $objectResults     Per-object results.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md
	 */
	public function __construct(?string $transferReference = null, array $objectResults = []) {
		$this->transferReference = $transferReference;
		$this->objectResults = $objectResults;
		$this->success = $this->hasRejections() === false;
	}//end __construct()

	/**
	 * This result as a transfer that failed as a whole.
	 *
	 * @param string $errorMessage Why it failed.
	 *
	 * @return self A failed copy.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md
	 */
	public function failed(string $errorMessage): self {
		$result = clone $this;
		$result->success = false;
		$result->errorMessage = $errorMessage;

		return $result;
	}//end failed()

	/**
	 * Whether the e-Depot rejected at least one object.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md
	 */
	public function hasRejections(): bool {
		return count($this->withAcceptance(accepted: false)) > 0;
	}//end hasRejections()

	/**
	 * The per-object results with the given acceptance.
	 *
	 * @param bool $accepted The acceptance to match.
	 *
	 * @return array<string, array{accepted: bool, reference: string|null, error: string|null}>
	 */
	private function withAcceptance(bool $accepted): array {
		return array_filter($this->objectResults, static fn (array $result): bool => $result['accepted'] === $accepted);
	}//end withAcceptance()

	/**
	 * Check if the transport succeeded.
	 *
	 * @return bool True if successful.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	public function isSuccess(): bool {
		return $this->success;
	}//end isSuccess()

	/**
	 * Check if the result is a partial success (some objects accepted, some rejected).
	 *
	 * @return bool True if partially successful.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	public function isPartialSuccess(): bool {
		if ($this->success === true) {
			return false;
		}

		return count($this->withAcceptance(accepted: true)) > 0 && $this->hasRejections() === true;
	}//end isPartialSuccess()

	/**
	 * Get per-object results.
	 *
	 * @return array<string, array{accepted: bool, reference: string|null, error: string|null}> Object results.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	public function getObjectResults(): array {
		return $this->objectResults;
	}//end getObjectResults()

	/**
	 * Get the error message.
	 *
	 * @return string|null The error message.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	public function getErrorMessage(): ?string {
		return $this->errorMessage;
	}//end getErrorMessage()

	/**
	 * Get the transfer reference.
	 *
	 * @return string|null The e-Depot transfer reference.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md
	 */
	public function getTransferReference(): ?string {
		return $this->transferReference;
	}//end getTransferReference()

	/**
	 * Serialize to array.
	 *
	 * @return array<string,mixed> Serialized result.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	public function toArray(): array {
		return [
			'success' => $this->success,
			'objectResults' => $this->objectResults,
			'errorMessage' => $this->errorMessage,
			'transferReference' => $this->transferReference,
		];
	}//end toArray()
}//end class
