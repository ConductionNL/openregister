<?php

/**
 * PermissionHandler and a schema's classification block: a find above the
 * caller's clearance is refused, and a ceiling refuses its scope, whatever a
 * schema rule or a per-object rule grants.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\ClassificationScale;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Classification refuses on the find path, ahead of every grant.
 *
 * @uses   \OCA\OpenRegister\Service\Rbac\RoleBlockExpander
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Service\Object\DenialQuestion
 * @uses \OCA\OpenRegister\Service\Object\PermissionCheck
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses \OCA\OpenRegister\Service\Rbac\DenyEntryMatcher
 * @uses \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses \OCA\OpenRegister\Service\Rbac\GrantConstraints
 * @uses \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleEntries
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleRequest
 * @uses   \OCA\OpenRegister\Db\JsonArrayColumn
 * @uses   \OCA\OpenRegister\Service\Object\PermissionAsk
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionCallerServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionDataServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionDenyServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionDerivedGrantServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionGrantServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionRuleServices
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyQuestion
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantLookup
 * @uses   \OCA\OpenRegister\Service\Rbac\McpScope
 * @uses   \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses \OCA\OpenRegister\Db\JsonArrayColumn
 * @uses \OCA\OpenRegister\Service\Object\PermissionAsk
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionCallerServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionDataServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionDenyServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionDerivedGrantServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionGrantServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionRuleServices
 * @uses \OCA\OpenRegister\Service\Rbac\DenyQuestion
 * @uses \OCA\OpenRegister\Service\Rbac\GrantLookup
 * @uses \OCA\OpenRegister\Service\Rbac\McpScope
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationReadGate
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationCaller
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationScale
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationLimits
 * @uses   \OCA\OpenRegister\Service\Rbac\ClearanceResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectClassification
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses   \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 *
 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-read-above-a-callers-clearance-is-refused-on-both-layers
 */
class PermissionHandlerClassificationTest extends TestCase {

	/**
	 * A distinct schema id per schema, so the per-request memo never answers for another.
	 *
	 * @var integer
	 */
	private int $schemaCounter = 700;

	/**
	 * A distinct uuid per object.
	 *
	 * @var integer
	 */
	private int $objectCounter = 0;

	/**
	 * A handler for one caller; null is the anonymous caller.
	 *
	 * @param string|null $userId The caller.
	 * @param string[]    $groups Their groups.
	 *
	 * @return PermissionHandler
	 */
	private function handlerFor(?string $userId, array $groups = []): PermissionHandler {
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($userId);
			$user->method('getDisplayName')->willReturn($userId);
		}

		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$userSession->method('isLoggedIn')->willReturn($user !== null);

		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);
		$appConfig->method('getValueString')->willReturn(DenyEnforcementMode::MODE_ENFORCING);

		$logger = new NullLogger();

		return PermissionHandlerFactory::create(
			$userSession,
			$userManager,
			$groupManager,
			$this->createMock(originalClassName: SchemaMapper::class),
			$this->createMock(originalClassName: MagicMapper::class),
			$this->createMock(originalClassName: ConditionMatcher::class),
			$appConfig,
			$logger,
			$this->container(),
			null,
			new ObjectScopeResolver(),
			null,
			new DenyResolver(new DenyEntryMatcher()),
			new DenyEnforcementMode($appConfig, $logger)
		);
	}//end handlerFor()

	/**
	 * A container answering with one register.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$register = new Register();
		$register->setId(9);
		$register->setSlug('documenten');

		$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$registerMapper->method('getFirstRegisterWithSchema')->willReturn(9);
		$registerMapper->method('find')->willReturn($register);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($registerMapper): mixed {
				if ($id === RegisterMapperInterface::class) {
					return $registerMapper;
				}

				throw new \RuntimeException(sprintf('nothing registered for %s', $id));
			}
		);

		return $container;
	}//end container()

	/**
	 * A document schema whose read rule names these principals, with or without the classification block.
	 *
	 * @param array<int, string> $readers    Who the schema lets read.
	 * @param bool               $classified Whether the schema declares the block.
	 *
	 * @return Schema
	 */
	private function schema(array $readers, bool $classified = true): Schema {
		$this->schemaCounter++;

		$schema = new Schema();
		$schema->setId($this->schemaCounter);
		$schema->setTitle('Document');
		$schema->setProperties(['vertrouwelijkheidaanduiding' => ['type' => 'string']]);
		$schema->setAuthorization(['read' => $readers]);
		if ($classified === true) {
			$schema->setConfiguration(
				[
					ClassificationScale::ANNOTATION => [
						'property' => 'vertrouwelijkheidaanduiding',
						'levels' => ['openbaar', 'intern', 'vertrouwelijk', 'geheim'],
						'clearances' => [['group' => 'medewerkers', 'level' => 'intern'], ['group' => 'juristen', 'level' => 'geheim']],
						'ceilings' => [['scope' => 'public', 'atOrAbove' => 'vertrouwelijk']],
					],
				]
			);
		}

		return $schema;
	}//end schema()

	/**
	 * A document carrying a label, and optionally its own rule block.
	 *
	 * @param string     $label         The label.
	 * @param array|null $authorization The object's own block.
	 *
	 * @return ObjectEntity
	 */
	private function document(string $label, ?array $authorization = null): ObjectEntity {
		$this->objectCounter++;

		$object = new ObjectEntity();
		$object->setUuid(sprintf('77777777-4444-5555-6666-%012d', $this->objectCounter));
		$object->setObject(['vertrouwelijkheidaanduiding' => $label]);
		$object->setOwner('bea');
		$object->setAuthorization($authorization);

		return $object;
	}//end document()

	/**
	 * The schema grants read, yet a find one level above the clearance is refused; at the clearance it is not.
	 *
	 * @return void
	 */
	public function testAFindAboveTheClearanceIsRefusedDespiteTheSchemaGrant(): void {
		$handler = $this->handlerFor(userId: 'mila', groups: ['medewerkers']);
		$schema = $this->schema(readers: ['medewerkers']);

		$this->assertFalse($handler->hasPermission(schema: $schema, action: 'read', userId: 'mila', object: $this->document('vertrouwelijk')));
		$this->assertTrue($handler->hasPermission(schema: $schema, action: 'read', userId: 'mila', object: $this->document('intern')));
		// A question about the schema, not one object, is the list path's to answer.
		$this->assertTrue($handler->hasPermission(schema: $schema, action: 'read', userId: 'mila'));
	}//end testAFindAboveTheClearanceIsRefusedDespiteTheSchemaGrant()

	/**
	 * A higher mapped group and the administrator read the top level.
	 *
	 * @return void
	 */
	public function testAClearedCallerAndTheAdministratorRead(): void {
		$schema = $this->schema(readers: ['medewerkers', 'juristen']);

		$this->assertTrue($this->handlerFor(userId: 'jan', groups: ['medewerkers', 'juristen'])->hasPermission(schema: $schema, action: 'read', userId: 'jan', object: $this->document('geheim')));
		$this->assertTrue($this->handlerFor(userId: 'beheer', groups: ['admin'])->hasPermission(schema: $schema, action: 'read', userId: 'beheer', object: $this->document('geheim')));
	}//end testAClearedCallerAndTheAdministratorRead()

	/**
	 * A ceiling on public beats a public schema rule and the object's own public grant.
	 *
	 * @return void
	 */
	public function testACeilingBeatsEveryGrantToItsScope(): void {
		$handler = $this->handlerFor(userId: null);
		$schema = $this->schema(readers: ['public']);

		$this->assertTrue($handler->hasPermission(schema: $schema, action: 'read', object: $this->document('openbaar')));
		$this->assertFalse($handler->hasPermission(schema: $schema, action: 'read', object: $this->document('vertrouwelijk')));
		$this->assertFalse($handler->hasPermission(schema: $schema, action: 'read', object: $this->document('geheim', ['read' => ['public']])));
	}//end testACeilingBeatsEveryGrantToItsScope()

	/**
	 * A schema without the block reads as it always did.
	 *
	 * @return void
	 */
	public function testASchemaWithoutTheBlockIsUnchanged(): void {
		$handler = $this->handlerFor(userId: 'mila', groups: ['medewerkers']);

		$this->assertTrue($handler->hasPermission(schema: $this->schema(readers: ['medewerkers'], classified: false), action: 'read', userId: 'mila', object: $this->document('geheim')));
	}//end testASchemaWithoutTheBlockIsUnchanged()
}//end class
