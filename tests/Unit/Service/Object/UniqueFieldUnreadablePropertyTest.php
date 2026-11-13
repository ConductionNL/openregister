<?php

/**
 * The unique-field check counts values the caller may not read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ExplicitRevealQueryException;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationLookups;
use OCA\OpenRegister\Service\Object\ValidationSchemaSource;
use OCA\OpenRegister\Service\Query\PropertyReadGuard;
use OCA\OpenRegister\Service\Query\RelatedRowAccess;
use OCA\OpenRegister\Service\Query\RelatedRowLookups;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * A unique field the caller may not read still finds the existing value.
 *
 * The mapper here behaves like MagicMapper::countIn(): the search path refuses a filter on an
 * unreadable property, and the count turns that refusal into 0.
 *
 * @covers \OCA\OpenRegister\Service\Object\ValidateObject
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Exception\CustomValidationException
 * @uses \OCA\OpenRegister\Service\Object\UniqueFieldScope
 * @uses \OCA\OpenRegister\Service\Object\ValidationLookups
 * @uses \OCA\OpenRegister\Service\Object\ValidationSchemaSource
 * @uses \OCA\OpenRegister\Service\OrganisationContext
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowLookups
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 */
class UniqueFieldUnreadablePropertyTest extends TestCase {

	/**
	 * A caller without read on the unique property cannot create a duplicate.
	 *
	 * @return void
	 */
	public function testACallerWithoutReadOnTheUniquePropertyCannotCreateADuplicate(): void {
		$schema = new Schema();
		$schema->setProperties(
			[
				'title' => ['type' => 'string'],
				'code'  => ['type' => 'string', 'authorization' => ['read' => ['admin']]],
			]
		);
		$schema->setConfiguration(['unique' => 'code']);

		$applier = $this->applierWithholding(property: 'code');
		$mapper  = $this->createMock(originalClassName: MagicMapper::class);
		$mapper->method('countAll')->willReturnCallback(
			static function (?array $_filters = null, ?Schema $schema = null) use ($applier): int {
				try {
					$applier->refuseUnreadableFilterOrSort(query: ($_filters ?? []), schema: $schema);
				} catch (ExplicitRevealQueryException $e) {
					return 0;
				}

				return 1;
			}
		);

		$validator = new ValidateObject(
			schemas: new ValidationSchemaSource(config: $this->createMock(IAppConfig::class), schemaMapper: $this->createMock(SchemaMapper::class), urlGenerator: $this->createMock(IURLGenerator::class)),
			lookups: new ValidationLookups(objectMapper: $mapper, userManager: $this->createMock(IUserManager::class)),
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->expectException(exception: CustomValidationException::class);
		$this->expectExceptionMessage(message: 'Fields are not unique: code (values: code=A-1)');

		(new ReflectionMethod(ValidateObject::class, 'validateUniqueFields'))->invoke($validator, ['title' => 'x', 'code' => 'A-1'], $schema);
	}//end testACallerWithoutReadOnTheUniquePropertyCannotCreateADuplicate()

	/**
	 * An applier whose property read guard withholds one property and admits the rest.
	 *
	 * @param string $property The property the caller may not read.
	 *
	 * @return RelatedRowQueryApplier The applier.
	 */
	private function applierWithholding(string $property): RelatedRowQueryApplier {
		$guard = $this->createMock(originalClassName: PropertyReadGuard::class);
		$guard->method('mayRead')->willReturnCallback(static fn (Schema $schema, string $name): bool => $name !== $property);

		return new RelatedRowQueryApplier(
			lookups: new RelatedRowLookups(schemaMapper: $this->createMock(originalClassName: SchemaMapper::class), tableHandler: $this->createMock(originalClassName: MagicTableHandler::class)),
			db: $this->createMock(originalClassName: IDBConnection::class),
			logger: new NullLogger(),
			access: new RelatedRowAccess(rbac: $this->createMock(originalClassName: MagicRbacHandler::class), logger: new NullLogger(), guard: $guard)
		);
	}//end applierWithholding()
}//end class
