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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\CaseTokenIssuance;
use OCA\OpenRegister\Service\CaseTokenService;
use OCA\OpenRegister\Notifications\Service\Timeline\PublicTimeline;
use OCA\OpenRegister\Notifications\Service\Timeline\PublicTimelineInterface;
use OCA\OpenRegister\Service\CaseTokenTarget;
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
class CaseTokenServiceTest extends TestCase {
	private function buildUserSession(?string $uid): IUserSession {
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

	private function buildUrlGenerator(): IURLGenerator {
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $args = []): string => '/index.php/apps/openregister/api/public/case-tokens/' . ($args['token'] ?? '')
		);
		$url->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://nc.example' . $path
		);
		return $url;
	}//end buildUrlGenerator()

	private function buildSecureRandom(string $token): ISecureRandom {
		$rng = $this->createMock(ISecureRandom::class);
		$rng->method('generate')->willReturn($token);
		return $rng;
	}//end buildSecureRandom()

	/**
	 * @param array<string,object> $services
	 */
	private function buildContainer(array $services): ContainerInterface {
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
	 * @param array<string,mixed>|null $rendered Rendered object (null = not found).
	 * @param bool $expectRbac Assert _rbac:true on find().
	 */
	private function buildObjectService(?array $rendered, bool $expectRbac = true): object {
		return new class($rendered, $expectRbac) {
			public bool $findRbac = false;

			public bool $renderRbac = false;

			public function __construct(
				private ?array $rendered,
				private bool $expectRbac,
			) {
			}//end __construct()

			public function find(
				int|string $id,
				?ObjectFindOptions $options = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): ?object {
				$this->findRbac = $_rbac;
				if ($this->rendered === null) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);

				return $entity;
			}//end find()

			public function renderEntity(
				object $entity,
				?object $selection = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
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
	public function testMintPersistsTokenAndReturnsUrl(): void {
		$mapper = $this->createMock(CaseTokenMapper::class);
		$mapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (CaseToken $t): CaseToken {
				$t->setId(7);
				return $t;
			}
		);

		$service = new CaseTokenService(
			issuance: new CaseTokenIssuance(
				mapper: $mapper,
				secureRandom: $this->buildSecureRandom('TOKEN-ABC'),
				urlGenerator: $this->buildUrlGenerator(),
			),
			userSession: $this->buildUserSession('alice'),
			logger: $this->createMock(LoggerInterface::class),
			container: $this->buildContainer([]),
		);

		$result = $service->mintFor(target: new CaseTokenTarget(objectUuid: 'obj-1', registerId: 3, schemaId: 9, label: 'Track your case'), ttlSeconds: 3600);

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
	public function testMintRejectsAnonymousMinter(): void {
		$service = new CaseTokenService(
			issuance: new CaseTokenIssuance(
				mapper: $this->createMock(CaseTokenMapper::class),
				secureRandom: $this->buildSecureRandom('x'),
				urlGenerator: $this->buildUrlGenerator(),
			),
			userSession: $this->buildUserSession(null),
			logger: $this->createMock(LoggerInterface::class),
			container: $this->buildContainer([]),
		);

		$this->expectException(InvalidArgumentException::class);
		$service->mintFor(target: new CaseTokenTarget(objectUuid: 'obj-1'));
	}//end testMintRejectsAnonymousMinter()

	/**
	 * mint() rejects an empty object uuid.
	 *
	 * @return void
	 */
	public function testMintRejectsEmptyObjectUuid(): void {
		$service = new CaseTokenService(
			issuance: new CaseTokenIssuance(
				mapper: $this->createMock(CaseTokenMapper::class),
				secureRandom: $this->buildSecureRandom('x'),
				urlGenerator: $this->buildUrlGenerator(),
			),
			userSession: $this->buildUserSession('alice'),
			logger: $this->createMock(LoggerInterface::class),
			container: $this->buildContainer([]),
		);

		$this->expectException(InvalidArgumentException::class);
		$service->mintFor(target: new CaseTokenTarget(objectUuid: '  '));
	}//end testMintRejectsEmptyObjectUuid()

	/**
	 * resolve() returns the public-safe view AND uses the RBAC-respecting
	 * read path (_rbac:true on both find and render).
	 *
	 * @return void
	 */
	public function testResolveReturnsPublicSafeViewWithRbac(): void {
		$token = new CaseToken();
		$token->setToken('GOOD');
		$token->setObjectUuid('obj-1');
		$token->setLabel('Your case');
		$token->setRevokedAt(null);
		$token->setExpiresAt(null);

		$mapper = $this->createMock(CaseTokenMapper::class);
		$mapper->method('findByToken')->with('GOOD')->willReturn($token);

		$objectService = $this->buildObjectService(['title' => 'Public View', 'status' => 'open']);
		$container = $this->buildContainer(['OCA\\OpenRegister\\Service\\ObjectService' => $objectService]);

		$service = new CaseTokenService(
			issuance: new CaseTokenIssuance(
				mapper: $mapper,
				secureRandom: $this->buildSecureRandom('x'),
				urlGenerator: $this->buildUrlGenerator(),
			),
			userSession: $this->buildUserSession(null),
			logger: $this->createMock(LoggerInterface::class),
			container: $container,
		);

		$result = $service->resolve('GOOD');

		$this->assertNotNull($result);
		$this->assertSame('Your case', $result['label']);
		$this->assertSame(['title' => 'Public View', 'status' => 'open'], $result['object']);
		$this->assertTrue($objectService->findRbac, 'find() must run with _rbac:true (RBAC-respecting)');
		$this->assertTrue($objectService->renderRbac, 'renderEntity() must run with _rbac:true (RBAC-respecting)');
		$this->assertSame([], $result['timeline'], 'an instance with no timeline service still resolves');
	}//end testResolveReturnsPublicSafeViewWithRbac()

	/**
	 * resolve() serves exactly what the one anonymous timeline reader answers.
	 *
	 * The public filter and the five-key projection belong to PublicTimeline,
	 * and PublicTimelineTest pins both by name. What this test pins is that the
	 * case-token path asks that class, for this object, and serves its answer
	 * without adding a key of its own. A second projection here is how the two
	 * anonymous surfaces would come to disagree.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function testResolveServesThePublicTimelineReadersAnswer(): void {
		$token = new CaseToken();
		$token->setToken('GOOD');
		$token->setObjectUuid('obj-1');
		$token->setLabel('Your case');
		$token->setRevokedAt(null);
		$token->setExpiresAt(null);

		$mapper = $this->createMock(CaseTokenMapper::class);
		$mapper->method('findByToken')->with('GOOD')->willReturn($token);

		$published = [[
			'id' => 'e1',
			'kind' => 'beschikking-verzonden',
			'message' => 'Beschikking verzonden',
			'fields' => ['channel' => 'berichtenbox'],
			'occurredAt' => '2026-05-04T09:12:00+02:00',
		]];

		$reader = $this->createMock(PublicTimeline::class);
		$reader->expects($this->once())
			->method('forObject')
			->with(
				$this->callback(static fn (ObjectEntity $object): bool => $object->getUuid() === 'obj-1'),
				50
			)
			->willReturn($published);

		$service = new CaseTokenService(
			issuance: new CaseTokenIssuance(
				mapper: $mapper,
				secureRandom: $this->buildSecureRandom('x'),
				urlGenerator: $this->buildUrlGenerator(),
			),
			userSession: $this->buildUserSession(null),
			logger: $this->createMock(LoggerInterface::class),
			container: $this->buildContainer([
				'OCA\\OpenRegister\\Service\\ObjectService' => $this->buildObjectService(['title' => 'Public View']),
				PublicTimelineInterface::class => $reader,
			]),
		);

		$result = $service->resolve('GOOD');

		$this->assertSame($published, $result['timeline']);
	}//end testResolveServesThePublicTimelineReadersAnswer()

	/**
	 * A reader the container cannot build leaves the status page standing.
	 *
	 * The status page shipped before the timeline did. An instance that cannot
	 * build the reader must show the status rather than a uniform 404, which a
	 * citizen reads as a revoked link. The warning is asserted beside it,
	 * because an empty timeline nobody logged is a failure with no name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function testAReaderThatCannotBeBuiltStillResolvesTheStatus(): void {
		$token = new CaseToken();
		$token->setToken('GOOD');
		$token->setObjectUuid('obj-1');
		$token->setLabel('Your case');
		$token->setRevokedAt(null);
		$token->setExpiresAt(null);

		$mapper = $this->createMock(CaseTokenMapper::class);
		$mapper->method('findByToken')->with('GOOD')->willReturn($token);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$service = new CaseTokenService(
			issuance: new CaseTokenIssuance(
				mapper: $mapper,
				secureRandom: $this->buildSecureRandom('x'),
				urlGenerator: $this->buildUrlGenerator(),
			),
			userSession: $this->buildUserSession(null),
			logger: $logger,
			container: $this->buildContainer([
				'OCA\\OpenRegister\\Service\\ObjectService' => $this->buildObjectService(['title' => 'Public View']),
			]),
		);

		$result = $service->resolve('GOOD');

		$this->assertNotNull($result);
		$this->assertSame(['title' => 'Public View'], $result['object']);
		$this->assertSame([], $result['timeline']);
	}//end testAReaderThatCannotBeBuiltStillResolvesTheStatus()

	/**
	 * resolve() returns null for an unknown token (no oracle).
	 *
	 * @return void
	 */
	public function testResolveUnknownTokenReturnsNull(): void {
		$mapper = $this->createMock(CaseTokenMapper::class);
		$mapper->method('findByToken')->willReturn(null);

		$service = new CaseTokenService(
			issuance: new CaseTokenIssuance(
				mapper: $mapper,
				secureRandom: $this->buildSecureRandom('x'),
				urlGenerator: $this->buildUrlGenerator(),
			),
			userSession: $this->buildUserSession(null),
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
	public function testResolveRevokedTokenReturnsNull(): void {
		$token = new CaseToken();
		$token->setToken('REV');
		$token->setObjectUuid('obj-1');
		$token->setRevokedAt(new DateTime('-1 hour'));

		$mapper = $this->createMock(CaseTokenMapper::class);
		$mapper->method('findByToken')->willReturn($token);

		$service = new CaseTokenService(
			issuance: new CaseTokenIssuance(
				mapper: $mapper,
				secureRandom: $this->buildSecureRandom('x'),
				urlGenerator: $this->buildUrlGenerator(),
			),
			userSession: $this->buildUserSession(null),
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
	public function testResolveExpiredTokenReturnsNull(): void {
		$token = new CaseToken();
		$token->setToken('EXP');
		$token->setObjectUuid('obj-1');
		$token->setExpiresAt(new DateTime('-1 second'));

		$mapper = $this->createMock(CaseTokenMapper::class);
		$mapper->method('findByToken')->willReturn($token);

		$service = new CaseTokenService(
			issuance: new CaseTokenIssuance(
				mapper: $mapper,
				secureRandom: $this->buildSecureRandom('x'),
				urlGenerator: $this->buildUrlGenerator(),
			),
			userSession: $this->buildUserSession(null),
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
	public function testResolveRbacDeniedReturnsNull(): void {
		$token = new CaseToken();
		$token->setToken('GOOD');
		$token->setObjectUuid('obj-secret');

		$mapper = $this->createMock(CaseTokenMapper::class);
		$mapper->method('findByToken')->willReturn($token);

		// ObjectService::find returns null (RBAC-denied looks like not-found).
		$objectService = $this->buildObjectService(null);
		$container = $this->buildContainer(['OCA\\OpenRegister\\Service\\ObjectService' => $objectService]);

		$service = new CaseTokenService(
			issuance: new CaseTokenIssuance(
				mapper: $mapper,
				secureRandom: $this->buildSecureRandom('x'),
				urlGenerator: $this->buildUrlGenerator(),
			),
			userSession: $this->buildUserSession(null),
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
	public function testRevokeByToken(): void {
		$token = new CaseToken();
		$token->setObjectUuid('obj-uuid');
		$token->setToken('REVME');
		$token->setRevokedAt(null);

		$mapper = $this->createMock(CaseTokenMapper::class);
		$mapper->method('findByToken')->with('REVME')->willReturn($token);
		$mapper->expects($this->once())->method('update')->willReturnCallback(
			static fn (CaseToken $t): CaseToken => $t
		);

		$service = new CaseTokenService(
			issuance: new CaseTokenIssuance(
				mapper: $mapper,
				secureRandom: $this->buildSecureRandom('x'),
				urlGenerator: $this->buildUrlGenerator(),
			),
			userSession: $this->buildUserSession('alice'),
			logger: $this->createMock(LoggerInterface::class),
			container: $this->buildContainer([]),
		);

		$this->assertTrue($service->revoke('obj-uuid', 'REVME'));
		$this->assertNotNull($token->getRevokedAt());
	}//end testRevokeByToken()

	/**
	 * revoke() refuses a token of another object, by token and by id, and writes nothing.
	 *
	 * @return void
	 */
	public function testRevokeRefusesATokenOfAnotherObject(): void {
		$token = new CaseToken();
		$token->setObjectUuid('other-uuid');

		$mapper = $this->createMock(CaseTokenMapper::class);
		$mapper->method('findByToken')->willReturn(null);
		$mapper->method('findById')->with(42)->willReturn($token);
		$mapper->expects($this->never())->method('update');

		$service = new CaseTokenService(
			issuance: new CaseTokenIssuance(
				mapper: $mapper,
				secureRandom: $this->buildSecureRandom('x'),
				urlGenerator: $this->buildUrlGenerator(),
			),
			userSession: $this->buildUserSession('alice'),
			logger: $this->createMock(LoggerInterface::class),
			container: $this->buildContainer([]),
		);

		$this->expectExceptionCode(404);
		$service->revoke('obj-uuid', '42');
	}//end testRevokeRefusesATokenOfAnotherObject()

	/**
	 * revoke() is idempotent on an already-revoked token (returns false,
	 * no second update).
	 *
	 * @return void
	 */
	public function testRevokeIdempotentOnAlreadyRevoked(): void {
		$token = new CaseToken();
		$token->setObjectUuid('obj-uuid');
		$token->setToken('DONE');
		$token->setRevokedAt(new DateTime('-1 hour'));

		$mapper = $this->createMock(CaseTokenMapper::class);
		$mapper->method('findByToken')->willReturn($token);
		$mapper->expects($this->never())->method('update');

		$service = new CaseTokenService(
			issuance: new CaseTokenIssuance(
				mapper: $mapper,
				secureRandom: $this->buildSecureRandom('x'),
				urlGenerator: $this->buildUrlGenerator(),
			),
			userSession: $this->buildUserSession('alice'),
			logger: $this->createMock(LoggerInterface::class),
			container: $this->buildContainer([]),
		);

		$this->assertFalse($service->revoke('obj-uuid', 'DONE'));
	}//end testRevokeIdempotentOnAlreadyRevoked()

	/**
	 * revoke() addresses a token by its numeric id when the token string
	 * lookup misses (provider passes `token:<id>`).
	 *
	 * @return void
	 */
	public function testRevokeByNumericId(): void {
		$token = new CaseToken();
		$token->setObjectUuid('obj-uuid');
		$token->setId(42);
		$token->setToken('BYID');
		$token->setRevokedAt(null);

		$mapper = $this->createMock(CaseTokenMapper::class);
		$mapper->method('findByToken')->with('42')->willReturn(null);
		$mapper->method('findById')->with(42)->willReturn($token);
		$mapper->expects($this->once())->method('update');

		$service = new CaseTokenService(
			issuance: new CaseTokenIssuance(
				mapper: $mapper,
				secureRandom: $this->buildSecureRandom('x'),
				urlGenerator: $this->buildUrlGenerator(),
			),
			userSession: $this->buildUserSession('alice'),
			logger: $this->createMock(LoggerInterface::class),
			container: $this->buildContainer([]),
		);

		$this->assertTrue($service->revoke('obj-uuid', '42'));
	}//end testRevokeByNumericId()

	/**
	 * A valid token row addressing obj-1.
	 *
	 * @return CaseToken
	 */
	private function validRow(): CaseToken {
		$token = new CaseToken();
		$token->setToken('GOOD');
		$token->setObjectUuid('obj-1');
		$token->setRegisterId(3);
		$token->setSchemaId(9);
		$token->setLabel('Your case');
		$token->setRevokedAt(null);
		$token->setExpiresAt(null);
		return $token;
	}//end validRow()

	/**
	 * A service resolving tokens to the given row, with this container and logger.
	 *
	 * @param ContainerInterface $container The container.
	 * @param LoggerInterface    $logger    The logger.
	 *
	 * @return CaseTokenService
	 */
	private function resolvingService(ContainerInterface $container, LoggerInterface $logger): CaseTokenService {
		$mapper = $this->createMock(CaseTokenMapper::class);
		$mapper->method('findByToken')->with('GOOD')->willReturn($this->validRow());

		return new CaseTokenService(
			issuance: new CaseTokenIssuance(
				mapper: $mapper,
				secureRandom: $this->buildSecureRandom('x'),
				urlGenerator: $this->buildUrlGenerator(),
			),
			userSession: $this->buildUserSession(null),
			logger: $logger,
			container: $container,
		);
	}//end resolvingService()

	/**
	 * A read that throws resolves to null and is logged at debug level with the exception.
	 *
	 * @return void
	 */
	public function testResolveReadFailureReturnsNullAndLogs(): void {
		$failure       = new RuntimeException('read refused');
		$objectService = new class($failure) {
			public function __construct(private RuntimeException $failure) {
			}//end __construct()

			public function find(int|string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): ?object {
				throw $this->failure;
			}//end find()
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')
			->with('[CaseTokenService] resolve failed for a token', ['exception' => $failure]);

		$service = $this->resolvingService(
			$this->buildContainer(['OCA\\OpenRegister\\Service\\ObjectService' => $objectService]),
			$logger
		);

		$this->assertNull($service->resolve('GOOD'));
	}//end testResolveReadFailureReturnsNullAndLogs()

	/**
	 * The read is made in the token's register and schema.
	 *
	 * @return void
	 */
	public function testResolveReadsInTheTokensRegisterAndSchema(): void {
		$objectService = new class {
			public ?ObjectFindOptions $options = null;

			public ?string $id = null;

			public function find(int|string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): ?object {
				$this->id      = (string)$id;
				$this->options = $options;
				return new \stdClass();
			}//end find()

			public function renderEntity(object $entity, ?object $selection = null, bool $_rbac = true, bool $_multitenancy = true): array {
				return ['title' => 'Plain object'];
			}//end renderEntity()
		};
		$timeline = $this->createMock(PublicTimelineInterface::class);
		$timeline->expects($this->never())->method($this->anything());

		$service = $this->resolvingService(
			$this->buildContainer(
				[
					'OCA\\OpenRegister\\Service\\ObjectService' => $objectService,
					PublicTimelineInterface::class => $timeline,
				]
			),
			$this->createMock(LoggerInterface::class)
		);

		$result = $service->resolve('GOOD');

		$this->assertSame('obj-1', $objectService->id);
		$this->assertSame(3, $objectService->options->register);
		$this->assertSame(9, $objectService->options->schema);
		$this->assertSame(
			['token' => 'GOOD', 'label' => 'Your case', 'object' => ['title' => 'Plain object'], 'timeline' => []],
			$result,
			'an object that is not an entity has no timeline to read'
		);
	}//end testResolveReadsInTheTokensRegisterAndSchema()

	/**
	 * A container entry that is not an object cannot serve the read, so the token resolves to null.
	 *
	 * @return void
	 */
	public function testResolveWithoutAnObjectServiceReturnsNull(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn('not-a-service');

		$service = $this->resolvingService($container, $this->createMock(LoggerInterface::class));

		$this->assertNull($service->resolve('GOOD'));
	}//end testResolveWithoutAnObjectServiceReturnsNull()

	/**
	 * listForObject() returns each token's metadata plus its absolute public URL.
	 *
	 * @return void
	 */
	public function testListForObjectAddsThePublicUrl(): void {
		$first = new CaseToken();
		$first->setToken('T1');
		$first->setObjectUuid('obj-1');
		$second = new CaseToken();
		$second->setToken('T2');
		$second->setObjectUuid('obj-1');

		$mapper = $this->createMock(CaseTokenMapper::class);
		$mapper->expects($this->once())->method('findByObjectUuid')->with('obj-1')->willReturn([$first, $second]);

		$service = new CaseTokenService(
			issuance: new CaseTokenIssuance(
				mapper: $mapper,
				secureRandom: $this->buildSecureRandom('x'),
				urlGenerator: $this->buildUrlGenerator(),
			),
			userSession: $this->buildUserSession('alice'),
			logger: $this->createMock(LoggerInterface::class),
			container: $this->buildContainer([]),
		);

		$rows = $service->listForObject('obj-1');

		$this->assertCount(2, $rows);
		$this->assertSame($first->jsonSerialize() + ['url' => 'https://nc.example/index.php/apps/openregister/api/public/case-tokens/T1'], $rows[0]);
		$this->assertSame('https://nc.example/index.php/apps/openregister/api/public/case-tokens/T2', $rows[1]['url']);
		$this->assertSame('T2', $rows[1]['token']);
	}//end testListForObjectAddsThePublicUrl()
}//end class
