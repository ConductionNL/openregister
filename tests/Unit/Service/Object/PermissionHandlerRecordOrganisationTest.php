<?php

/**
 * The record's own organisation answers a rule on `_organisation`.
 *
 * A record's data can carry an `@self` block and underscore-prefixed keys of
 * its own; neither may answer a rule that matches on the record's metadata.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
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

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests that a record's data cannot stand in for its organisation.
 *
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Service\ConditionMatcher
 * @uses   \OCA\OpenRegister\Service\OperatorEvaluator
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 */
class PermissionHandlerRecordOrganisationTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var PermissionHandler
	 */
	private PermissionHandler $handler;

	/**
	 * Build the handler for `alice`, in group `editors`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$userManager->method('get')->willReturn($user);
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['editors']);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException(message: 'not available'));

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default = false): bool => $default
		);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === DenyEnforcementMode::CONFIG_KEY) {
					return DenyEnforcementMode::MODE_ENFORCING;
				}

				return $default;
			}
		);

		$logger = new NullLogger();
		$this->handler = new PermissionHandler(
			userSession: $userSession,
			userManager: $userManager,
			groupManager: $groupManager,
			schemaMapper: $this->createMock(originalClassName: SchemaMapper::class),
			objectEntityMapper: $this->createMock(originalClassName: MagicMapper::class),
			conditionMatcher: new ConditionMatcher(
				userSession: $userSession,
				container: $container,
				operatorEvaluator: new OperatorEvaluator(logger: $logger),
				logger: $logger
			),
			appConfig: $appConfig,
			logger: $logger,
			container: $container
		);

	}//end setUp()

	/**
	 * Data that names another organisation, by `@self` or by a body key.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function forgedData(): array {
		return [
			'an @self block'           => [['title' => 'x', '@self' => ['organisation' => 'org-a']]],
			'an _organisation key'     => [['title' => 'x', '_organisation' => 'org-a']],
			'both'                     => [['title' => 'x', '_organisation' => 'org-a', '@self' => ['organisation' => 'org-a']]],
		];

	}//end forgedData()

	/**
	 * An update rule bound to `org-a` does not admit an `org-b` record whose data names `org-a`.
	 *
	 * @param array<string, mixed> $data The record's data.
	 *
	 * @return void
	 *
	 * @dataProvider forgedData
	 */
	public function testTheRecordsDataDoesNotStandInForItsOrganisation(array $data): void {
		$this->assertFalse(
			condition: $this->handler->hasPermission(
				schema: $this->schema(),
				action: 'update',
				userId: 'alice',
				objectOwner: 'bob',
				object: $this->record(organisation: 'org-b', data: $data)
			)
		);

	}//end testTheRecordsDataDoesNotStandInForItsOrganisation()

	/**
	 * The record's own organisation still answers the rule.
	 *
	 * @return void
	 */
	public function testTheRecordsOwnOrganisationAnswers(): void {
		$this->assertTrue(
			condition: $this->handler->hasPermission(
				schema: $this->schema(),
				action: 'update',
				userId: 'alice',
				objectOwner: 'bob',
				object: $this->record(organisation: 'org-a', data: ['title' => 'x', '@self' => ['organisation' => 'org-b']])
			)
		);

	}//end testTheRecordsOwnOrganisationAnswers()

	/**
	 * A user override bound to `org-a` does not admit an `org-b` record whose data names `org-a`.
	 *
	 * @return void
	 */
	public function testAUserOverrideReadsTheRecordsOwnOrganisation(): void {
		$schema = new Schema();
		$schema->setId(93);
		$schema->setTitle('Delegated');
		$schema->setAuthorization(
			[
				'read'   => ['editors'],
				'update' => [['user' => 'alice', 'match' => ['_organisation' => 'org-a']]],
			]
		);

		$this->assertFalse(
			condition: $this->handler->hasPermission(
				schema: $schema,
				action: 'update',
				userId: 'alice',
				objectOwner: 'bob',
				object: $this->record(organisation: 'org-b', data: ['title' => 'x', '@self' => ['organisation' => 'org-a']])
			)
		);

		$own = $this->record(organisation: 'org-a', data: ['title' => 'x']);
		$own->setUuid('rec-3');
		$this->assertTrue(
			condition: $this->handler->hasPermission(schema: $schema, action: 'update', userId: 'alice', objectOwner: 'bob', object: $own)
		);

	}//end testAUserOverrideReadsTheRecordsOwnOrganisation()

	/**
	 * A conditional deny on `org-a` bites an `org-a` record whose data names another organisation.
	 *
	 * @return void
	 */
	public function testAConditionalDenyReadsTheRecordsOwnOrganisation(): void {
		$schema = new Schema();
		$schema->setId(92);
		$schema->setTitle('Frozen');
		$schema->setAuthorization(
			[
				'read'   => ['editors'],
				'update' => ['editors'],
				'deny'   => ['update' => [['group' => 'editors', 'match' => ['_organisation' => 'org-a']]]],
			]
		);

		$this->assertFalse(
			condition: $this->handler->hasPermission(
				schema: $schema,
				action: 'update',
				userId: 'alice',
				objectOwner: 'bob',
				object: $this->record(organisation: 'org-a', data: ['title' => 'x', '@self' => ['organisation' => 'org-b']])
			)
		);
		$other = $this->record(organisation: 'org-b', data: ['title' => 'x']);
		$other->setUuid('rec-2');
		$this->assertTrue(
			condition: $this->handler->hasPermission(
				schema: $schema,
				action: 'update',
				userId: 'alice',
				objectOwner: 'bob',
				object: $other
			)
		);

	}//end testAConditionalDenyReadsTheRecordsOwnOrganisation()

	/**
	 * A schema whose `editors` may update the records of `org-a`.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(91);
		$schema->setTitle('Guarded');
		$schema->setAuthorization(
			[
				'read'   => ['editors'],
				'update' => [['group' => 'editors', 'match' => ['_organisation' => 'org-a']]],
			]
		);

		return $schema;

	}//end schema()

	/**
	 * A record of the given organisation with the given data.
	 *
	 * @param string               $organisation The record's organisation.
	 * @param array<string, mixed> $data         The record's data.
	 *
	 * @return ObjectEntity
	 */
	private function record(string $organisation, array $data): ObjectEntity {
		$record = new ObjectEntity();
		$record->setUuid('rec-1');
		$record->setOrganisation($organisation);
		$record->setOwner('bob');
		$record->setObject($data);

		return $record;

	}//end record()
}//end class
