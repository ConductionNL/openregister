<?php

/**
 * OpenRegister Gdpr NullIdentityVerifyProvider
 *
 * The OR-shipped fail-closed default {@see IdentityVerifyProvider}. Registered
 * at bootstrap under the id the default policy pack selects
 * (`or.default.identity-verify.null`), it is the provider a case resolves to
 * whenever the active pack's `identityVerifyProvider` selector is unset or names
 * an unregistered provider.
 *
 * It NEVER auto-verifies: `verify()` always returns a `needs-more`
 * (unverified) result. This encodes the ADR-005 / CWE-863 security invariant in
 * code — an unbound identity seam MUST fail closed, so a fresh install (with no
 * leaf identity provider) can never let a case pass identity verification.
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

/**
 * Fail-closed default identity-verify provider: never verifies.
 */
final class NullIdentityVerifyProvider implements IdentityVerifyProvider
{

    /**
     * The stable id the default policy pack binds for this fail-closed provider.
     *
     * @var string
     */
    public const PROVIDER_ID = 'or.default.identity-verify.null';

    /**
     * {@inheritDoc}
     *
     * @return string The fail-closed default provider id.
     *
     * @spec openspec/changes/dsar-integration-seams/specs/dsar-identity-verify-seam/spec.md
     */
    public function getProviderId(): string
    {
        return self::PROVIDER_ID;
    }//end getProviderId()

    /**
     * Always returns `needs-more` — the subject is never auto-verified.
     *
     * No leaf identity provider is bound, so there is no way to establish the
     * subject's identity; the only safe answer is "not verified, more needed".
     *
     * @param string               $caseUuid The case object uuid.
     * @param array<string, mixed> $case     The case's serialised payload (unused).
     *
     * @return IdentityVerifyResult Always an unverified (`needs-more`) result.
     *
     * @spec openspec/changes/dsar-integration-seams/specs/dsar-identity-verify-seam/spec.md
     *
     * @SuppressWarnings(PHPMD.StaticAccess) IdentityVerifyResult::needsMore is a named constructor — no DI alternative.
     */
    public function verify(string $caseUuid, array $case): IdentityVerifyResult
    {
        return IdentityVerifyResult::needsMore(
            providerId: self::PROVIDER_ID,
            message: 'No identity-verify provider is bound; identity cannot be verified (fail-closed default).'
        );
    }//end verify()
}//end class
