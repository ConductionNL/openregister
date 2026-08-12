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
use OCA\OpenRegister\Service\FederationShareService;
use OCA\OpenRegister\Service\OrganisationService;
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
	 * The organisation service mock.
	 *
	 * @var OrganisationService
	 */
	private $organisationService;

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
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);

		$this->secureRandom->method('generate')->willReturn(str_repeat('a', 48));

		$this->service = new FederationShareService(
			$this->shareMapper,
			$this->organisationService,
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
	}//end testCreateOutgoingSharePersistsOutgoingAcceptedWithToken()

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
}//end class
