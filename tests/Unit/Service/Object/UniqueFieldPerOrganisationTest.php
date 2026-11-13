<?php

/**
 * Unique fields are unique within an organisation.
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
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationLookups;
use OCA\OpenRegister\Service\Object\ValidationSchemaSource;
use OCA\OpenRegister\Service\OrganisationContext;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The unique-field check counts the rows of the write's organisation and the
 * org-less rows, whether or not the caller may read them, and no other rows.
 *
 * The mapper here keeps rows in memory and applies the count filters the way
 * the search handler does: RBAC hides the rows the caller cannot read unless
 * `_rbac` is off, multitenancy keeps the caller's organisation unless
 * `_multitenancy` is off, a system operation lifts both, an organisation
 * context keeps its own organisation only, and `@self.organisation` narrows
 * by organisation. The org-less count reads past the context.
 *
 * @covers \OCA\OpenRegister\Service\Object\ValidateObject
 * @covers \OCA\OpenRegister\Service\Object\UniqueFieldScope
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Exception\CustomValidationException
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\Object\ValidationLookups
 * @uses \OCA\OpenRegister\Service\Object\ValidationSchemaSource
 * @uses \OCA\OpenRegister\Service\OrganisationContext
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 */
class UniqueFieldPerOrganisationTest extends TestCase {

	private const CALLER_ORG = 'org-a';

	private const OTHER_ORG = 'org-b';

	/**
	 * A duplicate in the caller's organisation is refused even when the caller cannot read the existing row.
	 *
	 * @return void
	 */
	public function testDuplicateInOwnOrganisationIsRefusedEvenWhenTheRowIsUnreadable(): void {
		$validator = $this->validator(rows: [['org' => self::CALLER_ORG, 'code' => 'A-1', 'readable' => false]]);

		$this->expectException(exception: CustomValidationException::class);
		$this->expectExceptionMessage(message: 'Fields are not unique: code (values: code=A-1)');

		$this->check(validator: $validator, object: ['code' => 'A-1']);
	}//end testDuplicateInOwnOrganisationIsRefusedEvenWhenTheRowIsUnreadable()

	/**
	 * A value taken only in another organisation is allowed.
	 *
	 * @return void
	 */
	public function testSameValueInAnotherOrganisationIsAllowed(): void {
		$validator = $this->validator(rows: [['org' => self::OTHER_ORG, 'code' => 'A-1', 'readable' => true]]);

		$this->check(validator: $validator, object: ['code' => 'A-1']);

		$this->addToAssertionCount(count: 1);
	}//end testSameValueInAnotherOrganisationIsAllowed()

	/**
	 * Naming another organisation the caller does not belong to counts the caller's own organisation.
	 *
	 * @return void
	 */
	public function testAnOrganisationTheCallerCannotWriteIntoIsNotCounted(): void {
		$validator = $this->validator(rows: [['org' => self::OTHER_ORG, 'code' => 'A-1', 'readable' => false]]);

		$this->check(validator: $validator, object: ['code' => 'A-1', '@self' => ['organisation' => self::OTHER_ORG]]);

		$this->addToAssertionCount(count: 1);
	}//end testAnOrganisationTheCallerCannotWriteIntoIsNotCounted()

	/**
	 * The refusal is the same whether or not another organisation holds the value.
	 *
	 * @return void
	 */
	public function testRefusalDoesNotDependOnAnotherOrganisationsData(): void {
		$own   = ['org' => self::CALLER_ORG, 'code' => 'A-1', 'readable' => false];
		$other = ['org' => self::OTHER_ORG, 'code' => 'A-1', 'readable' => false];

		$alone = $this->refusal(validator: $this->validator(rows: [$own]));
		$both  = $this->refusal(validator: $this->validator(rows: [$own, $other, $other]));

		self::assertNotNull(actual: $alone);
		self::assertSame(expected: $alone->getMessage(), actual: $both->getMessage());
		self::assertSame(expected: $alone->getErrors(), actual: $both->getErrors());
	}//end testRefusalDoesNotDependOnAnotherOrganisationsData()

	/**
	 * Rows without an organisation count together with the caller's organisation.
	 *
	 * @return void
	 */
	public function testOrganisationlessRowsCountWithTheCallersOrganisation(): void {
		$validator = $this->validator(rows: [['org' => null, 'code' => 'A-1', 'readable' => false]]);

		$this->expectException(exception: CustomValidationException::class);

		$this->check(validator: $validator, object: ['code' => 'A-1']);
	}//end testOrganisationlessRowsCountWithTheCallersOrganisation()

	/**
	 * Code acting for an organisation counts that organisation's rows.
	 *
	 * @return void
	 */
	public function testAnOrganisationContextCountsThatOrganisation(): void {
		$validator = $this->validator(rows: [['org' => self::OTHER_ORG, 'code' => 'A-1', 'readable' => false]]);

		$refusal = OrganisationContext::run(
			organisationUuid: self::OTHER_ORG,
			operation: fn (): ?CustomValidationException => $this->refusal(validator: $validator)
		);

		self::assertNotNull(actual: $refusal);
	}//end testAnOrganisationContextCountsThatOrganisation()

	/**
	 * Inside an organisation context a duplicate in a row without an organisation is refused.
	 *
	 * @return void
	 */
	public function testAnOrganisationlessDuplicateIsRefusedInsideAContext(): void {
		$validator = $this->validator(rows: [['org' => null, 'code' => 'A-1', 'readable' => false]]);

		$refusal = OrganisationContext::run(
			organisationUuid: self::CALLER_ORG,
			operation: fn (): ?CustomValidationException => $this->refusal(validator: $validator)
		);

		self::assertNotNull(actual: $refusal);
	}//end testAnOrganisationlessDuplicateIsRefusedInsideAContext()

	/**
	 * Inside an organisation context another organisation's rows stay uncounted.
	 *
	 * @return void
	 */
	public function testAnotherOrganisationsRowsAreNotCountedInsideAContext(): void {
		$validator = $this->validator(rows: [['org' => self::OTHER_ORG, 'code' => 'A-1', 'readable' => true]]);

		$refusal = OrganisationContext::run(
			organisationUuid: self::CALLER_ORG,
			operation: fn (): ?CustomValidationException => $this->refusal(validator: $validator)
		);

		self::assertNull(actual: $refusal);
	}//end testAnotherOrganisationsRowsAreNotCountedInsideAContext()

	/**
	 * Run the unique-field check and return its refusal, or null when the value is unique.
	 *
	 * @param ValidateObject $validator The validator.
	 *
	 * @return CustomValidationException|null The refusal.
	 */
	private function refusal(ValidateObject $validator): ?CustomValidationException {
		try {
			$this->check(validator: $validator, object: ['code' => 'A-1']);
		} catch (CustomValidationException $e) {
			return $e;
		}

		return null;
	}//end refusal()

	/**
	 * Run the unique-field check.
	 *
	 * @param ValidateObject       $validator The validator.
	 * @param array<string, mixed> $object    The object being written.
	 *
	 * @return void
	 */
	private function check(ValidateObject $validator, array $object): void {
		$schema = new Schema();
		$schema->setProperties(['code' => ['type' => 'string']]);
		$schema->setConfiguration(['unique' => 'code']);

		(new ReflectionMethod(ValidateObject::class, 'validateUniqueFields'))->invoke($validator, $object, $schema);
	}//end check()

	/**
	 * A validator over in-memory rows, for a caller whose organisation is CALLER_ORG.
	 *
	 * @param array<int, array{org: ?string, code: string, readable: bool}> $rows The stored rows.
	 *
	 * @return ValidateObject The validator.
	 */
	private function validator(array $rows): ValidateObject {
		$mapper = $this->createMock(originalClassName: MagicMapper::class);
		$mapper->method('countAll')->willReturnCallback(
			static fn (?array $_filters = null): int => count(
				array_filter($rows, static fn (array $row): bool => self::counted(row: $row, filters: ($_filters ?? [])))
			)
		);
		$mapper->method('countOrganisationless')->willReturnCallback(
			static fn (array $filters): int => count(
				array_filter($rows, static fn (array $row): bool => $row['org'] === null && ($filters['code'] ?? null) === $row['code'])
			)
		);

		$organisations = $this->createMock(originalClassName: OrganisationServiceInterface::class);
		$organisations->method('getOrganisationForNewEntity')->willReturn(self::CALLER_ORG);
		$organisations->method('hasAccessToOrganisation')->willReturnCallback(static fn (string $uuid): bool => $uuid === self::CALLER_ORG);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->with(OrganisationServiceInterface::class)->willReturn($organisations);

		return new ValidateObject(
			schemas: new ValidationSchemaSource(
				config: $this->createMock(originalClassName: IAppConfig::class),
				schemaMapper: $this->createMock(originalClassName: SchemaMapper::class),
				urlGenerator: $this->createMock(originalClassName: IURLGenerator::class)
			),
			lookups: new ValidationLookups(
				objectMapper: $mapper,
				userManager: $this->createMock(originalClassName: IUserManager::class),
				container: $container
			),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end validator()

	/**
	 * Whether a row matches the count filters, read the way the search handler reads them.
	 *
	 * @param array{org: ?string, code: string, readable: bool} $row     The row.
	 * @param array<string, mixed>                               $filters The count filters.
	 *
	 * @return bool True when the row is counted.
	 */
	private static function counted(array $row, array $filters): bool {
		$context = OrganisationContext::current();
		if ($context !== null && $row['org'] !== $context) {
			return false;
		}

		$system = SystemOperationContext::isActive();
		if ($system === false && ($filters['_rbac'] ?? true) !== false && $row['readable'] === false) {
			return false;
		}

		if ($system === false && ($filters['_multitenancy'] ?? true) !== false && $row['org'] !== self::CALLER_ORG) {
			return false;
		}

		$criterion = ($filters['@self']['organisation'] ?? null);
		if (is_array($criterion) === true) {
			$isNull  = ($row['org'] === null);
			$inList  = (in_array($row['org'], ($criterion['in'] ?? []), true) === true);
			$orNull  = (($criterion['orNull'] ?? false) === true || ($criterion['isnull'] ?? false) === true);
			if ($inList === false && ($isNull === false || $orNull === false)) {
				return false;
			}
		}

		return ($filters['code'] ?? null) === $row['code'];
	}//end counted()
}//end class
