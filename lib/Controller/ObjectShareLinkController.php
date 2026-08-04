<?php

/**
 * Public entry point for an object share LINK or EMAIL invitation.
 *
 * A link is a CAPABILITY, not a principal grant. Nobody is logged in, so there
 * is no principal for RBAC to resolve — the TOKEN is the authorization, and core
 * is what validates it. That is exactly why link and email types are absent from
 * {@see \OCA\OpenRegister\Service\Rbac\ObjectGrantResolver}'s principal list:
 * they are decided here instead of in the RBAC filter.
 *
 * WHAT MAKES THIS SAFE. Every check below is core's, not a reimplementation:
 *
 *  1. `getShareByToken()` resolves ONLY a live share. A revoked one is gone and
 *     an expired one is refused, so revocation and expiry take effect on the
 *     next request with nothing for OpenRegister to invalidate.
 *  2. A password-protected share is refused until core says the supplied
 *     password matches — we never compare it ourselves.
 *  3. The share must be a LINK or EMAIL type on a FOLDER whose name is the
 *     object's UUID. A share of anything else grants no object.
 *  4. The object is then loaded with RBAC OFF, because there is no principal to
 *     check it against and the token has already answered the question. This is
 *     the one place in this capability that bypasses the filter, and it is
 *     reachable only with a live token that core just validated.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It does not write, and it does not enumerate:
 * a token addresses exactly one object, and there is no listing endpoint here. A
 * link that carries write permission still cannot write through this controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/share-links-and-email-invites/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves an object share token to the one object it addresses.
 */
class ObjectShareLinkController extends Controller
{

    /**
     * Share types this endpoint will honour.
     *
     * Only the two bearer-capability types. A USER or GROUP share names a
     * principal and is decided by the RBAC filter for a logged-in caller; it
     * must not also be redeemable as an anonymous token.
     *
     * @var int[]
     */
    private const TOKEN_SHARE_TYPES = [
        IShare::TYPE_LINK,
        IShare::TYPE_EMAIL,
    ];

    /**
     * Constructor.
     *
     * @param string          $appName       App name.
     * @param IRequest        $request       Request.
     * @param IManager        $shareManager  Core share manager — validates the token.
     * @param ObjectService   $objectService Loads the addressed object.
     * @param LoggerInterface $logger        Logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IManager $shareManager,
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Resolve a share token to its object.
     *
     * @param string $token The share token.
     *
     * @return JSONResponse The object, or a refusal.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function show(string $token): JSONResponse
    {
        $share = $this->resolveLiveShare(token: $token);
        if ($share === null) {
            // One shape for every refusal — invalid, revoked, expired, wrong
            // type, wrong password. Distinguishing them would turn this into an
            // oracle for which tokens exist.
            return $this->refused();
        }

        $uuid = $this->objectUuidOf(share: $share);
        if ($uuid === null) {
            return $this->refused();
        }

        try {
            // RBAC off: there is no principal to evaluate, and the token that
            // core just validated IS the authorization. Multitenancy off for the
            // same reason — an anonymous caller belongs to no organisation.
            $object = $this->objectService->find(
                id: $uuid,
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[ObjectShareLink] Could not load the object a live token addressed',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            return $this->refused();
        }

        if ($object === null) {
            return $this->refused();
        }

        return new JSONResponse(
            [
                'object'      => $object->jsonSerialize(),
                'permissions' => $share->getPermissions(),
            ]
        );
    }//end show()

    /**
     * Resolve a token to a live, permitted share, or null.
     *
     * @param string $token The share token.
     *
     * @return IShare|null The share, or null when it must not be honoured.
     */
    private function resolveLiveShare(string $token): ?IShare
    {
        if (trim($token) === '') {
            return null;
        }

        try {
            // Throws for an unknown token, and for one whose share has expired
            // or been revoked — so expiry and revocation need no handling here.
            $share = $this->shareManager->getShareByToken($token);
        } catch (Throwable $e) {
            return null;
        }

        if (in_array($share->getShareType(), self::TOKEN_SHARE_TYPES, true) === false) {
            return null;
        }

        if ($this->passwordSatisfied(share: $share) === false) {
            return null;
        }

        return $share;
    }//end resolveLiveShare()

    /**
     * Whether a password-protected share has been unlocked.
     *
     * The comparison is core's `checkPassword()`, never a local one — it is what
     * knows the hashing scheme and applies the brute-force protection.
     *
     * @param IShare $share The share.
     *
     * @return bool True when the share needs no password, or the supplied one matches.
     */
    private function passwordSatisfied(IShare $share): bool
    {
        if ($share->getPassword() === null) {
            return true;
        }

        $supplied = $this->request->getParam('password');
        if (is_string($supplied) === false || $supplied === '') {
            return false;
        }

        try {
            return $this->shareManager->checkPassword($share, $supplied);
        } catch (Throwable $e) {
            return false;
        }
    }//end passwordSatisfied()

    /**
     * The object UUID a share addresses, or null when it addresses none.
     *
     * An object's folder is named after its UUID. A share of a FILE inside that
     * folder is a file share and grants no object — the same rule the grant
     * resolver applies, kept identical here on purpose.
     *
     * @param IShare $share The share.
     *
     * @return string|null The object UUID.
     */
    private function objectUuidOf(IShare $share): ?string
    {
        try {
            if ($share->getNodeType() !== 'folder') {
                return null;
            }

            $name = $share->getNode()->getName();
        } catch (Throwable $e) {
            return null;
        }

        $uuidPattern = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';
        if ($name === '' || preg_match($uuidPattern, $name) !== 1) {
            return null;
        }

        return $name;
    }//end objectUuidOf()

    /**
     * The single refusal shape.
     *
     * @return JSONResponse A 404.
     */
    private function refused(): JSONResponse
    {
        return new JSONResponse(
            ['message' => 'No such share'],
            Http::STATUS_NOT_FOUND
        );
    }//end refused()
}//end class
