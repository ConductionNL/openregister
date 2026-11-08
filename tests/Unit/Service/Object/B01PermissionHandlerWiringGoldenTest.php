<?php

/**
 * PermissionHandler allows and refuses as recorded, whatever reaches its constructor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Pins allowed and refused outcomes of hasPermission() and that the injected
 * collaborators, not their fallbacks, decide them.
 *
 * Recorded on the constructor before its collaborators were grouped into bundles.
 *
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Event\ActionEvaluatedEvent
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\Object\DenialQuestion
 * @uses   \OCA\OpenRegister\Service\Object\DraftCallerRule
 * @uses   \OCA\OpenRegister\Service\Object\PermissionCheck
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleEntries
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleRequest
 * @uses   \OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEntryMatcher
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantConstraints
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\PermissionCatalogue
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 */
class B01PermissionHandlerWiringGoldenTest extends TestCase {

	/**
	 * Events the handler dispatched in the current case.
	 *
	 * @var array<int, string>
	 */
	private array $events = [];

	/**
	 * Hands out a distinct schema id per schema, so the per-request memo never answers for another case.
	 *
	 * @var integer
	 */
	private int $schemaCounter = 500;

	/**
	 * An app config answering one deny enforcement mode.
	 *
	 * @param string $mode The mode getValueString() answers.
	 *
	 * @return IAppConfig The config.
	 */
	private function config(string $mode): IAppConfig {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueBool')->willReturn(false);
		$config->method('getValueString')->willReturn($mode);

		return $config;
	}//end config()

	/**
	 * Build a handler for one caller with every collaborator named.
	 *
	 * The handler's own app config answers `staging`, the injected enforcement
	 * mode answers `enforcing`: a deny only refuses when the injected mode is the
	 * one consulted.
	 *
	 * @param string   $userId The caller.
	 * @param string[] $groups The caller's group IDs.
	 *
	 * @return PermissionHandler The handler under test.
	 */
	private function handlerFor(string $userId, array $groups): PermissionHandler {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturn($user);
		$groupManager = $this->createStub(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);
		$dispatcher = $this->createStub(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->events[] = get_class($event);
			}
		);

		return \OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory::create(
			userSession: $session,
			userManager: $users,
			groupManager: $groupManager,
			schemaMapper: $this->createStub(SchemaMapper::class),
			objectEntityMapper: $this->createStub(MagicMapper::class),
			conditionMatcher: $this->createStub(ConditionMatcher::class),
			appConfig: $this->config(DenyEnforcementMode::MODE_STAGING),
			logger: new NullLogger(),
			container: $this->createStub(ContainerInterface::class),
			eventDispatcher: $dispatcher,
			denyResolver: new DenyResolver(new DenyEntryMatcher()),
			denyEnforcementMode: new DenyEnforcementMode($this->config(DenyEnforcementMode::MODE_ENFORCING), new NullLogger()),
		);
	}//end handlerFor()

	/**
	 * A schema carrying one authorization block, with its own id.
	 *
	 * @param array $authorization The block.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWith(array $authorization): Schema {
		$this->schemaCounter++;
		$schema = new Schema();
		$schema->setId($this->schemaCounter);
		$schema->setTitle('Zaak');
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schemaWith()

	/**
	 * A member of the granted group may read.
	 *
	 * @return void
	 */
	public function testAGrantedGroupMayRead(): void {
		$handler = $this->handlerFor('ana', ['behandelaars']);

		$this->assertTrue($handler->hasPermission($this->schemaWith(['read' => ['behandelaars']]), 'read', 'ana'));
	}//end testAGrantedGroupMayRead()

	/**
	 * A caller outside the granted group is refused.
	 *
	 * @return void
	 */
	public function testACallerOutsideTheGroupIsRefused(): void {
		$handler = $this->handlerFor('bob', ['burgers']);

		$this->assertFalse($handler->hasPermission($this->schemaWith(['read' => ['behandelaars']]), 'read', 'bob'));
	}//end testACallerOutsideTheGroupIsRefused()

	/**
	 * A verb the block does not grant is refused to a granted group.
	 *
	 * @return void
	 */
	public function testAnUngrantedVerbIsRefused(): void {
		$handler = $this->handlerFor('ana', ['behandelaars']);

		$this->assertFalse($handler->hasPermission($this->schemaWith(['read' => ['behandelaars']]), 'delete', 'ana'));
	}//end testAnUngrantedVerbIsRefused()

	/**
	 * The injected enforcement mode decides: a deny refuses although the handler's own config says staging.
	 *
	 * @return void
	 */
	public function testTheInjectedDenyModeRefuses(): void {
		$handler = $this->handlerFor('ana', ['behandelaars']);
		$schema  = $this->schemaWith(['read' => ['behandelaars'], 'deny' => ['read' => ['behandelaars']]]);

		$this->assertFalse($handler->hasPermission($schema, 'read', 'ana'));
	}//end testTheInjectedDenyModeRefuses()

	/**
	 * The admin group keeps its bypass.
	 *
	 * @return void
	 */
	public function testAnAdminMayRead(): void {
		$handler = $this->handlerFor('root', ['admin']);

		$this->assertTrue($handler->hasPermission($this->schemaWith(['read' => ['behandelaars']]), 'read', 'root'));
	}//end testAnAdminMayRead()

	/**
	 * The injected dispatcher hears the evaluation.
	 *
	 * @return void
	 */
	public function testTheInjectedDispatcherHearsTheDecision(): void {
		$this->events = [];
		$handler      = $this->handlerFor('ana', ['behandelaars']);
		$handler->hasPermission($this->schemaWith(['read' => ['behandelaars']]), 'read', 'ana');

		$this->assertNotSame([], $this->events);
	}//end testTheInjectedDispatcherHearsTheDecision()
}//end class
