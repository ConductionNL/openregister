<?php
/**
 * OpenRegister FederationShareService.
 *
 * Manages cross-instance federated shares: minting scoped share tokens,
 * creating OUTGOING grants (a register / schema / object / query shared with an
 * organisation on another instance), listing and revoking them, and recording
 * INCOMING grants when a remote instance shares with an organisation here.
 * The OCM trust/transport layer (ICloudFederationProvider) sits on top of this
 * service; the scoped token it mints is what the federation serving endpoint
 * (FederationController) and the remote FederatedObjectSourceProvider use.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Create, list and revoke federated shares.
 */
class FederationShareService
{

    /**
     * Recognised share scopes.
     *
     * @var string[]
     */
    private const SCOPES = ['register', 'schema', 'object', 'query'];

    /**
     * Recognised permission grants.
     *
     * @var string[]
     */
    private const PERMISSIONS = ['read', 'read-write'];

    /**
     * Constructor.
     *
     * @param FederatedShareMapper $shareMapper         Federated-share persistence.
     * @param OrganisationService  $organisationService Active-organisation resolver.
     * @param ISecureRandom        $secureRandom        Secure token generator.
     * @param LoggerInterface      $logger              Logger.
     */
    public function __construct(
        private readonly FederatedShareMapper $shareMapper,
        private readonly OrganisationService $organisationService,
        private readonly ISecureRandom $secureRandom,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create an outgoing federated share and mint its scoped token.
     *
     * @param array<string, mixed> $params Share parameters: scope, register,
     *                                     schema, objectUri, queryFilter,
     *                                     sharedWith (slug@host), permissions,
     *                                     remoteInstanceUrl.
     *
     * @return FederatedShare The persisted share (carrying the minted token).
     *
     * @throws \InvalidArgumentException When scope/permissions are invalid.
     */
    public function createOutgoingShare(array $params): FederatedShare
    {
        $scope = (string) ($params['scope'] ?? 'schema');
        if (in_array($scope, self::SCOPES, true) === false) {
            throw new InvalidArgumentException('Invalid share scope: '.$scope);
        }

        $permissions = (string) ($params['permissions'] ?? 'read');
        if (in_array($permissions, self::PERMISSIONS, true) === false) {
            throw new InvalidArgumentException('Invalid permissions: '.$permissions);
        }

        $organisation = $this->activeOrganisationUuid();

        $data = [
            'direction'         => 'outgoing',
            'shareToken'        => $this->mintToken(),
            'scope'             => $scope,
            'register'          => ($params['register'] ?? null),
            'schema'            => ($params['schema'] ?? null),
            'objectUri'         => ($params['objectUri'] ?? null),
            'queryFilter'       => ($params['queryFilter'] ?? null),
            'permissions'       => $permissions,
            'organisation'      => $organisation,
            'sharedWith'        => ($params['sharedWith'] ?? null),
            'remoteInstanceUrl' => ($params['remoteInstanceUrl'] ?? null),
            // Outgoing token shares are immediately usable; an OCM share moves to
            // 'accepted' once the remote confirms, but the local grant stands.
            'status'            => 'accepted',
        ];

        return $this->shareMapper->createFromArray(data: $data);
    }//end createOutgoingShare()

    /**
     * Record an incoming federated share (a remote shared with an org here).
     *
     * @param array<string, mixed> $params Share parameters: remoteInstanceUrl,
     *                                     shareToken, scope, register, schema,
     *                                     objectUri, permissions, sharedWith,
     *                                     remoteProviderId.
     *
     * @return FederatedShare The persisted incoming share (status 'pending').
     */
    public function recordIncomingShare(array $params): FederatedShare
    {
        $data = [
            'direction'         => 'incoming',
            'remoteInstanceUrl' => ($params['remoteInstanceUrl'] ?? null),
            'remoteProviderId'  => ($params['remoteProviderId'] ?? null),
            'shareToken'        => (string) ($params['shareToken'] ?? $this->mintToken()),
            'scope'             => (string) ($params['scope'] ?? 'schema'),
            'register'          => ($params['register'] ?? null),
            'schema'            => ($params['schema'] ?? null),
            'objectUri'         => ($params['objectUri'] ?? null),
            'permissions'       => (string) ($params['permissions'] ?? 'read'),
            'organisation'      => $this->activeOrganisationUuid(),
            'sharedWith'        => ($params['sharedWith'] ?? null),
            'status'            => 'pending',
        ];

        return $this->shareMapper->createFromArray(data: $data);
    }//end recordIncomingShare()

    /**
     * Ensure an outgoing object-scope share exists for a uri + target.
     *
     * Idempotent: returns the existing share when one is already present (so the
     * federate-share flow action can fire on every save without duplicating).
     *
     * @param string      $objectUri   The shared object's uri/uuid.
     * @param string|null $register    The register id/slug.
     * @param string|null $schema      The schema id/slug.
     * @param string      $sharedWith  The federated target (slug@host).
     * @param string      $permissions 'read' or 'read-write'.
     *
     * @return FederatedShare The existing or newly created share.
     */
    public function ensureObjectShare(
        string $objectUri,
        ?string $register,
        ?string $schema,
        string $sharedWith,
        string $permissions='read'
    ): FederatedShare {
        $existing = $this->shareMapper->findOutgoingObjectShare(objectUri: $objectUri, sharedWith: $sharedWith);
        if ($existing !== null) {
            return $existing;
        }

        return $this->createOutgoingShare(
            params: [
                'scope'       => 'object',
                'register'    => $register,
                'schema'      => $schema,
                'objectUri'   => $objectUri,
                'sharedWith'  => $sharedWith,
                'permissions' => $permissions,
            ]
        );
    }//end ensureObjectShare()

    /**
     * List federated shares, optionally by direction.
     *
     * @param string|null $direction 'outgoing' | 'incoming' | null for all.
     *
     * @return FederatedShare[] The shares for the active organisation.
     */
    public function listShares(?string $direction=null): array
    {
        $filters = [];
        if ($direction !== null && $direction !== '') {
            $filters['direction'] = $direction;
        }

        return $this->shareMapper->findAll(limit: null, offset: null, filters: $filters);
    }//end listShares()

    /**
     * Set the status of a share (e.g. accept, decline, revoke).
     *
     * @param int    $id     The share id.
     * @param string $status The new status.
     *
     * @return FederatedShare The updated share.
     */
    public function setStatus(int $id, string $status): FederatedShare
    {
        return $this->shareMapper->updateFromArray(id: $id, data: ['status' => $status]);
    }//end setStatus()

    /**
     * Resolve the active organisation UUID (null when none).
     *
     * @return string|null The active organisation UUID.
     */
    private function activeOrganisationUuid(): ?string
    {
        try {
            $organisation = $this->organisationService->getActiveOrganisation();
            if ($organisation !== null) {
                return $organisation->getUuid();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[Federation] could not resolve active organisation: '.$e->getMessage());
        }

        return null;
    }//end activeOrganisationUuid()

    /**
     * Mint a scoped bearer share token.
     *
     * @return string A 48-char alphanumeric token.
     */
    private function mintToken(): string
    {
        return $this->secureRandom->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);
    }//end mintToken()
}//end class
