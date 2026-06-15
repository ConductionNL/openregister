<?php

/**
 * Unit tests for CaseTokenService — public "track your case" token
 * mint / resolve / revoke.
 *
 * Covers the contract required by the integration-leaf-foundation change:
 *   - mint(): persists a token bound to the object, records the minter,
 *     honours TTL, returns the public URL; rejects anonymous minters and
 *     empty object uuids.
 *   - resolve(): RBAC-RESPECTING — runs ObjectService::find/renderEntity
 *     with `_rbac: true`; returns the public-safe view for a valid token.
 *   - resolve() fails closed (null) for unknown / revoked / expired
 *     tokens and when the RBAC read denies — no enumeration oracle.
 *   - revoke(): invalidates a token (idempotent), addressable by token
 *     or numeric id.
 *
 * ObjectService is pulled lazily through a ContainerInterface the test
 * injects, so the resolve path is exercisable without the NC container.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
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

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\CaseToken;
use OCA\OpenRegister\Db\CaseTokenMapper;
use OCA\OpenRegister\Service\CaseTokenService;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for CaseTokenService.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CaseTokenServiceTest extends TestCase
{
    private function buildUserSession(?string $uid): IUserSession
    {
        $session = $this->createMock(IUserSession::class);
        if ($uid === null) {
            $session->method('getUser')->willReturn(null);
            return $session;
        }

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session->method('getUser')->willReturn($user);
        return $session;
    }//end buildUserSession()


    private function buildUrlGenerator(): IURLGenerator
    {
        $url = $this->createMock(IURLGenerator::class);
        $url->method('linkToRoute')->willReturnCallback(
            static fn (string $route, array $args=[]): string => '/index.php/apps/openregister/api/public/case-tokens/'.($args['token'] ?? '')
        );
        $url->method('getAbsoluteURL')->willReturnCallback(
            static fn (string $path): string => 'https://nc.example'.$path
        );
        return $url;
    }//end buildUrlGenerator()


    private function buildSecureRandom(string $token): ISecureRandom
    {
        $rng = $this->createMock(ISecureRandom::class);
        $rng->method('generate')->willReturn($token);
        return $rng;
    }//end buildSecureRandom()


    /**
     * @param array<string,object> $services
     */
    private function buildContainer(array $services): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($services) {
                if (array_key_exists($id, $services) === true) {
                    return $services[$id];
                }

                throw new class extends RuntimeException implements NotFoundExceptionInterface {
                };
            }
        );
        return $container;
    }//end buildContainer()


    /**
     * Build an ObjectService stand-in returning the given rendered view.
     *
     * @param array<string,mixed>|null $rendered  Rendered object (null = not found).
     * @param bool                     $expectRbac Assert _rbac:true on find().
     */
    private function buildObjectService(?array $rendered, bool $expectRbac=true): object
    {
        return new class($rendered, $expectRbac) {

            public bool $findRbac = false;

            public bool $renderRbac = false;

            public function __construct(private ?array $rendered, private bool $expectRbac)
            {
            }//end __construct()

            public function find(
                int | string $id,
                ?array $_extend=[],
                bool $files=false,
                $register=null,
                $schema=null,
                bool $_rbac=true,
                bool $_multitenancy=true
            ): ?object {
                $this->findRbac = $_rbac;
                if ($this->rendered === null) {
                    return null;
                }

                return (object) ['uuid' => $id];
            }//end find()

            public function renderEntity(
                object $entity,
                ?array $_extend=[],
                ?int $depth=0,
                ?array $filter=[],
                ?array $fields=[],
                ?array $unset=[],
                bool $_rbac=true,
                bool $_multitenancy=true
            ): array {
                $this->renderRbac = $_rbac;
                return ($this->rendered ?? []);
            }//end renderEntity()
        };
    }//end buildObjectService()


    /**
     * mint() persists the token and returns its public URL.
     *
     * @return void
     */
    public function testMintPersistsTokenAndReturnsUrl(): void
    {
        $mapper = $this->createMock(CaseTokenMapper::class);
        $mapper->expects($this->once())->method('insert')->willReturnCallback(
            static function (CaseToken $t): CaseToken {
                $t->setId(7);
                return $t;
            }
        );

        $service = new CaseTokenService(
            mapper: $mapper,
            secureRandom: $this->buildSecureRandom('TOKEN-ABC'),
            userSession: $this->buildUserSession('alice'),
            urlGenerator: $this->buildUrlGenerator(),
            logger: $this->createMock(LoggerInterface::class),
        );

        $result = $service->mint(objectUuid: 'obj-1', registerId: 3, schemaId: 9, label: 'Track your case', ttlSeconds: 3600);

        $this->assertSame('TOKEN-ABC', $result['token']);
        $this->assertSame('obj-1', $result['objectUuid']);
        $this->assertSame('alice', $result['createdBy']);
        $this->assertNotNull($result['expiresAt']);
        $this->assertStringContainsString('TOKEN-ABC', $result['url']);
    }//end testMintPersistsTokenAndReturnsUrl()


    /**
     * mint() rejects an anonymous minter (fail-closed write).
     *
     * @return void
     */
    public function testMintRejectsAnonymousMinter(): void
    {
        $service = new CaseTokenService(
            mapper: $this->createMock(CaseTokenMapper::class),
            secureRandom: $this->buildSecureRandom('x'),
            userSession: $this->buildUserSession(null),
            urlGenerator: $this->buildUrlGenerator(),
            logger: $this->createMock(LoggerInterface::class),
        );

        $this->expectException(InvalidArgumentException::class);
        $service->mint(objectUuid: 'obj-1');
    }//end testMintRejectsAnonymousMinter()


    /**
     * mint() rejects an empty object uuid.
     *
     * @return void
     */
    public function testMintRejectsEmptyObjectUuid(): void
    {
        $service = new CaseTokenService(
            mapper: $this->createMock(CaseTokenMapper::class),
            secureRandom: $this->buildSecureRandom('x'),
            userSession: $this->buildUserSession('alice'),
            urlGenerator: $this->buildUrlGenerator(),
            logger: $this->createMock(LoggerInterface::class),
        );

        $this->expectException(InvalidArgumentException::class);
        $service->mint(objectUuid: '  ');
    }//end testMintRejectsEmptyObjectUuid()


    /**
     * resolve() returns the public-safe view AND uses the RBAC-respecting
     * read path (_rbac:true on both find and render).
     *
     * @return void
     */
    public function testResolveReturnsPublicSafeViewWithRbac(): void
    {
        $token = new CaseToken();
        $token->setToken('GOOD');
        $token->setObjectUuid('obj-1');
        $token->setLabel('Your case');
        $token->setRevokedAt(null);
        $token->setExpiresAt(null);

        $mapper = $this->createMock(CaseTokenMapper::class);
        $mapper->method('findByToken')->with('GOOD')->willReturn($token);

        $objectService = $this->buildObjectService(['title' => 'Public View', 'status' => 'open']);
        $container     = $this->buildContainer(['OCA\\OpenRegister\\Service\\ObjectService' => $objectService]);

        $service = new CaseTokenService(
            mapper: $mapper,
            secureRandom: $this->buildSecureRandom('x'),
            userSession: $this->buildUserSession(null),
            urlGenerator: $this->buildUrlGenerator(),
            logger: $this->createMock(LoggerInterface::class),
            container: $container,
        );

        $result = $service->resolve('GOOD');

        $this->assertNotNull($result);
        $this->assertSame('Your case', $result['label']);
        $this->assertSame(['title' => 'Public View', 'status' => 'open'], $result['object']);
        $this->assertTrue($objectService->findRbac, 'find() must run with _rbac:true (RBAC-respecting)');
        $this->assertTrue($objectService->renderRbac, 'renderEntity() must run with _rbac:true (RBAC-respecting)');
    }//end testResolveReturnsPublicSafeViewWithRbac()


    /**
     * resolve() returns null for an unknown token (no oracle).
     *
     * @return void
     */
    public function testResolveUnknownTokenReturnsNull(): void
    {
        $mapper = $this->createMock(CaseTokenMapper::class);
        $mapper->method('findByToken')->willReturn(null);

        $service = new CaseTokenService(
            mapper: $mapper,
            secureRandom: $this->buildSecureRandom('x'),
            userSession: $this->buildUserSession(null),
            urlGenerator: $this->buildUrlGenerator(),
            logger: $this->createMock(LoggerInterface::class),
            container: $this->buildContainer([]),
        );

        $this->assertNull($service->resolve('NOPE'));
    }//end testResolveUnknownTokenReturnsNull()


    /**
     * resolve() returns null for a revoked token.
     *
     * @return void
     */
    public function testResolveRevokedTokenReturnsNull(): void
    {
        $token = new CaseToken();
        $token->setToken('REV');
        $token->setObjectUuid('obj-1');
        $token->setRevokedAt(new DateTime('-1 hour'));

        $mapper = $this->createMock(CaseTokenMapper::class);
        $mapper->method('findByToken')->willReturn($token);

        $service = new CaseTokenService(
            mapper: $mapper,
            secureRandom: $this->buildSecureRandom('x'),
            userSession: $this->buildUserSession(null),
            urlGenerator: $this->buildUrlGenerator(),
            logger: $this->createMock(LoggerInterface::class),
            container: $this->buildContainer([]),
        );

        $this->assertNull($service->resolve('REV'));
    }//end testResolveRevokedTokenReturnsNull()


    /**
     * resolve() returns null for an expired token.
     *
     * @return void
     */
    public function testResolveExpiredTokenReturnsNull(): void
    {
        $token = new CaseToken();
        $token->setToken('EXP');
        $token->setObjectUuid('obj-1');
        $token->setExpiresAt(new DateTime('-1 second'));

        $mapper = $this->createMock(CaseTokenMapper::class);
        $mapper->method('findByToken')->willReturn($token);

        $service = new CaseTokenService(
            mapper: $mapper,
            secureRandom: $this->buildSecureRandom('x'),
            userSession: $this->buildUserSession(null),
            urlGenerator: $this->buildUrlGenerator(),
            logger: $this->createMock(LoggerInterface::class),
            container: $this->buildContainer([]),
        );

        $this->assertNull($service->resolve('EXP'));
    }//end testResolveExpiredTokenReturnsNull()


    /**
     * resolve() returns null when the RBAC read denies (object hidden
     * from the public group) — fail-closed, no oracle.
     *
     * @return void
     */
    public function testResolveRbacDeniedReturnsNull(): void
    {
        $token = new CaseToken();
        $token->setToken('GOOD');
        $token->setObjectUuid('obj-secret');

        $mapper = $this->createMock(CaseTokenMapper::class);
        $mapper->method('findByToken')->willReturn($token);

        // ObjectService::find returns null (RBAC-denied looks like not-found).
        $objectService = $this->buildObjectService(null);
        $container     = $this->buildContainer(['OCA\\OpenRegister\\Service\\ObjectService' => $objectService]);

        $service = new CaseTokenService(
            mapper: $mapper,
            secureRandom: $this->buildSecureRandom('x'),
            userSession: $this->buildUserSession(null),
            urlGenerator: $this->buildUrlGenerator(),
            logger: $this->createMock(LoggerInterface::class),
            container: $container,
        );

        $this->assertNull($service->resolve('GOOD'));
        $this->assertTrue($objectService->findRbac, 'find() ran RBAC-respecting');
    }//end testResolveRbacDeniedReturnsNull()


    /**
     * revoke() invalidates a token by its opaque string.
     *
     * @return void
     */
    public function testRevokeByToken(): void
    {
        $token = new CaseToken();
        $token->setToken('REVME');
        $token->setRevokedAt(null);

        $mapper = $this->createMock(CaseTokenMapper::class);
        $mapper->method('findByToken')->with('REVME')->willReturn($token);
        $mapper->expects($this->once())->method('update')->willReturnCallback(
            static fn (CaseToken $t): CaseToken => $t
        );

        $service = new CaseTokenService(
            mapper: $mapper,
            secureRandom: $this->buildSecureRandom('x'),
            userSession: $this->buildUserSession('alice'),
            urlGenerator: $this->buildUrlGenerator(),
            logger: $this->createMock(LoggerInterface::class),
        );

        $this->assertTrue($service->revoke('REVME'));
        $this->assertNotNull($token->getRevokedAt());
    }//end testRevokeByToken()


    /**
     * revoke() is idempotent on an already-revoked token (returns false,
     * no second update).
     *
     * @return void
     */
    public function testRevokeIdempotentOnAlreadyRevoked(): void
    {
        $token = new CaseToken();
        $token->setToken('DONE');
        $token->setRevokedAt(new DateTime('-1 hour'));

        $mapper = $this->createMock(CaseTokenMapper::class);
        $mapper->method('findByToken')->willReturn($token);
        $mapper->expects($this->never())->method('update');

        $service = new CaseTokenService(
            mapper: $mapper,
            secureRandom: $this->buildSecureRandom('x'),
            userSession: $this->buildUserSession('alice'),
            urlGenerator: $this->buildUrlGenerator(),
            logger: $this->createMock(LoggerInterface::class),
        );

        $this->assertFalse($service->revoke('DONE'));
    }//end testRevokeIdempotentOnAlreadyRevoked()


    /**
     * revoke() addresses a token by its numeric id when the token string
     * lookup misses (provider passes `token:<id>`).
     *
     * @return void
     */
    public function testRevokeByNumericId(): void
    {
        $token = new CaseToken();
        $token->setId(42);
        $token->setToken('BYID');
        $token->setRevokedAt(null);

        $mapper = $this->createMock(CaseTokenMapper::class);
        $mapper->method('findByToken')->with('42')->willReturn(null);
        $mapper->method('findById')->with(42)->willReturn($token);
        $mapper->expects($this->once())->method('update');

        $service = new CaseTokenService(
            mapper: $mapper,
            secureRandom: $this->buildSecureRandom('x'),
            userSession: $this->buildUserSession('alice'),
            urlGenerator: $this->buildUrlGenerator(),
            logger: $this->createMock(LoggerInterface::class),
        );

        $this->assertTrue($service->revoke('42'));
    }//end testRevokeByNumericId()
}//end class
