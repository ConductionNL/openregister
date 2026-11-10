<?php

/**
 * Golden tests for the case-token mint, resolve and revoke branches.
 *
 * Pins, on the code as it stood before those methods were split, the
 * branches CaseTokenServiceTest leaves open: the order of the two mint
 * refusals, the time-to-live rule, the exact fields and field order of the
 * minted row, every resolve exit that answers null before or after the read,
 * and every revoke lookup (token, numeric id, neither) with its refusal.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use DateTime;
use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\CaseToken;
use OCA\OpenRegister\Db\CaseTokenMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\CaseTokenIssuance;
use OCA\OpenRegister\Service\CaseTokenService;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden tests for the CaseTokenService branches.
 */
class CaseTokenServiceGoldenTest extends TestCase {

	/**
	 * Mocked token mapper.
	 *
	 * @var CaseTokenMapper&MockObject
	 */
	private CaseTokenMapper&MockObject $mapper;

	/**
	 * Services the container answers, by id.
	 *
	 * @var array<string, mixed>
	 */
	private array $services = [];

	/**
	 * Create the mapper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(CaseTokenMapper::class);
	}//end setUp()

	/**
	 * The service, minting as the given user (or anonymously).
	 *
	 * @param string|null $uid The minter, or null for none.
	 *
	 * @return CaseTokenService
	 */
	private function service(?string $uid = 'alice'): CaseTokenService {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$rng = $this->createMock(ISecureRandom::class);
		$rng->method('generate')->with(43, ISecureRandom::CHAR_ALPHANUMERIC)->willReturn('TOKEN');

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $args): string => '/' . $route . '/' . $args['token']
		);
		$url->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://nc' . $path);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): mixed {
				if (array_key_exists($id, $this->services) === true) {
					return $this->services[$id];
				}

				throw new RuntimeException('no ' . $id);
			}
		);

		return new CaseTokenService(
			new CaseTokenIssuance($this->mapper, $rng, $url),
			$session,
			$this->createMock(LoggerInterface::class),
			$container
		);
	}//end service()

	/**
	 * Capture the row mint() inserts.
	 *
	 * @return \ArrayObject<int, CaseToken> Filled with each inserted row once mint() ran.
	 */
	private function captureInsert(): \ArrayObject {
		$captured = new \ArrayObject();
		$this->mapper->method('insert')->willReturnCallback(
			static function (CaseToken $row) use ($captured): CaseToken {
				$captured[] = $row;
				return $row;
			}
		);

		return $captured;
	}//end captureInsert()

	/**
	 * A stored token.
	 *
	 * @param string $objectUuid Object it is minted against.
	 * @param bool   $revoked    Whether it is revoked.
	 *
	 * @return CaseToken
	 */
	private function token(string $objectUuid, bool $revoked = false): CaseToken {
		$row = new CaseToken();
		$row->setToken('GOOD');
		$row->setObjectUuid($objectUuid);
		$row->setLabel('Label');
		$row->setRevokedAt(null);
		if ($revoked === true) {
			$row->setRevokedAt(new DateTime('-1 hour'));
		}

		$row->setExpiresAt(null);

		return $row;
	}//end token()

	/**
	 * An empty object uuid is refused before the minter is looked at.
	 *
	 * @return void
	 */
	public function testMintRefusesBlankUuidBeforeTheAnonymousCheck(): void {
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('objectUuid is required to mint a case token');

		$this->service(null)->mint('   ');
	}//end testMintRefusesBlankUuidBeforeTheAnonymousCheck()

	/**
	 * An anonymous minter is refused.
	 *
	 * @return void
	 */
	public function testMintRefusesAnonymous(): void {
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A logged-in user is required to mint a case token');

		$this->service(null)->mint('obj-1');
	}//end testMintRefusesAnonymous()

	/**
	 * The minted row carries exactly these fields, in this order.
	 *
	 * @return void
	 */
	public function testMintWritesTheRowFieldsInOrder(): void {
		$captured = $this->captureInsert();

		$result = $this->service()->mint('obj-1', 3, 9, 'Label', 120);

		$row = $captured[0];
		$this->assertSame(
			['token', 'objectUuid', 'registerId', 'schemaId', 'label', 'createdBy', 'createdAt', 'expiresAt'],
			array_keys($row->getUpdatedFields())
		);
		$this->assertSame('TOKEN', $row->getToken());
		$this->assertSame(3, $row->getRegisterId());
		$this->assertSame(9, $row->getSchemaId());
		$this->assertSame('alice', $row->getCreatedBy());
		$this->assertNull($row->getRevokedAt());
		$this->assertSame(120, ($row->getExpiresAt()->getTimestamp() - $row->getCreatedAt()->getTimestamp()));
		$this->assertSame('https://nc/openregister.caseToken.resolve/TOKEN', $result['url']);
	}//end testMintWritesTheRowFieldsInOrder()

	/**
	 * Without a positive time-to-live the token never expires.
	 *
	 * @return void
	 */
	public function testMintWithoutPositiveTtlNeverExpires(): void {
		$captured = $this->captureInsert();
		$service = $this->service();

		$service->mint('obj-1');
		$service->mint('obj-1', null, null, null, 0);
		$service->mint('obj-1', null, null, null, -5);

		$this->assertCount(3, $captured);
		foreach ($captured as $row) {
			$this->assertNull($row->getExpiresAt());
			$this->assertSame(
				['token', 'objectUuid', 'createdBy', 'createdAt'],
				array_keys($row->getUpdatedFields())
			);
		}
	}//end testMintWithoutPositiveTtlNeverExpires()

	/**
	 * A blank token is refused without a lookup.
	 *
	 * @return void
	 */
	public function testResolveBlankTokenDoesNotLookUp(): void {
		$this->mapper->expects($this->never())->method('findByToken');

		$this->assertNull($this->service()->resolve(' '));
	}//end testResolveBlankTokenDoesNotLookUp()

	/**
	 * A valid token with no object service answers null.
	 *
	 * @return void
	 */
	public function testResolveWithoutObjectServiceIsNull(): void {
		$this->mapper->method('findByToken')->willReturn($this->token('obj-1'));

		$this->assertNull($this->service()->resolve('GOOD'));
	}//end testResolveWithoutObjectServiceIsNull()

	/**
	 * A valid token over a missing object answers null; a found one the view.
	 *
	 * @return void
	 */
	public function testResolveMissingAndFoundObject(): void {
		$this->mapper->method('findByToken')->willReturn($this->token('obj-1'));
		$objects = new class {
			public ?object $found = null;

			public array $findArgs = [];

			public function find(mixed ...$args): ?object {
				$flat           = FindCallShape::find(args: [$args['id'], ($args['options'] ?? null), $args['_rbac'], $args['_multitenancy']]);
				$this->findArgs = array_combine(['id', '_extend', 'files', 'register', 'schema', '_rbac', '_multitenancy'], array_slice($flat, 0, 7));
				return $this->found;
			}//end find()

			public function renderEntity(mixed ...$args): array {
				return ['rendered' => true, 'rbac' => $args['_rbac'], 'tenancy' => $args['_multitenancy']];
			}//end renderEntity()
		};
		$this->services['OCA\\OpenRegister\\Service\\ObjectService'] = $objects;
		$service = $this->service();

		$this->assertNull($service->resolve('GOOD'));
		$this->assertSame(
			['id' => 'obj-1', '_extend' => [], 'files' => false, 'register' => null, 'schema' => null, '_rbac' => true, '_multitenancy' => true],
			$objects->findArgs
		);

		$objects->found = new ObjectEntity();
		$this->assertSame(
			[
				'token' => 'GOOD',
				'label' => 'Label',
				'object' => ['rendered' => true, 'rbac' => true, 'tenancy' => true],
				'timeline' => [],
			],
			$service->resolve('GOOD')
		);
	}//end testResolveMissingAndFoundObject()

	/**
	 * A revoked token is refused before the object service is asked.
	 *
	 * @return void
	 */
	public function testResolveRevokedNeverReads(): void {
		$this->mapper->method('findByToken')->willReturn($this->token('obj-1', true));
		$objects = new class {
			public int $calls = 0;

			public function find(mixed ...$args): ?object {
				$this->calls++;
				return null;
			}//end find()
		};
		$this->services['OCA\\OpenRegister\\Service\\ObjectService'] = $objects;

		$this->assertNull($this->service()->resolve('GOOD'));
		$this->assertSame(0, $objects->calls);
	}//end testResolveRevokedNeverReads()

	/**
	 * An unknown non-numeric token is refused without an id lookup.
	 *
	 * @return void
	 */
	public function testRevokeUnknownTokenIs404WithoutIdLookup(): void {
		$this->mapper->method('findByToken')->willReturn(null);
		$this->mapper->expects($this->never())->method('findById');
		$this->mapper->expects($this->never())->method('update');

		try {
			$this->service()->revoke('obj-1', 'abc12');
			$this->fail('expected a refusal');
		} catch (Exception $e) {
			$this->assertSame(Exception::class, get_class($e));
			$this->assertSame('Token not found', $e->getMessage());
			$this->assertSame(404, $e->getCode());
		}
	}//end testRevokeUnknownTokenIs404WithoutIdLookup()

	/**
	 * An unknown numeric id is refused.
	 *
	 * @return void
	 */
	public function testRevokeUnknownIdIs404(): void {
		$this->mapper->method('findByToken')->willReturn(null);
		$this->mapper->expects($this->once())->method('findById')->with(42)->willReturn(null);

		$this->expectExceptionCode(404);
		$this->service()->revoke('obj-1', '42');
	}//end testRevokeUnknownIdIs404()

	/**
	 * A numeric id of another object's token is refused; of this object's, revoked.
	 *
	 * @return void
	 */
	public function testRevokeByIdChecksTheObject(): void {
		$own = $this->token('obj-1');
		$this->mapper->method('findByToken')->willReturn(null);
		$this->mapper->method('findById')->willReturnOnConsecutiveCalls($this->token('obj-2'), $own);
		$this->mapper->expects($this->once())->method('update')->with($own);
		$service = $this->service();

		try {
			$service->revoke('obj-1', '7');
			$this->fail('expected a refusal');
		} catch (Exception $e) {
			$this->assertSame(404, $e->getCode());
		}

		$this->assertTrue($service->revoke('obj-1', '7'));
		$this->assertNotNull($own->getRevokedAt());
	}//end testRevokeByIdChecksTheObject()

	/**
	 * A token found by its string is never looked up by id.
	 *
	 * @return void
	 */
	public function testRevokeFoundByTokenSkipsIdLookup(): void {
		$this->mapper->method('findByToken')->with('123')->willReturn($this->token('obj-1', true));
		$this->mapper->expects($this->never())->method('findById');
		$this->mapper->expects($this->never())->method('update');

		$this->assertFalse($this->service()->revoke('obj-1', '123'));
	}//end testRevokeFoundByTokenSkipsIdLookup()
}//end class
