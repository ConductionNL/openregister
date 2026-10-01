<?php

/**
 * MagicRbacHandler multitenancy-bypass register cascade tests
 *
 * The multitenancy-bypass verdict must read the SAME resolved authorization as
 * the RBAC filter: the schema's own block, else its register's block with the
 * register roles expanded. These tests wire a REAL PermissionHandler (only the
 * register lookup is a double) so the cascade under test is the production one.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests that hasConditionalRulesBypassingMultitenancy() honours the register cascade.
 */
class MagicRbacHandlerRegisterCascadeBypassTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var MagicRbacHandler
	 */
	private MagicRbacHandler $handler;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Register lookup double (the only part of the cascade that is not real).
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * Build the real PermissionHandler and the subject under test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$this->registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$conditionMatcher = $this->createMock(originalClassName: ConditionMatcher::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(true);

		$container = $this->createMock(originalClassName: ContainerInterface::class);

		$permissionHandler = new PermissionHandler(
			$this->userSession,
			$userManager,
			$this->groupManager,
			$this->createMock(originalClassName: SchemaMapper::class),
			$this->createMock(originalClassName: MagicMapper::class),
			$conditionMatcher,
			$appConfig,
			$logger,
			$container
		);

		$container->method('get')->willReturnCallback(
			function (string $service) use ($permissionHandler) {
				if ($service === PermissionHandler::class) {
					return $permissionHandler;
				}

				if ($service === RegisterMapper::class) {
					return $this->registerMapper;
				}

				throw new RuntimeException(message: 'Unexpected service ' . $service);
			}
		);

		$this->handler = new MagicRbacHandler(
			$this->userSession,
			$this->groupManager,
			$userManager,
			$appConfig,
			$conditionMatcher,
			$container,
			$logger
		);

	}//end setUp()

	/**
	 * Give the schema a learniq-shaped parent register: a `read-write` role
	 * granted to `instructors` and `hr`, and no schema-level block.
	 *
	 * @return void
	 */
	private function wireLearniqRegister(): void {
		$register = new Register();
		$register->setId(99);
		$register->setTitle('learniq');
		$register->setAuthorization(['roles' => ['read-write' => ['instructors', 'hr']]]);
		$register->setConfiguration(
			[
				'roles' => [
					['name' => 'read-write', 'actions' => ['read', 'create', 'update']],
				],
			]
		);

		$this->registerMapper->method('getFirstRegisterWithSchema')->willReturn(99);
		$this->registerMapper->method('find')->willReturn($register);

	}//end wireLearniqRegister()

	/**
	 * Build a schema fixture.
	 *
	 * @param array|null $authorization The schema's own authorization block.
	 *
	 * @return Schema
	 */
	private function createSchema(?array $authorization): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setTitle('School');
		$schema->setAuthorization($authorization);

		return $schema;

	}//end createSchema()

	/**
	 * Log a user in with the given groups.
	 *
	 * @param string $uid    The user id.
	 * @param array  $groups The user's groups.
	 *
	 * @return void
	 */
	private function mockUser(string $uid, array $groups): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($groups);

	}//end mockUser()

	/**
	 * A schema with no block of its own inherits its register's role, so a
	 * member of a granted group escapes the organisation filter exactly as the
	 * RBAC filter already lets them read.
	 *
	 * @return void
	 */
	public function testRegisterRoleGrantBypassesMultitenancyForGrantedGroup(): void {
		$this->wireLearniqRegister();
		$this->mockUser(uid: 'po-leerkracht-09', groups: ['instructors']);

		$this->assertTrue(
			condition: $this->handler->hasConditionalRulesBypassingMultitenancy(
				schema: $this->createSchema(authorization: null),
				action: 'read'
			)
		);

	}//end testRegisterRoleGrantBypassesMultitenancyForGrantedGroup()

	/**
	 * A user outside every granted group gets no bypass, so the organisation
	 * filter still applies and the org-less row stays hidden.
	 *
	 * @return void
	 */
	public function testRegisterRoleGrantDoesNotBypassForUngrantedUser(): void {
		$this->wireLearniqRegister();
		$this->mockUser(uid: 'leerling-01', groups: ['students']);

		$this->assertFalse(
			condition: $this->handler->hasConditionalRulesBypassingMultitenancy(
				schema: $this->createSchema(authorization: null),
				action: 'read'
			)
		);

	}//end testRegisterRoleGrantDoesNotBypassForUngrantedUser()

	/**
	 * The register role grants read, not delete, so the delete verdict gets
	 * no bypass from it.
	 *
	 * @return void
	 */
	public function testRegisterRoleGrantDoesNotBypassForUngrantedAction(): void {
		$this->wireLearniqRegister();
		$this->mockUser(uid: 'po-leerkracht-09', groups: ['instructors']);

		$this->assertFalse(
			condition: $this->handler->hasConditionalRulesBypassingMultitenancy(
				schema: $this->createSchema(authorization: null),
				action: 'delete'
			)
		);

	}//end testRegisterRoleGrantDoesNotBypassForUngrantedAction()

	/**
	 * A schema with its own block is unchanged: the register role does not
	 * leak into it, and the schema's own grant still bypasses.
	 *
	 * @return void
	 */
	public function testSchemaOwnBlockStillWinsOverRegister(): void {
		$this->wireLearniqRegister();
		$schema = $this->createSchema(authorization: ['read' => ['teachers']]);

		$this->mockUser(uid: 'po-leerkracht-09', groups: ['instructors']);
		$this->assertFalse(
			condition: $this->handler->hasConditionalRulesBypassingMultitenancy(schema: $schema, action: 'read')
		);

	}//end testSchemaOwnBlockStillWinsOverRegister()

	/**
	 * The schema's own grant still bypasses for its own group.
	 *
	 * @return void
	 */
	public function testSchemaOwnBlockGrantStillBypasses(): void {
		$this->wireLearniqRegister();
		$this->mockUser(uid: 'juf-01', groups: ['teachers']);

		$this->assertTrue(
			condition: $this->handler->hasConditionalRulesBypassingMultitenancy(
				schema: $this->createSchema(authorization: ['read' => ['teachers']]),
				action: 'read'
			)
		);

	}//end testSchemaOwnBlockGrantStillBypasses()

	/**
	 * An organisation-scoped conditional rule on the register still keeps the
	 * organisation filter: a `_organisation`-only match is not a bypass.
	 *
	 * @return void
	 */
	public function testRegisterOrganisationOnlyMatchDoesNotBypass(): void {
		$register = new Register();
		$register->setId(99);
		$register->setTitle('learniq');
		$register->setAuthorization(
			['read' => [['group' => 'instructors', 'match' => ['_organisation' => '$organisation']]]]
		);
		$this->registerMapper->method('getFirstRegisterWithSchema')->willReturn(99);
		$this->registerMapper->method('find')->willReturn($register);
		$this->mockUser(uid: 'po-leerkracht-09', groups: ['instructors']);

		$this->assertFalse(
			condition: $this->handler->hasConditionalRulesBypassingMultitenancy(
				schema: $this->createSchema(authorization: null),
				action: 'read'
			)
		);

	}//end testRegisterOrganisationOnlyMatchDoesNotBypass()

	/**
	 * An unresolvable register keeps the organisation filter (fail closed).
	 *
	 * @return void
	 */
	public function testUnresolvableRegisterDoesNotBypass(): void {
		$this->registerMapper->method('getFirstRegisterWithSchema')
			->willThrowException(new RuntimeException(message: 'database gone'));
		$this->mockUser(uid: 'po-leerkracht-09', groups: ['instructors']);

		$this->assertFalse(
			condition: $this->handler->hasConditionalRulesBypassingMultitenancy(
				schema: $this->createSchema(authorization: null),
				action: 'read'
			)
		);

	}//end testUnresolvableRegisterDoesNotBypass()
}//end class
