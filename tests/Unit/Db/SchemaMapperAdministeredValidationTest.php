<?php

/**
 * SchemaMapperAdministeredValidationTest
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
 * The schema save path refuses an administered validation that cannot run as written (task 4.1).
 *
 * @coversNothing
 */
class SchemaMapperAdministeredValidationTest extends TestCase {

	private function save(array $validations): void {
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
		$schema->setSlug('subsidies');
		$schema->setProperties(['bedrag' => ['type' => 'number'], 'mandaat' => ['type' => 'string']]);
		$schema->setConfiguration(['x-openregister-validations' => $validations]);

		(new ReflectionMethod(SchemaMapper::class, 'cleanObject'))->invoke($mapper, $schema);
	}//end save()

	private static function validation(array $overrides = []): array {
		return [
			'mandaat' => array_merge(
				[
					'condition'  => ['>' => [['var' => 'bedrag'], 50000]],
					'severity'   => 'refuse',
					'message'    => ['nl' => 'Boven 50.000 is een mandaat nodig.', 'en' => 'Above 50,000 a mandate is required.'],
					'properties' => ['bedrag', 'mandaat'],
				],
				$overrides
			),
		];
	}//end validation()

	public function testAWellFormedValidationSaves(): void {
		$this->save(self::validation());
		$this->save(self::validation(['severity' => 'warn', 'message' => 'Controleer het mandaat.']));

		$this->addToAssertionCount(1);
	}//end testAWellFormedValidationSaves()

	public function testAnUnknownSeverityRefusesTheSave(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('x-openregister-validations: validation "mandaat" declares severity "block": use one of refuse or warn');

		$this->save(self::validation(['severity' => 'block']));
	}//end testAnUnknownSeverityRefusesTheSave()

	public function testAMissingMessageRefusesTheSave(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/^x-openregister-validations: validation "mandaat" carries no message/');

		$this->save(self::validation(['message' => '  ']));
	}//end testAMissingMessageRefusesTheSave()

	public function testAnUndeclaredPropertyRefusesTheSave(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('x-openregister-validations: validation "mandaat" points at "budget", which this schema does not declare');

		$this->save(self::validation(['properties' => ['budget']]));
	}//end testAnUndeclaredPropertyRefusesTheSave()

	public function testANoConditionValidationRefusesTheSave(): void {
		$declaration = self::validation();
		unset($declaration['mandaat']['condition']);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('validation "mandaat" declares no condition');

		$this->save($declaration);
	}//end testANoConditionValidationRefusesTheSave()
}//end class
