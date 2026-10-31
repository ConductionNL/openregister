<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A bulk save applies property authorization per row, as the single save does.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-bulk-save-applies-property-authorization-per-row
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCA\OpenRegister\Tests\Support\BuildsStateFieldRuleResolver;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * A caller with update on the object but not on a protected property cannot write that property through the bulk
 * path, explicitly or by leaving it out.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-bulk-save-applies-property-authorization-per-row
 */
class BulkSavePropertyAuthorizationTest extends TestCase {
	use BuildsStateFieldRuleResolver;

	private const UUID = '11111111-1111-1111-1111-111111111111';

	private SaveObjects $handler;

	private Schema $schema;

	private Register $register;

	protected function setUp(): void {
		parent::setUp();
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('getUserGroupIds')->willReturn(['staff']);
		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturn(true);

		$this->schema = new Schema();
		$this->schema->setId(1);
		$this->schema->setSlug('document');
		$this->schema->setTitle('document');
		$service = ['create' => [['group' => 'service']], 'update' => [['group' => 'service']]];
		$this->schema->setProperties([
			'title' => ['type' => 'string'],
			'restricted' => ['type' => 'boolean', 'authorization' => $service],
			'history' => ['type' => 'array', 'authorization' => $service],
		]);
		$this->register = new Register();
		$this->register->setId(10);
		$this->register->setConfiguration([]);

		$existing = new ObjectEntity();
		$existing->setUuid(self::UUID);
		$existing->setObject(['title' => 'Offerte', 'restricted' => true, 'history' => [['action' => 'set']]]);
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('find')->willReturn($existing);
		$mapper->method('findObjectsByUuidsInRegisterSchema')->willReturn([self::UUID => $existing]);

		$this->handler = new SaveObjects(
			objectEntityMapper: $mapper,
			schemaMapper: $this->createMock(SchemaMapper::class),
			registerMapper: $this->createMock(RegisterMapper::class),
			saveHandler: $this->createMock(SaveObject::class),
			userSession: $session,
			organisationService: $this->createMock(OrganisationService::class),
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $groups,
			permissionHandler: $permissions,
			propertyRbac: new PropertyRbacHandler(
				$session,
				$groups,
				$this->createMock(ConditionMatcher::class),
				$this->createMock(LoggerInterface::class),
				self::stateFieldRuleResolver($session, $groups)
			),
		);

		$ref = new ReflectionClass(SaveObjects::class);
		foreach (['schemaCache', 'schemaAnalysisCache', 'registerCache'] as $propName) {
			$prop = $ref->getProperty($propName);
			$prop->setAccessible(true);
			$prop->setValue(null, []);
		}
	}//end setUp()

	/**
	 * Run the safeguards over rows.
	 *
	 * @param array<int, array<string, mixed>> $rows   The rows.
	 * @param array<string, mixed>             $result The result, filled in.
	 *
	 * @return array<int, array<string, mixed>> The rows that pass.
	 */
	private function safeguard(array $rows, array &$result): array {
		$result = ['invalid' => [], 'statistics' => ['invalid' => 0, 'errors' => 0]];
		$method = new ReflectionMethod(SaveObjects::class, 'applyBulkSafeguards');
		$method->setAccessible(true);

		return $method->invokeArgs($this->handler, [$rows, $this->register, $this->schema, true, false, &$result]);
	}//end safeguard()

	public function testAnExplicitWriteOfAProtectedPropertyIsRefused(): void {
		$result = [];
		$passed = $this->safeguard(
			rows: [['@self' => ['uuid' => self::UUID], 'title' => 'Offerte', 'restricted' => false]],
			result: $result
		);

		$this->assertSame([], $passed);
		$this->assertStringContainsString('restricted', $result['invalid'][0]['error']);
	}//end testAnExplicitWriteOfAProtectedPropertyIsRefused()

	public function testABulkRowThatOmitsAProtectedPropertyKeepsIt(): void {
		$result = [];
		$passed = $this->safeguard(rows: [['@self' => ['uuid' => self::UUID], 'title' => 'Offerte v2']], result: $result);

		$this->assertCount(1, $passed);
		$this->assertSame('Offerte v2', $passed[0]['title']);
		$this->assertTrue($passed[0]['restricted']);
		$this->assertSame([['action' => 'set']], $passed[0]['history']);
	}//end testABulkRowThatOmitsAProtectedPropertyKeepsIt()

	public function testAnUnchangedProtectedValuePasses(): void {
		$result = [];
		$passed = $this->safeguard(rows: [['@self' => ['uuid' => self::UUID], 'title' => 'Offerte', 'restricted' => true]], result: $result);

		$this->assertCount(1, $passed);
	}//end testAnUnchangedProtectedValuePasses()

	public function testASystemOperationWritesAProtectedPropertyAsTheObjectRuleLetsIt(): void {
		$result = [];
		$passed = SystemOperationContext::run(
			function () use (&$result): array {
				return $this->safeguard(
					rows: [['@self' => ['uuid' => self::UUID], 'title' => 'Offerte', 'restricted' => false]],
					result: $result
				);
			}
		);

		$this->assertCount(1, $passed);
		$this->assertFalse($passed[0]['restricted']);
		$this->assertSame([], $result['invalid']);
	}//end testASystemOperationWritesAProtectedPropertyAsTheObjectRuleLetsIt()
}//end class
