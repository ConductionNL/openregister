<?php

/**
 * The SchemaMapper the registrar builds verifies declared actions with the full validator.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MapperRegistrar;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\MacroActionValidator;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionValidator;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\AppFramework\IAppContainer;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\MapperRegistrar
 * @covers \OCA\OpenRegister\Db\SchemaMapper
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionValidator
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinition
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionElevation
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionInputSchema
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionShape
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\SchemaMapper\DeclaredActionCheck
 * @uses \OCA\OpenRegister\Db\SchemaMapper\LifecycleAnnotationCheck
 * @uses \OCA\OpenRegister\Db\SchemaMapper\CalculationAnnotationCheck
 * @uses \OCA\OpenRegister\Db\SchemaMapper\RelationDeclarationCheck
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AdvisoryAnnotationChecks
 * @uses \OCA\OpenRegister\Db\SchemaMapper\RecordAnnotationRefusals
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ExchangeAnnotationRefusals
 * @uses \OCA\OpenRegister\Db\SchemaMapper\PropertyDeclarationRefusals
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AnnotationFaults
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ReferenceNormaliser
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaDefaults
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ConfigurationFields
 * @uses \OCA\OpenRegister\Db\SchemaMapper\FacetConfiguration
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaComposition
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ParentReference
 * @uses \OCA\OpenRegister\Db\SchemaMapper\PropertyMerge
 * @uses \OCA\OpenRegister\Db\SchemaMapper\PropertyDelta
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ExtendedByReads
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AccessDeclarationChecks
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SubjectDeclarationRefusals
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AuthorizationBlockCheck
 */
class MapperRegistrarSchemaActionsTest extends TestCase {

	/**
	 * A schema saved by a non-administrator that declares `elevate`.
	 *
	 * @return Schema
	 */
	private function elevatingSchema(): Schema {
		$schema = new Schema();
		$schema->setConfiguration(
			[
				'x-openregister-action' => [
					'publish' => [
						'name' => 'Publish',
						'description' => 'Publish it.',
						'steps' => [['type' => 'openregister.set-fields']],
						'elevate' => [['register' => 'pub', 'schema' => 'publication', 'verbs' => ['create']]],
					],
				],
			]
		);
		$schema->setAuthorization(['publish' => [['group' => 'redactie']]]);
		return $schema;
	}//end elevatingSchema()

	/**
	 * The SchemaMapper as the registrar's factory builds it, for a signed-in non-administrator.
	 *
	 * @return SchemaMapper
	 */
	private function registeredMapper(): SchemaMapper {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$macros = $this->createMock(originalClassName: MacroActionValidator::class);
		$macros->method('refusals')->willReturn([]);

		$services = [
			'OCP\IUserSession' => $session,
			'OCP\IGroupManager' => $groups,
			ObjectActionValidator::class => new ObjectActionValidator($macros, $session, $groups),
		];
		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$services) {
				$services[$id] ??= $this->createMock(originalClassName: $id);
				return $services[$id];
			}
		);

		$context = new RecordingRegistrationContext();
		MapperRegistrar::register(context: $context);

		return $context->factories[SchemaMapper::class]($container);
	}//end registeredMapper()

	/**
	 * A non-administrator's `elevate` declaration is refused by the registered mapper.
	 *
	 * @return void
	 */
	public function testANonAdministratorsElevateIsRefused(): void {
		$validate = new ReflectionMethod(SchemaMapper::class, 'cleanObject');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('only an administrator may declare "elevate"');

		$validate->invoke($this->registeredMapper(), $this->elevatingSchema());
	}//end testANonAdministratorsElevateIsRefused()
}//end class
