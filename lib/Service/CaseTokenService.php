<?php

/**
 * CaseTokenService — mint / resolve / revoke public "track your case"
 * links to an OpenRegister object.
 *
 * This is the backend behind the Shares integration provider's public
 * token-link surface (AD-22 create()/delete()). A leaf app (procest)
 * mints a token bound to an object; the token resolves anonymously to a
 * PUBLIC-SAFE view of that object — RBAC-respecting, NOT a bypass.
 *
 * RBAC contract (ADR-005, fail-closed):
 *   - mint requires a logged-in user; the minter is recorded.
 *   - resolve runs the canonical OR read path with `_rbac: true`, so
 *     only the fields the PUBLIC group may read are returned (the same
 *     `publicatiedatum<=$now` + public-group predicate that drives
 *     `ObjectsController::show()` for anonymous callers). The token is
 *     an addressing handle, never an authorisation grant.
 *   - revoked / expired / unknown tokens resolve to null so the
 *     controller returns 404 — no enumeration oracle.
 *
 * Lazy-resolution policy mirrors {@see ShareLinkService}: ObjectService
 * is pulled from the server container on demand so the ctor stays light
 * and unit tests can inject a mock container.
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
 *
 * @spec openspec/changes/integration-leaf-foundation-shares-analytics/specs/integration-leaf-foundation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\CaseToken;
use OCA\OpenRegister\Db\CaseTokenMapper;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * CaseTokenService — public case-token link mint / resolve / revoke.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Service composes the token mapper, secure-random,
 * user-session, URL generator and the lazily-resolved ObjectService — each is required for the
 * mint / resolve / revoke contract.
 */
class CaseTokenService
{

    /**
     * Token length in characters (URL-safe alphanumeric). 43 chars of
     * the 62-symbol alphabet ≈ 256 bits of entropy — non-guessable, so a
     * brute-force enumeration of the resolve endpoint is infeasible.
     *
     * @var int
     */
    private const TOKEN_LENGTH = 43;

    /**
     * Optional server container override (tests inject a mock so the
     * ObjectService resolve path is exercisable without the full
     * container).
     *
     * @var ContainerInterface|null
     */
    private ?ContainerInterface $container;

    /**
     * Constructor.
     *
     * @param CaseTokenMapper         $mapper       Token persistence.
     * @param ISecureRandom           $secureRandom NC secure RNG.
     * @param IUserSession            $userSession  Current user (minter).
     * @param IURLGenerator           $urlGenerator Public URL builder.
     * @param LoggerInterface         $logger       Logger.
     * @param ContainerInterface|null $container    Optional container (tests only).
     *
     * @return void
     */
    public function __construct(
        private CaseTokenMapper $mapper,
        private ISecureRandom $secureRandom,
        private IUserSession $userSession,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
        ?ContainerInterface $container=null,
    ) {
        $this->container = $container;
    }//end __construct()

    /**
     * Mint a public token-link for an object.
     *
     * @param string      $objectUuid Object uuid to bind the token to.
     * @param int|null    $registerId Register id of the object.
     * @param int|null    $schemaId   Schema id of the object.
     * @param string|null $label      Optional human label.
     * @param int|null    $ttlSeconds Optional time-to-live in seconds;
     *                                null = never expires.
     *
     * @return array<string,mixed> Minted token metadata + the public URL.
     *
     * @throws InvalidArgumentException When objectUuid is empty or the
     *                                  minter is anonymous.
     *
     * @spec openspec/changes/integration-leaf-foundation-shares-analytics/specs/integration-leaf-foundation/spec.md
     */
    public function mint(
        string $objectUuid,
        ?int $registerId=null,
        ?int $schemaId=null,
        ?string $label=null,
        ?int $ttlSeconds=null
    ): array {
        if (trim($objectUuid) === '') {
            throw new InvalidArgumentException('objectUuid is required to mint a case token');
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            // Minting is a write that establishes a public surface — it
            // MUST be performed by an authenticated user, never anonymously.
            throw new InvalidArgumentException('A logged-in user is required to mint a case token');
        }

        $now      = new DateTime();
        $expires  = null;
        if ($ttlSeconds !== null && $ttlSeconds > 0) {
            $expires = (clone $now)->modify('+'.$ttlSeconds.' seconds');
        }

        $token = $this->secureRandom->generate(
            self::TOKEN_LENGTH,
            ISecureRandom::CHAR_ALPHANUMERIC
        );

        $entity = new CaseToken();
        $entity->setToken($token);
        $entity->setObjectUuid($objectUuid);
        $entity->setRegisterId($registerId);
        $entity->setSchemaId($schemaId);
        $entity->setLabel($label);
        $entity->setCreatedBy($user->getUID());
        $entity->setCreatedAt($now);
        $entity->setExpiresAt($expires);
        $entity->setRevokedAt(null);

        $saved = $this->mapper->insert($entity);

        $data        = $saved->jsonSerialize();
        $data['url'] = $this->buildPublicUrl($token);
        return $data;
    }//end mint()

    /**
     * Resolve a token to a PUBLIC-SAFE view of the referenced object.
     *
     * Runs the canonical OR read path with RBAC enforced (`_rbac: true`):
     * an anonymous caller only sees the fields the public group may read,
     * exactly as `ObjectsController::show()` does for anonymous requests.
     * The token never bypasses RBAC — it only addresses the object.
     *
     * Returns null on any failure (unknown / revoked / expired token,
     * object missing, RBAC-denied) so the caller returns a uniform 404
     * and the endpoint is not an enumeration oracle.
     *
     * @param string $token The opaque token.
     *
     * @return array<string,mixed>|null The public-safe object view, or
     *                                  null when the token cannot be
     *                                  resolved.
     *
     * @spec openspec/changes/integration-leaf-foundation-shares-analytics/specs/integration-leaf-foundation/spec.md
     */
    public function resolve(string $token): ?array
    {
        if (trim($token) === '') {
            return null;
        }

        $row = $this->mapper->findByToken($token);
        if ($row === null) {
            return null;
        }

        if ($row->isValidAt(new DateTime()) === false) {
            // Revoked or expired — fail closed.
            return null;
        }

        $objectService = $this->resolveObjectService();
        if ($objectService === null) {
            return null;
        }

        try {
            // RBAC-respecting read: no user is logged in on the public
            // resolve endpoint, so `_rbac: true` enforces the public-group
            // read predicate. We do NOT pass an admin bypass.
            $entity = $objectService->find(
                id: $row->getObjectUuid(),
                _extend: [],
                files: false,
                register: $row->getRegisterId(),
                schema: $row->getSchemaId(),
                _rbac: true,
                _multitenancy: true
            );

            if ($entity === null) {
                return null;
            }

            $rendered = $objectService->renderEntity(
                entity: $entity,
                _extend: [],
                depth: 0,
                filter: [],
                fields: [],
                unset: [],
                _rbac: true,
                _multitenancy: true
            );

            return [
                'token'  => $row->getToken(),
                'label'  => $row->getLabel(),
                'object' => $rendered,
            ];
        } catch (Throwable $e) {
            // RBAC-denied / not-found / any read failure → 404 (null).
            // Logged server-side; the caller never learns why.
            $this->logger->debug(
                '[CaseTokenService] resolve failed for a token',
                ['exception' => $e]
            );
            return null;
        }//end try
    }//end resolve()

    /**
     * Revoke a token so it can no longer be resolved.
     *
     * Idempotent: revoking an already-revoked or unknown token returns
     * false rather than throwing. Accepts either the opaque token string
     * or the numeric row id (the provider addresses tokens by id).
     *
     * @param string $tokenOrId The opaque token, or its numeric row id.
     *
     * @return bool True when a token was revoked, false when none matched.
     *
     * @spec openspec/changes/integration-leaf-foundation-shares-analytics/specs/integration-leaf-foundation/spec.md
     */
    public function revoke(string $tokenOrId): bool
    {
        $row = $this->mapper->findByToken($tokenOrId);
        if ($row === null && ctype_digit($tokenOrId) === true) {
            $row = $this->mapper->findById((int) $tokenOrId);
        }

        if ($row === null) {
            return false;
        }

        if ($row->getRevokedAt() !== null) {
            // Already revoked — idempotent no-op.
            return false;
        }

        $row->setRevokedAt(new DateTime());
        $this->mapper->update($row);
        return true;
    }//end revoke()

    /**
     * List the (public-safe metadata of) tokens minted against an object.
     *
     * @param string $objectUuid Object uuid.
     *
     * @return array<int,array<string,mixed>> Token metadata rows.
     *
     * @spec openspec/changes/integration-leaf-foundation-shares-analytics/specs/integration-leaf-foundation/spec.md
     */
    public function listForObject(string $objectUuid): array
    {
        $rows = $this->mapper->findByObjectUuid($objectUuid);
        return array_map(
            function (CaseToken $row): array {
                $data        = $row->jsonSerialize();
                $data['url'] = $this->buildPublicUrl((string) $row->getToken());
                return $data;
            },
            $rows
        );
    }//end listForObject()

    /**
     * Build the absolute public resolve URL for a token.
     *
     * @param string $token The opaque token.
     *
     * @return string Absolute URL.
     */
    private function buildPublicUrl(string $token): string
    {
        $path = $this->urlGenerator->linkToRoute(
            'openregister.caseToken.resolve',
            ['token' => $token]
        );
        return $this->urlGenerator->getAbsoluteURL($path);
    }//end buildPublicUrl()

    /**
     * Resolve the ObjectService from the active container.
     *
     * @return object|null The ObjectService, or null when unresolvable.
     */
    private function resolveObjectService(): ?object
    {
        try {
            $container = $this->resolveContainer();
            $service   = $container->get('OCA\\OpenRegister\\Service\\ObjectService');
            if (is_object($service) === true) {
                return $service;
            }

            return null;
        } catch (Throwable $e) {
            return null;
        }
    }//end resolveObjectService()

    /**
     * Resolve the active container — the test override if injected,
     * otherwise NC's global server container.
     *
     * @return ContainerInterface
     */
    private function resolveContainer(): ContainerInterface
    {
        if ($this->container !== null) {
            return $this->container;
        }

        return new class implements ContainerInterface {

            /**
             * Resolve a service from NC's global server container.
             *
             * @param string $id Service id.
             *
             * @return object
             *
             * @spec exclude Anonymous PSR-11 adapter shim around \OCP\Server::get — pure DI plumbing, no behavioural contract.
             */
            public function get(string $id): object
            {
                return \OCP\Server::get($id);
            }//end get()

            /**
             * Whether NC's global server container can resolve the id.
             *
             * @param string $id Service id.
             *
             * @return bool
             *
             * @spec exclude Anonymous PSR-11 adapter shim around \OCP\Server::get — pure DI plumbing, no behavioural contract.
             */
            public function has(string $id): bool
            {
                try {
                    \OCP\Server::get($id);
                    return true;
                } catch (Throwable $e) {
                    return false;
                }
            }//end has()
        };
    }//end resolveContainer()
}//end class
