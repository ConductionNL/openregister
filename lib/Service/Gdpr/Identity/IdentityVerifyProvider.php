<?php

/**
 * OpenRegister Gdpr IdentityVerifyProvider
 *
 * Public contract a leaf app implements to verify a data-subject's identity for
 * a DSAR case (e.g. pipelinq NL → BSN/BRP/RvIG in Phase-3). Providers are
 * registered into {@see IdentityVerifyRegistry} at app bootstrap (ADR-019), so
 * OpenRegister core never hard-codes a jurisdiction-specific identity check: a
 * provider that is not registered cannot verify, and the case resolves the
 * OR-shipped fail-closed default instead.
 *
 * A PHP interface + registry + runtime resolution is the legitimate
 * ADR-019 / ADR-031 registry-seam exception — a schema cannot express a
 * provider contract. The verify operation returns exactly one of `verified`,
 * `failed`, or `needs-more` (see {@see IdentityVerifyResult}); a missing or
 * unavailable provider MUST NOT be treated as verified (ADR-005 / CWE-863).
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
 * A registerable identity-verification provider for a DSAR case.
 */
interface IdentityVerifyProvider
{
    /**
     * Stable provider id addressed by a `dsarPolicyPack.identityVerifyProvider`
     * selector. MUST be unique across the registry; collisions are rejected
     * first-wins at registration time.
     *
     * @return string The provider id (e.g. `or.default.identity-verify.null`).
     *
     * @spec openspec/changes/dsar-integration-seams/specs/dsar-identity-verify-seam/spec.md
     */
    public function getProviderId(): string;

    /**
     * Verify the identity of the data-subject on a DSAR case.
     *
     * The provider is handed the case's serialised payload (subject identifier,
     * subject type, jurisdiction, …) so it can decide how to verify. It MUST
     * return exactly one of the three states of {@see IdentityVerifyResult}
     * (`verified` / `failed` / `needs-more`). It MUST NOT return `verified`
     * unless the identity was positively established (fail-closed).
     *
     * @param string               $caseUuid The case object uuid.
     * @param array<string, mixed> $case     The case's serialised payload.
     *
     * @return IdentityVerifyResult The three-state verification outcome.
     *
     * @spec openspec/changes/dsar-integration-seams/specs/dsar-identity-verify-seam/spec.md
     */
    public function verify(string $caseUuid, array $case): IdentityVerifyResult;
}//end interface
