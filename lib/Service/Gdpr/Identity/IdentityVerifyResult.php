<?php

/**
 * OpenRegister Gdpr IdentityVerifyResult
 *
 * Immutable three-state outcome of an identity-verification attempt for a DSAR
 * case: exactly one of `verified`, `failed`, or `needs-more`. The status is
 * validated in the constructor so an {@see IdentityVerifyProvider} can never
 * return a fourth, ambiguous state — a case is either verified or it is not
 * (fail-closed, ADR-005 / CWE-863).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Identity
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

namespace OCA\OpenRegister\Service\Gdpr\Identity;

use InvalidArgumentException;

/**
 * The three-state result of verifying a data-subject's identity.
 */
final class IdentityVerifyResult
{

    /**
     * Identity was positively verified.
     *
     * @var string
     */
    public const STATUS_VERIFIED = 'verified';

    /**
     * Identity verification was attempted and failed (positively not verified).
     *
     * @var string
     */
    public const STATUS_FAILED = 'failed';

    /**
     * Verification is incomplete — more information/steps are required. This is
     * an UNVERIFIED state: a case MUST NOT proceed as if identity were proven.
     *
     * @var string
     */
    public const STATUS_NEEDS_MORE = 'needs-more';

    /**
     * The exact set of permitted statuses.
     *
     * @var array<int, string>
     */
    private const ALLOWED = [
        self::STATUS_VERIFIED,
        self::STATUS_FAILED,
        self::STATUS_NEEDS_MORE,
    ];

    /**
     * Constructor.
     *
     * @param string      $status     One of the three permitted statuses.
     * @param string      $providerId The id of the provider that produced this result.
     * @param string|null $message    Optional human-readable detail.
     *
     * @throws InvalidArgumentException When $status is not one of the three permitted values.
     *
     * @spec openspec/changes/dsar-integration-seams/specs/dsar-identity-verify-seam/spec.md
     */
    public function __construct(
        private readonly string $status,
        private readonly string $providerId,
        private readonly ?string $message=null
    ) {
        if (in_array($status, self::ALLOWED, true) === false) {
            throw new InvalidArgumentException(
                sprintf(
                    'IdentityVerifyResult status "%s" is invalid; expected one of: %s',
                    $status,
                    implode(', ', self::ALLOWED)
                )
            );
        }
    }//end __construct()

    /**
     * Build a positively-verified result.
     *
     * @param string      $providerId The provider that verified the subject.
     * @param string|null $message    Optional detail.
     *
     * @return self
     *
     * @spec openspec/changes/dsar-integration-seams/specs/dsar-identity-verify-seam/spec.md
     */
    public static function verified(string $providerId, ?string $message=null): self
    {
        return new self(status: self::STATUS_VERIFIED, providerId: $providerId, message: $message);
    }//end verified()

    /**
     * Build a failed (not-verified) result.
     *
     * @param string      $providerId The provider that produced the result.
     * @param string|null $message    Optional detail.
     *
     * @return self
     *
     * @spec openspec/changes/dsar-integration-seams/specs/dsar-identity-verify-seam/spec.md
     */
    public static function failed(string $providerId, ?string $message=null): self
    {
        return new self(status: self::STATUS_FAILED, providerId: $providerId, message: $message);
    }//end failed()

    /**
     * Build a needs-more (incomplete, not-verified) result.
     *
     * @param string      $providerId The provider that produced the result.
     * @param string|null $message    Optional detail.
     *
     * @return self
     *
     * @spec openspec/changes/dsar-integration-seams/specs/dsar-identity-verify-seam/spec.md
     */
    public static function needsMore(string $providerId, ?string $message=null): self
    {
        return new self(status: self::STATUS_NEEDS_MORE, providerId: $providerId, message: $message);
    }//end needsMore()

    /**
     * The three-state status.
     *
     * @return string One of the STATUS_* constants.
     *
     * @spec openspec/changes/dsar-integration-seams/specs/dsar-identity-verify-seam/spec.md
     */
    public function getStatus(): string
    {
        return $this->status;
    }//end getStatus()

    /**
     * The id of the provider that produced this result.
     *
     * @return string
     *
     * @spec openspec/changes/dsar-integration-seams/specs/dsar-identity-verify-seam/spec.md
     */
    public function getProviderId(): string
    {
        return $this->providerId;
    }//end getProviderId()

    /**
     * Optional human-readable detail.
     *
     * @return string|null
     *
     * @spec openspec/changes/dsar-integration-seams/specs/dsar-identity-verify-seam/spec.md
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }//end getMessage()

    /**
     * Whether the subject was positively verified.
     *
     * The ONLY status that means "proceed as verified" — `failed` and
     * `needs-more` are both unverified (fail-closed).
     *
     * @return bool True only for STATUS_VERIFIED.
     *
     * @spec openspec/changes/dsar-integration-seams/specs/dsar-identity-verify-seam/spec.md
     */
    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }//end isVerified()

    /**
     * Serialise for API/case persistence.
     *
     * @return array{status: string, provider: string, message: string|null}
     *
     * @spec openspec/changes/dsar-integration-seams/specs/dsar-identity-verify-seam/spec.md
     */
    public function toArray(): array
    {
        return [
            'status'   => $this->status,
            'provider' => $this->providerId,
            'message'  => $this->message,
        ];
    }//end toArray()
}//end class
