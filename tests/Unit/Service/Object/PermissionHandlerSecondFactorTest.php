<?php

/**
 * The find path refuses a read of a scope requiring a second factor to a caller without one (REQ-IHC-003).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\ConditionUserResolvers;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCA\OpenRegister\Service\Object\PermissionCheckOptions;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\Rbac\SecondFactorReadGate;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory;
use OCP\Authentication\TwoFactorAuth\IRegistry;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Service\Rbac\SecondFactorReadGate
 */
final class PermissionHandlerSecondFactorTest extends TestCase {

	private const GROUPS = ['ann' => ['medewerkers'], 'root' => ['admin']];

	/**
	 * The signed-in user.
	 *
	 * @var string
	 */
	private string $current = 'ann';

	/**
	 * Whether the users have a second factor enabled.
	 *
	 * @var boolean
	 */
	private bool $secondFactor = false;

	/**
	 * The configuration of the one register holding the schema.
	 *
	 * @var array<string, mixed>
	 */
	private array $registerConfiguration = [];

	/**
	 * The handler under test.
	 *
	 * @var PermissionHandler
	 */
	private PermissionHandler $handler;

	protected function setUp(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturnCallback(fn (): IUser => $this->user($this->current));
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(fn (string $uid): IUser => $this->user($uid));
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturnCallback(static fn (IUser $user): array => (self::GROUPS[$user->getUID()] ?? []));
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => ($uid === 'root'));

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('getFirstRegisterWithSchema')->willReturn(20);
		$registerMapper->method('getAllRegisterIdsWithSchema')->willReturn([20]);
		$registerMapper->method('find')->willReturnCallback(
			function (): Register {
				$register = new Register();
				$register->setId(20);
				$register->setTitle('Jeugdzorg');
				$register->setAuthorization(null);
				$register->setConfiguration($this->registerConfiguration);

				return $register;
			}
		);

		$registry = $this->createMock(IRegistry::class);
		$registry->method('getProviderStates')->willReturnCallback(fn (): array => ['totp' => $this->secondFactor]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $class) use ($registerMapper, $registry, $userManager) {
				if ($class === RegisterMapperInterface::class) {
					return $registerMapper;
				}

				if ($class === SecondFactorReadGate::class) {
					// A fresh gate per lookup, so a changed factor is read again.
					return new SecondFactorReadGate($registry, $userManager, $this->createMock(ISession::class), $registerMapper, new NullLogger());
				}

				throw new \RuntimeException('Not available: ' . $class);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(static fn (string $app, string $key, bool $default = false): bool => $default);

		$matcher = new ConditionMatcher(
			new ConditionUserResolvers($userSession, $groupManager),
			$this->createMock(ContainerInterface::class),
			new OperatorEvaluator(new NullLogger()),
			new NullLogger()
		);

		$this->handler = PermissionHandlerFactory::create(
			$userSession,
			$userManager,
			$groupManager,
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			$matcher,
			$appConfig,
			new NullLogger(),
			$container
		);
	}//end setUp()

	public function testAConfidentialSchemaRefusesAReaderWithoutTheFactor(): void {
		$this->assertFalse($this->may('ann', $this->schema(declares: true), 'read'));
	}//end testAConfidentialSchemaRefusesAReaderWithoutTheFactor()

	public function testTheSameReaderWithTheFactorReads(): void {
		$this->secondFactor = true;

		$this->assertTrue($this->may('ann', $this->schema(declares: true), 'read'));
	}//end testTheSameReaderWithTheFactorReads()

	public function testAnAdministratorWithoutTheFactorIsRefusedToo(): void {
		$this->assertFalse($this->may('root', $this->schema(declares: true), 'read'));
	}//end testAnAdministratorWithoutTheFactorIsRefusedToo()

	public function testARegisterDeclaringItRefusesTheSchemasReaders(): void {
		$this->registerConfiguration = [SecondFactorReadGate::ANNOTATION => true];

		$this->assertFalse($this->may('ann', $this->schema(declares: false), 'read'));
	}//end testARegisterDeclaringItRefusesTheSchemasReaders()

	public function testAnInstanceDeclaringNothingReadsAsBefore(): void {
		$this->assertTrue($this->may('ann', $this->schema(declares: false), 'read'));
		$this->assertTrue($this->may('root', $this->schema(declares: false), 'read'));
	}//end testAnInstanceDeclaringNothingReadsAsBefore()

	public function testAWriteIsNotGatedByTheReadRequirement(): void {
		$this->assertTrue($this->may('ann', $this->schema(declares: true), 'update'));
	}//end testAWriteIsNotGatedByTheReadRequirement()

	public function testTheRefusalNamesTheRequirement(): void {
		$this->expectException(NotAuthorizedException::class);
		$this->expectExceptionMessage("Reading schema 'Dossiers' requires a verified second factor");

		$this->handler->checkPermission(
			schema: $this->schema(declares: true),
			action: 'read',
			options: new PermissionCheckOptions(object: $this->object())
		);
	}//end testTheRefusalNamesTheRequirement()

	private function may(string $uid, Schema $schema, string $action): bool {
		$this->current = $uid;
		$this->handler->clearPermissionCache();

		return $this->handler->hasPermission(
			ask: (new PermissionAsk(schema: $schema, action: $action))
				->askedBy(userId: null, objectOwner: 'someone-else')
				->about(object: $this->object(), register: null)
		);
	}//end may()

	private function schema(bool $declares): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setTitle('Dossiers');
		$schema->setAuthorization(['read' => ['medewerkers'], 'update' => ['medewerkers']]);
		$schema->setConfiguration([Schema::SECOND_FACTOR_ANNOTATION => $declares]);

		return $schema;
	}//end schema()

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('11111111-2222-3333-4444-555555555555');
		$object->setOwner('someone-else');
		$object->setObject(['title' => 'Dossier']);

		return $object;
	}//end object()

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($uid);

		return $user;
	}//end user()
}//end class
