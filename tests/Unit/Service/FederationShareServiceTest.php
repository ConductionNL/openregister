<?php

/**
 * FederationShareService Test
 *
 * Verifies scope/permission validation, token minting and the idempotent
 * object-share path used by the federate-share flow action.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Federation\ShareGrantor;
use OCA\OpenRegister\Service\FederationShareService;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for FederationShareService.
 *
 * @package OCA\OpenRegister\Tests\Unit\Service
 */
class FederationShareServiceTest extends TestCase {

	/**
	 * The share mapper mock.
	 *
	 * @var FederatedShareMapper
	 */
	private $shareMapper;

	/**
	 * The grantor mock.
	 *
	 * @var ShareGrantor
	 */
	private $grantor;

	/**
	 * What the grantor answers for each share it is asked about.
	 *
	 * @var callable
	 */
	private $refusal;

	/**
	 * The shares the grantor was asked about.
	 *
	 * @var FederatedShare[]
	 */
	private array $judged = [];

	/**
	 * The secure random mock.
	 *
	 * @var ISecureRandom
	 */
	private $secureRandom;

	/**
	 * The service under test.
	 *
	 * @var FederationShareService
	 */
	private $service;

	/**
	 * Set up the mocks and service under test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->shareMapper = $this->createMock(FederatedShareMapper::class);
		$this->grantor = $this->createMock(ShareGrantor::class);
		$this->refusal = static fn (FederatedShare $share): ?string => null;
		$this->grantor->method('userId')->willReturn('alice');
		$this->grantor->method('organisation')->willReturn('org-a');
		$judge = function (FederatedShare $share): ?string {
			$this->judged[] = $share;
			return ($this->refusal)($share);
		};
		$this->grantor->method('refusal')->willReturnCallback($judge);
		$this->grantor->method('changeRefusal')->willReturnCallback($judge);
		$this->secureRandom = $this->createMock(ISecureRandom::class);

		$this->secureRandom->method('generate')->willReturn(str_repeat('a', 48));

		$this->service = new FederationShareService(
			$this->shareMapper,
			$this->grantor,
			$this->secureRandom,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * An unrecognised scope is rejected before anything is persisted.
	 *
	 * @return void
	 */
	public function testCreateOutgoingShareRejectsInvalidScope(): void {
		$this->shareMapper->expects($this->never())->method('createFromArray');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->createOutgoingShare(['scope' => 'planet', 'permissions' => 'read']);
	}//end testCreateOutgoingShareRejectsInvalidScope()

	/**
	 * An unrecognised permission grant is rejected before anything is persisted.
	 *
	 * @return void
	 */
	public function testCreateOutgoingShareRejectsInvalidPermissions(): void {
		$this->shareMapper->expects($this->never())->method('createFromArray');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->createOutgoingShare(['scope' => 'schema', 'permissions' => 'admin']);
	}//end testCreateOutgoingShareRejectsInvalidPermissions()

	/**
	 * A valid share is persisted as an outgoing, accepted grant carrying a
	 * freshly minted token.
	 *
	 * @return void
	 */
	public function testCreateOutgoingSharePersistsOutgoingAcceptedWithToken(): void {
		$captured = null;
		$this->shareMapper->expects($this->once())
			->method('createFromArray')
			->willReturnCallback(
				function (array $data) use (&$captured): FederatedShare {
					$captured = $data;
					return new FederatedShare();
				}
			);

		$this->service->createOutgoingShare(
			[
				'scope' => 'schema',
				'register' => '1',
				'schema' => '2',
				'permissions' => 'read-write',
				'sharedWith' => 'partner@remote.example',
			]
		);

		$this->assertSame('outgoing', $captured['direction']);
		$this->assertSame('accepted', $captured['status']);
		$this->assertSame('schema', $captured['scope']);
		$this->assertSame('read-write', $captured['permissions']);
		$this->assertSame('partner@remote.example', $captured['sharedWith']);
		$this->assertSame(str_repeat('a', 48), $captured['shareToken']);
		$this->assertSame('org-a', $captured['organisation']);
	}//end testCreateOutgoingSharePersistsOutgoingAcceptedWithToken()

	/**
	 * A share the caller may not grant is refused and never stored.
	 *
	 * @return void
	 */
	public function testASharePastTheCallersRightsIsRefusedAndNotStored(): void {
		$this->refusal = static fn (FederatedShare $share): ?string => 'This share needs the right to manage its schema';
		$this->shareMapper->expects($this->never())->method('createFromArray');

		$this->expectException(NotAuthorizedException::class);
		$this->expectExceptionMessage('This share needs the right to manage its schema');
		$this->service->createOutgoingShare(['scope' => 'schema', 'register' => 'zaken', 'schema' => 'zaak', 'permissions' => 'read-write']);
	}//end testASharePastTheCallersRightsIsRefusedAndNotStored()

	/**
	 * The grantor judges exactly the share that would be stored.
	 *
	 * @return void
	 */
	public function testTheGrantorJudgesTheShareAsRequested(): void {
		$this->shareMapper->method('createFromArray')->willReturn(new FederatedShare());

		$this->service->createOutgoingShare(
			['scope' => 'object', 'register' => 'zaken', 'schema' => 'zaak', 'objectUri' => 'secret-1', 'permissions' => 'read-write']
		);

		$this->assertCount(1, $this->judged);
		$this->assertSame('object', $this->judged[0]->getScope());
		$this->assertSame('read-write', $this->judged[0]->getPermissions());
		$this->assertSame('zaken', $this->judged[0]->getRegister());
		$this->assertSame('zaak', $this->judged[0]->getSchema());
		$this->assertSame('secret-1', $this->judged[0]->getObjectUri());
		$this->assertSame('org-a', $this->judged[0]->getOrganisation());
	}//end testTheGrantorJudgesTheShareAsRequested()

	/**
	 * A request value for owner never reaches the stored share.
	 *
	 * @return void
	 */
	public function testARequestOwnerIsNotStored(): void {
		$captured = null;
		$this->shareMapper->method('createFromArray')->willReturnCallback(
			function (array $data) use (&$captured): FederatedShare {
				$captured = $data;
				return new FederatedShare();
			}
		);

		$this->service->createOutgoingShare(['scope' => 'schema', 'schema' => 'zaak', 'permissions' => 'read', 'owner' => 'mallory']);

		$this->assertArrayNotHasKey('owner', $captured);
	}//end testARequestOwnerIsNotStored()

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function retargetingFilters(): array {
		return [
			'register key' => [['register' => 'other', 'status' => 'open']],
			'schema key' => [['schema' => 'other']],
			'not a set of conditions' => ['status=open'],
		];
	}//end retargetingFilters()

	/**
	 * A query filter that moves the search or is not a set of conditions is refused.
	 *
	 * @dataProvider retargetingFilters
	 *
	 * @param mixed $filter The requested filter.
	 *
	 * @return void
	 */
	public function testAQueryFilterThatMovesTheSearchIsRefused(mixed $filter): void {
		$this->shareMapper->expects($this->never())->method('createFromArray');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->createOutgoingShare(['scope' => 'query', 'schema' => 'zaak', 'queryFilter' => $filter, 'permissions' => 'read']);
	}//end testAQueryFilterThatMovesTheSearchIsRefused()

	/**
	 * A plain query filter is kept as given.
	 *
	 * @return void
	 */
	public function testAPlainQueryFilterIsKept(): void {
		$captured = null;
		$this->shareMapper->method('createFromArray')->willReturnCallback(
			function (array $data) use (&$captured): FederatedShare {
				$captured = $data;
				return new FederatedShare();
			}
		);

		$this->service->createOutgoingShare(
			['scope' => 'query', 'schema' => 'zaak', 'queryFilter' => ['status' => 'open', '_ids' => ['a']], 'permissions' => 'read']
		);

		$this->assertSame(['status' => 'open', '_ids' => ['a']], $captured['queryFilter']);
	}//end testAPlainQueryFilterIsKept()

	/**
	 * A share's token is listed only to a caller who may grant that share.
	 *
	 * @return void
	 */
	public function testListingHidesTheTokenOfASharePastTheCallersRights(): void {
		$mine = (new FederatedShare())->hydrate(object: ['schema' => 'mine', 'shareToken' => 'token-mine']);
		$theirs = (new FederatedShare())->hydrate(object: ['schema' => 'theirs', 'shareToken' => 'token-theirs']);
		$this->shareMapper->method('findAll')->willReturn([$mine, $theirs]);
		$this->refusal = static fn (FederatedShare $share): ?string => ($share->getSchema() === 'theirs' ? 'no' : null);

		$listed = $this->service->listShares();

		$this->assertSame(['token-mine', null], array_column($listed, 'shareToken'));
		$this->assertSame(['mine', 'theirs'], array_column($listed, 'schema'));
	}//end testListingHidesTheTokenOfASharePastTheCallersRights()

	/**
	 * Revoking needs the rights the share grants; a refused revoke writes nothing.
	 *
	 * @return void
	 */
	public function testRevokingASharePastTheCallersRightsIsRefused(): void {
		$stored = (new FederatedShare())->hydrate(object: ['schema' => 'zaak']);
		$this->shareMapper->method('find')->with(7)->willReturn($stored);
		$this->refusal = static fn (FederatedShare $share): ?string => 'no';
		$this->shareMapper->expects($this->never())->method('updateFromArray');

		try {
			$this->service->setStatus(id: 7, status: 'revoked');
			$this->fail('the revoke was not refused');
		} catch (NotAuthorizedException $e) {
			$this->assertSame([$stored], $this->judged);
		}
	}//end testRevokingASharePastTheCallersRightsIsRefused()

	/**
	 * A caller who holds the rights revokes the share.
	 *
	 * @return void
	 */
	public function testRevokingAShareWithinTheCallersRightsWrites(): void {
		$stored = new FederatedShare();
		$this->shareMapper->method('find')->willReturn($stored);
		$this->shareMapper->expects($this->once())->method('updateFromArray')
			->with(7, ['status' => 'revoked'])->willReturn($stored);

		$this->assertSame($stored, $this->service->setStatus(id: 7, status: 'revoked'));
		$this->assertSame([$stored], $this->judged);
	}//end testRevokingAShareWithinTheCallersRightsWrites()

	/**
	 * A failing organisation lookup leaves the share without one.
	 *
	 * @return void
	 */
	public function testAFailingOrganisationLookupLeavesNoOrganisation(): void {
		$grantor = $this->createMock(ShareGrantor::class);
		$grantor->method('organisation')->willThrowException(new \RuntimeException('down'));
		$grantor->method('refusal')->willReturn(null);
		$captured = null;
		$this->shareMapper->method('createFromArray')->willReturnCallback(
			function (array $data) use (&$captured): FederatedShare {
				$captured = $data;
				return new FederatedShare();
			}
		);
		$service = new FederationShareService($this->shareMapper, $grantor, $this->secureRandom, $this->createMock(LoggerInterface::class));

		$service->createOutgoingShare(['scope' => 'schema', 'schema' => 'zaak', 'permissions' => 'read']);

		$this->assertNull($captured['organisation']);
	}//end testAFailingOrganisationLookupLeavesNoOrganisation()

	/**
	 * ensureObjectShare returns the existing share and never creates a duplicate.
	 *
	 * @return void
	 */
	public function testEnsureObjectShareIsIdempotent(): void {
		$existing = new FederatedShare();
		$this->shareMapper->method('findOutgoingObjectShare')->willReturn($existing);
		$this->shareMapper->expects($this->never())->method('createFromArray');

		$result = $this->service->ensureObjectShare(
			'urn:uuid:1',
			'1',
			'2',
			'partner@remote.example'
		);

		$this->assertSame($existing, $result);
	}//end testEnsureObjectShareIsIdempotent()

	/**
	 * ensureObjectShare creates an object-scope share when none exists yet.
	 *
	 * @return void
	 */
	public function testEnsureObjectShareCreatesWhenMissing(): void {
		$captured = null;
		$this->shareMapper->method('findOutgoingObjectShare')->willReturn(null);
		$this->shareMapper->expects($this->once())
			->method('createFromArray')
			->willReturnCallback(
				function (array $data) use (&$captured): FederatedShare {
					$captured = $data;
					return new FederatedShare();
				}
			);

		$this->service->ensureObjectShare(
			'urn:uuid:9',
			'1',
			'2',
			'partner@remote.example',
			'read'
		);

		$this->assertSame('object', $captured['scope']);
		$this->assertSame('urn:uuid:9', $captured['objectUri']);
		$this->assertSame('partner@remote.example', $captured['sharedWith']);
	}//end testEnsureObjectShareCreatesWhenMissing()

	/**
	 * A service whose log lines are collected.
	 *
	 * @param array<int, array{0: string, 1: string, 2: array}> $lines Collected [level, message, context].
	 *
	 * @return FederationShareService
	 */
	private function loggingService(array &$lines): FederationShareService {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string|\Stringable $message, array $context = []) use (&$lines, $level): void {
					$lines[] = [$level, (string)$message, $context];
				}
			);
		}

		return new FederationShareService($this->shareMapper, $this->grantor, $this->secureRandom, $logger);
	}//end loggingService()

	/**
	 * aud-f1: a created share is logged with its actor and scope, never its token.
	 *
	 * @return void
	 */
	public function testACreatedShareIsLoggedWithoutItsToken(): void {
		$lines = [];
		$this->shareMapper->method('createFromArray')->willReturn(new FederatedShare());

		$this->loggingService($lines)->createOutgoingShare(['scope' => 'schema', 'schema' => 'zaak', 'permissions' => 'read-write']);

		$this->assertSame('info', $lines[0][0] ?? null);
		$this->assertSame('alice', $lines[0][2]['actor'] ?? null);
		$this->assertSame('read-write', $lines[0][2]['permissions'] ?? null);
		$this->assertStringNotContainsString(str_repeat('a', 48), json_encode($lines));
	}//end testACreatedShareIsLoggedWithoutItsToken()

	/**
	 * aud-f1: a refused share and a refused revoke are logged as warnings.
	 *
	 * @return void
	 */
	public function testRefusalsAreLogged(): void {
		$lines = [];
		$this->refusal = static fn (FederatedShare $share): ?string => 'needs manage rights';
		$this->shareMapper->method('find')->willReturn(new FederatedShare());
		$service = $this->loggingService($lines);

		foreach ([
			fn () => $service->createOutgoingShare(['scope' => 'schema', 'schema' => 'zaak', 'permissions' => 'read']),
			fn () => $service->setStatus(id: 7, status: 'revoked'),
		] as $call) {
			try {
				$call();
				$this->fail('refused');
			} catch (NotAuthorizedException $e) {
				// Expected.
			}
		}

		$this->assertSame(['warning', 'warning'], array_column($lines, 0));
		$this->assertSame(7, $lines[1][2]['share'] ?? null);
	}//end testRefusalsAreLogged()

	/**
	 * aud-f1: a revoke is logged with its actor.
	 *
	 * @return void
	 */
	public function testARevokeIsLogged(): void {
		$lines = [];
		$this->shareMapper->method('find')->willReturn(new FederatedShare());
		$this->shareMapper->method('updateFromArray')->willReturn(new FederatedShare());

		$this->loggingService($lines)->setStatus(id: 7, status: 'revoked');

		$this->assertSame([['info', '[Federation] Changed a share status', ['actor' => 'alice', 'share' => 7, 'status' => 'revoked']]], $lines);
	}//end testARevokeIsLogged()
}//end class
