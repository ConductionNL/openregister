<?php

/**
 * A federated recipient judged by the deny rules, as one more principal (REQ-PCF-001, D-1).
 *
 * The gate is tested over a real PermissionHandler, so "the same permission
 * layer as a local principal" is measured in the evaluator itself: the
 * cascade, the deny pass and the enforcement switch.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Federation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Federation;

use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Federation\RemoteRecipientGate;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The gate over the real evaluator.
 *
 * @covers \OCA\OpenRegister\Service\Federation\RemoteRecipientGate
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 */
class RemoteRecipientGateTest extends TestCase {

	/**
	 * The recipient's cloud id.
	 *
	 * @var string
	 */
	private const CLOUD_ID = 'alice@other.example';

	/**
	 * Hands out a distinct schema id per schema.
	 *
	 * @var integer
	 */
	private int $schemaCounter = 4100;

	/**
	 * The schema the schema mapper answers, or null to make it throw.
	 *
	 * @var Schema|null
	 */
	private ?Schema $schema = null;

	/**
	 * The gate over a real handler in the given deny enforcement mode.
	 *
	 * @param string $mode The deny enforcement mode.
	 *
	 * @return RemoteRecipientGate The gate.
	 */
	private function gate(string $mode = DenyEnforcementMode::MODE_ENFORCING): RemoteRecipientGate {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);
		$appConfig->method('getValueString')->willReturn($mode);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$logger = new NullLogger();
		$handler = PermissionHandlerFactory::create(
			userSession: $userSession,
			userManager: $this->createMock(IUserManager::class),
			groupManager: $this->createMock(IGroupManager::class),
			schemaMapper: $this->createMock(SchemaMapper::class),
			objectEntityMapper: $this->createMock(MagicMapper::class),
			conditionMatcher: $this->createMock(ConditionMatcher::class),
			appConfig: $appConfig,
			logger: $logger,
			container: $this->container(),
			objectScopeResolver: new ObjectScopeResolver(),
			denyResolver: new DenyResolver(new DenyEntryMatcher()),
			denyEnforcementMode: new DenyEnforcementMode($appConfig, $logger)
		);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			function (): Schema {
				if ($this->schema === null) {
					throw new RuntimeException('no such schema');
				}

				return $this->schema;
			}
		);

		return new RemoteRecipientGate(permissions: $handler, schemas: $schemas, logger: $logger);
	}//end gate()

	/**
	 * A container that knows a register and nothing else.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(): ContainerInterface {
		$register = new Register();
		$register->setId(9);
		$register->setSlug('zaken');

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('getFirstRegisterWithSchema')->willReturn(9);
		$registerMapper->method('find')->willReturn($register);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($registerMapper): mixed {
				if ($id === RegisterMapperInterface::class) {
					return $registerMapper;
				}

				throw new RuntimeException(sprintf('nothing registered for %s', $id));
			}
		);

		return $container;
	}//end container()

	/**
	 * Use a schema with this authorization block.
	 *
	 * @param array|null $authorization The block.
	 *
	 * @return void
	 */
	private function schemaWith(?array $authorization): void {
		$this->schemaCounter++;
		$this->schema = new Schema();
		$this->schema->setId($this->schemaCounter);
		$this->schema->setSlug('zaak');
		$this->schema->setAuthorization($authorization);
	}//end schemaWith()

	/**
	 * A share to the recipient, or to a token when no cloud id is given.
	 *
	 * @param string|null $sharedWith The cloud id.
	 *
	 * @return FederatedShare The share.
	 */
	private static function share(?string $sharedWith = self::CLOUD_ID): FederatedShare {
		$share = new FederatedShare();
		$share->setSharedWith($sharedWith);

		return $share;
	}//end share()

	/**
	 * An object, with its own block.
	 *
	 * @param array|null $authorization The object's block.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(?array $authorization = null): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('fed00000-0000-4000-8000-000000000001');
		$object->setSchema((string)($this->schema?->getId() ?? 1));
		$object->setObject(['title' => 'een zaak']);
		$object->setAuthorization($authorization);

		return $object;
	}//end object()

	/**
	 * The recipient is reached by `remote:*` and, when named, by its cloud id.
	 *
	 * @return void
	 */
	public function testTheRecipientsPrincipalNames(): void {
		$this->assertSame(['remote:*', 'remote:' . self::CLOUD_ID], RemoteRecipientGate::principalsOf(share: self::share()));
		$this->assertSame(['remote:*'], RemoteRecipientGate::principalsOf(share: self::share(sharedWith: null)));
		$this->assertSame(['remote:*'], RemoteRecipientGate::principalsOf(share: self::share(sharedWith: '  ')));
	}//end testTheRecipientsPrincipalNames()

	/**
	 * Without a deny, the share's grant stands.
	 *
	 * @return void
	 */
	public function testWithoutADenyNothingIsRefused(): void {
		$this->schemaWith(authorization: ['read' => ['medewerkers']]);

		$this->assertFalse($this->gate()->refuses(share: self::share(), object: $this->object(), verb: 'read'));
	}//end testWithoutADenyNothingIsRefused()

	/**
	 * A deny naming the recipient takes the verb from it, and only that verb.
	 *
	 * @return void
	 */
	public function testADenyNamingTheRecipientRefusesIt(): void {
		$this->schemaWith(authorization: ['read' => ['medewerkers'], 'deny' => ['read' => ['remote:' . self::CLOUD_ID]]]);

		$this->assertTrue($this->gate()->refuses(share: self::share(), object: $this->object(), verb: 'read'));
		$this->assertFalse($this->gate()->refuses(share: self::share(), object: $this->object(), verb: 'update'));
		$this->assertFalse($this->gate()->refuses(share: self::share(sharedWith: 'bob@other.example'), object: $this->object(), verb: 'read'));
	}//end testADenyNamingTheRecipientRefusesIt()

	/**
	 * `remote:*` reaches every federated recipient, a token share included.
	 *
	 * @return void
	 */
	public function testADenyOnEveryRemoteReachesATokenShareToo(): void {
		$this->schemaWith(authorization: ['deny' => ['update' => ['remote:*']]]);

		$this->assertTrue($this->gate()->refuses(share: self::share(), object: $this->object(), verb: 'update'));
		$this->assertTrue($this->gate()->refuses(share: self::share(sharedWith: null), object: $this->object(), verb: 'update'));
	}//end testADenyOnEveryRemoteReachesATokenShareToo()

	/**
	 * The object's own block is cascaded like for a local caller.
	 *
	 * @return void
	 */
	public function testTheObjectsOwnDenyIsCascaded(): void {
		$this->schemaWith(authorization: ['read' => ['medewerkers']]);

		$object = $this->object(authorization: ['deny' => ['read' => ['remote:' . self::CLOUD_ID]]]);

		$this->assertTrue($this->gate()->refuses(share: self::share(), object: $object, verb: 'read'));
	}//end testTheObjectsOwnDenyIsCascaded()

	/**
	 * A deny for local people does not reach a federated recipient, and the recipient is not `public`.
	 *
	 * @return void
	 */
	public function testLocalPrincipalsDoNotReachTheRecipient(): void {
		$this->schemaWith(authorization: ['deny' => ['read' => ['user:alice', 'public', 'authenticated']]]);

		$this->assertFalse($this->gate()->refuses(share: self::share(), object: $this->object(), verb: 'read'));
	}//end testLocalPrincipalsDoNotReachTheRecipient()

	/**
	 * The enforcement switch is honoured as for a local caller: staging and off admit.
	 *
	 * @return void
	 */
	public function testTheEnforcementSwitchIsHonoured(): void {
		$this->schemaWith(authorization: ['deny' => ['read' => ['remote:*']]]);

		$this->assertFalse($this->gate(mode: DenyEnforcementMode::MODE_STAGING)->refuses(share: self::share(), object: $this->object(), verb: 'read'));
		$this->assertFalse($this->gate(mode: DenyEnforcementMode::MODE_OFF)->refuses(share: self::share(), object: $this->object(), verb: 'read'));
	}//end testTheEnforcementSwitchIsHonoured()

	/**
	 * A schema that cannot be read refuses: the object is not served.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaRefuses(): void {
		$this->schema = null;

		$this->assertTrue($this->gate()->refuses(share: self::share(), object: $this->object(), verb: 'read'));
	}//end testAnUnreadableSchemaRefuses()
}//end class
