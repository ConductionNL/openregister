<?php

/**
 * OpenRegister Gdpr OneTimeDownloadTokenStore
 *
 * Mints, redeems and burns single-use, time-boxed download tokens for a
 * generated export bundle. The token is a security credential minted at
 * bundle-generation time and burned on first successful download so it cannot
 * be replayed; every token is bound to a specific case uuid so the download
 * endpoint can enforce case scope on redemption.
 *
 * The store is app-config-backed (per the design's Open Question: a short-lived
 * app-config-backed store is acceptable; the security posture — single-use,
 * burned on first use, time-boxed, case-scoped — is fixed regardless of the
 * concrete backing). Only a SHA-256 of the token is persisted, never the raw
 * token, so a config leak cannot reconstruct a usable credential.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Export
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

namespace OCA\OpenRegister\Service\Gdpr\Export;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;

/**
 * Single-use, time-boxed, case-scoped download-token store.
 */
class OneTimeDownloadTokenStore
{

    /**
     * App id for app-config storage.
     *
     * @var string
     */
    private const APP_ID = 'openregister';

    /**
     * App-config key prefix for stored token records.
     *
     * @var string
     */
    private const KEY_PREFIX = 'dsar_bundle_token_';

    /**
     * Default token lifetime in seconds (15 minutes).
     *
     * @var int
     */
    public const DEFAULT_TTL_SECONDS = 900;

    /**
     * Constructor.
     *
     * @param IAppConfig    $appConfig App-config store for token records.
     * @param ISecureRandom $random    CSPRNG for token generation.
     * @param ITimeFactory  $time      Time source for expiry checks.
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly ISecureRandom $random,
        private readonly ITimeFactory $time
    ) {
    }//end __construct()

    /**
     * Mint a single-use token bound to a case, returning the RAW token.
     *
     * Only the token's SHA-256 is persisted (with the case uuid + expiry); the
     * raw token is returned to the caller once and never stored.
     *
     * @param string $caseUuid   The case the token authorises a download for.
     * @param int    $ttlSeconds Token lifetime in seconds.
     *
     * @return string The raw one-time token.
     *
     * @spec openspec/changes/dsar-case-engine/specs/dsar-export-bundle/spec.md
     */
    public function mint(string $caseUuid, int $ttlSeconds=self::DEFAULT_TTL_SECONDS): string
    {
        $token   = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
        $tokenId = hash(algo: 'sha256', data: $token);
        $expiry  = ($this->time->getTime() + $ttlSeconds);

        $this->appConfig->setValueString(
            app: self::APP_ID,
            key: self::KEY_PREFIX.$tokenId,
            value: json_encode(
                [
                    'caseUuid' => $caseUuid,
                    'expiry'   => $expiry,
                ]
            )
        );

        return $token;
    }//end mint()

    /**
     * Redeem a token for a case: verify + BURN it in one step.
     *
     * Returns true only when the token exists, is bound to the given case, and
     * has not expired — and the token is deleted before returning, so a second
     * redemption of the same token is refused. An expired token is also burned.
     * The check fails closed: any malformed/absent/mismatched record denies.
     *
     * @param string $token    The raw token presented at the download endpoint.
     * @param string $caseUuid The case the download is scoped to.
     *
     * @return bool True when the token was valid for this case (and is now burned).
     *
     * @spec openspec/changes/dsar-case-engine/specs/dsar-export-bundle/spec.md
     */
    public function redeem(string $token, string $caseUuid): bool
    {
        if ($token === '') {
            return false;
        }

        $tokenId = hash(algo: 'sha256', data: $token);
        $key     = self::KEY_PREFIX.$tokenId;

        $raw = $this->appConfig->getValueString(
            app: self::APP_ID,
            key: $key,
            default: ''
        );

        if ($raw === '') {
            return false;
        }

        // Burn on first sight: whether valid or expired, the token is single
        // use. Deleting before the validity verdict guarantees no replay.
        $this->appConfig->deleteKey(app: self::APP_ID, key: $key);

        $record = json_decode($raw, true);
        if (is_array($record) === false) {
            return false;
        }

        $recordCase = (string) ($record['caseUuid'] ?? '');
        $expiry     = (int) ($record['expiry'] ?? 0);

        if ($recordCase === '' || $recordCase !== $caseUuid) {
            return false;
        }

        if ($expiry < $this->time->getTime()) {
            return false;
        }

        return true;
    }//end redeem()
}//end class
