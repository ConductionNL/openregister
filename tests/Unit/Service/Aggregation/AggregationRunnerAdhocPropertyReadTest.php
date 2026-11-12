<?php

/**
 * An ad-hoc aggregation asks the same property read rule as a named one: a sum
 * over a property the caller may not read is that property's total, and a
 * group-by key is its value.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/aggregate-paths-ask-permission/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Aggregation\NamedAggregationRef;
use OCA\OpenRegister\Service\Aggregation\NamedAggregationOptions;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Rbac\AggregateVisibility;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Ad-hoc aggregations refuse a property the caller may not read.
 */
class AggregationRunnerAdhocPropertyReadTest extends TestCase {

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('employee');
		$schema->setProperties(
			[
				'department' => ['type' => 'string'],
				'salary' => [
					'type' => 'number',
					'authorization' => ['read' => [['group' => 'hr']]],
				],
			]
		);

		return $schema;
	}//end schema()

	private function register(): Register {
		$register = new Register();
		$register->setId(1);
		$register->setSlug('brp');
		$register->setSchemas([7]);

		return $register;
	}//end register()

	/**
	 * A runner for a reader who may read the schema AND the BSN property.
	 *
	 * @return AggregationRunner The runner.
	 */
	private function runner(): AggregationRunner {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$permissions = $this->createMock(originalClassName: PermissionHandler::class);
		$permissions->method('hasPermission')->willReturn(true);

		$propertyRbac = $this->createMock(originalClassName: PropertyRbacHandler::class);
		$propertyRbac->method('canReadProperty')->willReturnCallback(
			static fn ($property): bool => $property->name !== 'salary'
		);

		$magicMapper = $this->createMock(originalClassName: MagicMapper::class);
		$magicMapper->method('callerQualifiesForAction')->willReturn(true);
		$magicMapper->method('findAllInRegisterSchemaTable')->willReturnCallback(
			function (RegisterSchema $table, ListQuery $listing): array {
				$this->fail(message: 'the refused aggregation reached the data');
			}
		);

		$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$registerMapper->method('find')->willReturn($this->register());
		$schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($this->schema());
		$schemaMapper->method('findInIds')->willReturn($this->schema());

		return new AggregationRunner(
			catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $registerMapper, schemas: $schemaMapper, tables: $magicMapper),
			placeholders: new PlaceholderResolver($session),
			db: $this->createMock(originalClassName: IDBConnection::class),
			cache: $this->createMock(originalClassName: AggregationCache::class),
			permissionHandler: $permissions,
			userSession: $session,
			organisationService: $this->createMock(originalClassName: OrganisationService::class),
			organizationHandler: $this->createMock(originalClassName: MagicOrganizationHandler::class),
			translationHandler: $this->createMock(originalClassName: TranslationHandler::class),
			languageService: $this->createMock(originalClassName: LanguageService::class),
			propertyRbac: $propertyRbac,
			explicitReveal: new ExplicitReveal(),
		);
	}//end runner()

	/**
	 * An ad-hoc group-by on the BSN is refused for a reader who may read it.
	 *
	 * @return void
	 */
	/**
	 * The queries over the withheld property, each refused before it reaches the data.
	 *
	 * @return array<string, array{0: AggregationQuery}>
	 */
	public static function withheldQueries(): array {
		return [
			'group by the withheld property' => [AggregationQuery::create(metric: 'count', groupBy: ['field' => 'salary'])],
			'sum of the withheld property' => [AggregationQuery::create(metric: 'sum', field: 'salary')],
			'max of the withheld property' => [AggregationQuery::create(metric: 'max', field: 'salary')],
		];
	}//end withheldQueries()

	#[\PHPUnit\Framework\Attributes\DataProvider('withheldQueries')]
	public function testAnAdhocAggregationOverAWithheldPropertyIsRefused(AggregationQuery $query): void {
		$this->expectException(exception: NotAuthorizedException::class);
		$this->expectExceptionMessage(message: 'salary');

		$this->runner()->runAdhoc(register: $this->register(), schema: $this->schema(), query: $query);
	}//end testAnAdhocAggregationOverAWithheldPropertyIsRefused()

	public function testAnAdhocAggregationOverAReadablePropertyIsNotRefused(): void {
		try {
			$this->runner()->runAdhoc(
				register: $this->register(),
				schema: $this->schema(),
				query: AggregationQuery::create(metric: 'count', groupBy: ['field' => 'department'])
			);
		} catch (NotAuthorizedException $refused) {
			$this->fail(message: 'a readable property was refused: ' . $refused->getMessage());
		} catch (\Throwable $other) {
			// Past the guards; the doubles hold no data to aggregate.
		}

		$this->addToAssertionCount(1);
	}//end testAnAdhocAggregationOverAReadablePropertyIsNotRefused()
}//end class
