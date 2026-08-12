<?php

/**
 * OpenRegister Gdpr RegulatorEscalateResult
 *
 * Immutable outcome of escalating / dossiering a DSAR case to a supervisory
 * authority. It carries a regulator `reference` and a `status` that is exactly
 * one of `escalated` (the authority accepted the escalation and returned a
 * reference) or `refused` (no escalation was performed). The status is
 * validated in the constructor so a {@see RegulatorEscalateProvider} can never
 * silently claim success: a `refused` result explicitly reports "escalation not
 * performed" (fail-closed, ADR-005 / CWE-863).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Regulator
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

namespace OCA\OpenRegister\Service\Gdpr\Regulator;

use InvalidArgumentException;

/**
 * The outcome of escalating a DSAR case to a supervisory authority.
 */
final class RegulatorEscalateResult {

	/**
	 * The escalation was accepted by the authority (a reference was returned).
	 *
	 * @var string
	 */
	public const STATUS_ESCALATED = 'escalated';

	/**
	 * No escalation was performed (no provider bound / the provider refused).
	 * This is NOT a success — the case was not escalated.
	 *
	 * @var string
	 */
	public const STATUS_REFUSED = 'refused';

	/**
	 * The exact set of permitted statuses.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED = [
		self::STATUS_ESCALATED,
		self::STATUS_REFUSED,
	];

	/**
	 * Constructor.
	 *
	 * @param string $status One of the two permitted statuses.
	 * @param string $providerId The id of the provider that produced this result.
	 * @param string $reference The regulator reference (empty when refused).
	 * @param string|null $message Optional human-readable detail.
	 *
	 * @throws InvalidArgumentException When $status is not one of the permitted values.
	 *
	 * @spec openspec/changes/dsar-integration-seams/specs/dsar-regulator-escalate-seam/spec.md
	 */
	public function __construct(
		private readonly string $status,
		private readonly string $providerId,
		private readonly string $reference = '',
		private readonly ?string $message = null,
	) {
		if (in_array($status, self::ALLOWED, true) === false) {
			throw new InvalidArgumentException(
				sprintf(
					'RegulatorEscalateResult status "%s" is invalid; expected one of: %s',
					$status,
					implode(', ', self::ALLOWED)
				)
			);
		}
	}//end __construct()

	/**
	 * Build an escalated result carrying the authority's reference.
	 *
	 * @param string $providerId The provider that escalated.
	 * @param string $reference The regulator reference returned.
	 * @param string|null $message Optional detail.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/dsar-integration-seams/specs/dsar-regulator-escalate-seam/spec.md
	 */
	public static function escalated(string $providerId, string $reference, ?string $message = null): self {
		return new self(
			status: self::STATUS_ESCALATED,
			providerId: $providerId,
			reference: $reference,
			message: $message
		);
	}//end escalated()

	/**
	 * Build a refused result — escalation NOT performed.
	 *
	 * @param string $providerId The provider that refused.
	 * @param string|null $message Optional detail (why it was refused).
	 *
	 * @return self
	 *
	 * @spec openspec/changes/dsar-integration-seams/specs/dsar-regulator-escalate-seam/spec.md
	 */
	public static function refused(string $providerId, ?string $message = null): self {
		return new self(status: self::STATUS_REFUSED, providerId: $providerId, reference: '', message: $message);
	}//end refused()

	/**
	 * The status.
	 *
	 * @return string One of the STATUS_* constants.
	 *
	 * @spec openspec/changes/dsar-integration-seams/specs/dsar-regulator-escalate-seam/spec.md
	 */
	public function getStatus(): string {
		return $this->status;
	}//end getStatus()

	/**
	 * The id of the provider that produced this result.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/dsar-integration-seams/specs/dsar-regulator-escalate-seam/spec.md
	 */
	public function getProviderId(): string {
		return $this->providerId;
	}//end getProviderId()

	/**
	 * The regulator reference (empty string when refused).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/dsar-integration-seams/specs/dsar-regulator-escalate-seam/spec.md
	 */
	public function getReference(): string {
		return $this->reference;
	}//end getReference()

	/**
	 * Optional human-readable detail.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/dsar-integration-seams/specs/dsar-regulator-escalate-seam/spec.md
	 */
	public function getMessage(): ?string {
		return $this->message;
	}//end getMessage()

	/**
	 * Whether the case was actually escalated.
	 *
	 * @return bool True only for STATUS_ESCALATED.
	 *
	 * @spec openspec/changes/dsar-integration-seams/specs/dsar-regulator-escalate-seam/spec.md
	 */
	public function isEscalated(): bool {
		return $this->status === self::STATUS_ESCALATED;
	}//end isEscalated()

	/**
	 * Serialise for API/case persistence.
	 *
	 * @return array{status: string, provider: string, reference: string, message: string|null}
	 *
	 * @spec openspec/changes/dsar-integration-seams/specs/dsar-regulator-escalate-seam/spec.md
	 */
	public function toArray(): array {
		return [
			'status' => $this->status,
			'provider' => $this->providerId,
			'reference' => $this->reference,
			'message' => $this->message,
		];
	}//end toArray()
}//end class
