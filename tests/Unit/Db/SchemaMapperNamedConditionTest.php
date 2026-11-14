<?php

/**
 * SchemaMapperNamedConditionTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use Exception;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaMapper\SchemaMapperServices;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The schema save path runs the named-condition check (task 1.3b).
 *
 * @coversNothing
 */
class SchemaMapperNamedConditionTest extends TestCase {

	private function save(array $configuration): void {
		$mapper = new SchemaMapper(
			db: $this->createMock(IDBConnection::class),
			tenancy: new TenancyServices(
				organisationMapper: $this->createMock(OrganisationMapper::class),
				userSession: $this->createMock(IUserSession::class),
				groupManager: $this->createMock(IGroupManager::class)
			),
			appConfig: $this->createMock(IAppConfig::class),
			services: new SchemaMapperServices(
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				validator: $this->createMock(PropertyValidatorHandler::class),
				logger: $this->createMock(LoggerInterface::class)
			)
		);

		$schema = new Schema();
		$schema->setSlug('meldingen');
		$schema->setProperties(['spoed' => ['type' => 'boolean'], 'reden' => ['type' => 'string']]);
		$schema->setConfiguration($configuration);

		(new ReflectionMethod(SchemaMapper::class, 'cleanObject'))->invoke($mapper, $schema);
	}//end save()

	private static function validation(): array {
		return [
			'spoedreden' => [
				'condition'  => ['$condition' => 'spoedeisend'],
				'severity'   => 'refuse',
				'message'    => 'Spoed vraagt een reden.',
				'properties' => ['reden'],
			],
		];
	}//end validation()

	public function testAnUndeclaredNamedConditionRefusesTheSave(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/^x-openregister-conditions: .*"spoedeisend", which is not declared/');

		$this->save(['x-openregister-validations' => self::validation()]);
	}//end testAnUndeclaredNamedConditionRefusesTheSave()

	public function testADeclaredNamedConditionSaves(): void {
		$this->save(
			[
				'x-openregister-conditions'  => ['spoedeisend' => ['expression' => ['==' => [['var' => 'spoed'], true]]]],
				'x-openregister-validations' => self::validation(),
			]
		);

		$this->addToAssertionCount(1);
	}//end testADeclaredNamedConditionSaves()
}//end class
