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
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\OpenRegister\Service\Federation\ShareCreatorCheck::class)
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
	 * The owner is the session user, whatever the request names.
	 *
	 * @return void
	 */
	public function testTheOwnerIsTheSessionUserNotARequestValue(): void {
		$captured = null;
		$this->shareMapper->method('createFromArray')->willReturnCallback(
			function (array $data) use (&$captured): FederatedShare {
				$captured = $data;
				return new FederatedShare();
			}
		);

		$this->service->createOutgoingShare(['scope' => 'schema', 'schema' => 'zaak', 'permissions' => 'read', 'owner' => 'mallory']);

		$this->assertSame('alice', $captured['owner']);
	}//end testTheOwnerIsTheSessionUserNotARequestValue()

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
		$service = new FederationShareService($this->shareMapper, $grantor, $this->secureRandom, $this->createMock(LoggerInterface::class), $this->createMock(\OCA\OpenRegister\Service\Federation\ShareCreatorCheck::class));

		$service->createOutgoingShare(['scope' => 'schema', 'schema' => 'zaak', 'permissions' => 'read']);

		$this->assertNull($captured['organisation']);
	}//end testAFailingOrganisationLookupLeavesNoOrganisation()

	/**
	 * An incoming share is recorded as pending, and no remote value decides its status.
	 *
	 * @return void
	 */
	public function testAnIncomingShareIsRecordedAsPending(): void {
		$captured = [];
		$this->shareMapper->method('createFromArray')->willReturnCallback(
			function (array $data) use (&$captured): FederatedShare {
				$captured = $data;
				return new FederatedShare();
			}
		);

		$this->service->recordIncomingShare(
			[
				'remoteInstanceUrl' => 'https://remote.example',
				'remoteProviderId'  => 'p-1',
				'shareToken'        => 'tok',
				'register'          => 2,
				'schema'            => 3,
				'objectUri'         => 'u',
				'sharedWith'        => 'bob',
				'status'            => 'accepted',
				'direction'         => 'outgoing',
			]
		);

		$this->assertSame(
			[
				'direction'         => 'incoming',
				'remoteInstanceUrl' => 'https://remote.example',
				'remoteProviderId'  => 'p-1',
				'shareToken'        => 'tok',
				'scope'             => 'schema',
				'register'          => 2,
				'schema'            => 3,
				'objectUri'         => 'u',
				'permissions'       => 'read',
				'organisation'      => 'org-a',
				'sharedWith'        => 'bob',
				'status'            => 'pending',
			],
			$captured
		);
	}//end testAnIncomingShareIsRecordedAsPending()

}//end class
