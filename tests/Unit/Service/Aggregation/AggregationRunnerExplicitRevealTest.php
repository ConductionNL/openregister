<?php

/**
 * An aggregation never shows the values of an explicit-reveal property: a
 * group-by bucket key is the value, a min or max is the value, a filter is an
 * oracle on it, and none of them records a reveal.
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
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Rbac\AggregateVisibility;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Aggregation\AggregationRunner
 * @covers \OCA\OpenRegister\Service\Rbac\AggregateVisibility
 */
class AggregationRunnerExplicitRevealTest extends TestCase {

	/**
	 * A person schema whose BSN is explicit-reveal and readable by staff.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('brp-person');
		$schema->setProperties(
			[
				'municipality' => ['type' => 'string'],
				'citizenServiceNumber' => [
					'type' => 'string',
					'authorization' => ['read' => [['group' => 'staff']], 'reveal' => 'explicit'],
				],
			]
		);
		$schema->setConfiguration(
			[
				'x-openregister-aggregations' => [
					'byBsn' => ['metric' => 'count', 'groupBy' => ['field' => 'citizenServiceNumber']],
					'perMunicipality' => ['metric' => 'count', 'groupBy' => ['field' => 'municipality']],
				],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * A register carrying the schema.
	 *
	 * @return Register The register.
	 */
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
		$propertyRbac->method('canReadProperty')->willReturn(true);

		$magicMapper = $this->createMock(originalClassName: MagicMapper::class);
		$magicMapper->method('callerQualifiesForAction')->willReturn(true);
		$magicMapper->method('findAllInRegisterSchemaTable')->willReturnCallback(
			function (): array {
				$this->fail(message: 'the refused aggregation reached the data');
			}
		);

		$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$registerMapper->method('find')->willReturn($this->register());
		$schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($this->schema());
		$schemaMapper->method('findInIds')->willReturn($this->schema());

		return new AggregationRunner(
			magicMapper: $magicMapper,
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
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
	public function testAnAdhocGroupByOnItIsRefused(): void {
		$this->expectException(exception: NotAuthorizedException::class);
		$this->expectExceptionMessage(message: 'shown only on request');

		$this->runner()->runAdhoc(
			register: $this->register(),
			schema: $this->schema(),
			query: AggregationQuery::create(metric: 'count', groupBy: ['field' => 'citizenServiceNumber'])
		);
	}//end testAnAdhocGroupByOnItIsRefused()

	/**
	 * A max over the BSN is the value, and is refused.
	 *
	 * @return void
	 */
	public function testAnAdhocMaxOverItIsRefused(): void {
		$this->expectException(exception: NotAuthorizedException::class);
		$this->expectExceptionMessage(message: 'shown only on request');

		$this->runner()->runAdhoc(
			register: $this->register(),
			schema: $this->schema(),
			query: AggregationQuery::create(metric: 'max', field: 'citizenServiceNumber')
		);
	}//end testAnAdhocMaxOverItIsRefused()

	/**
	 * A filter on the BSN is an oracle, and is refused.
	 *
	 * @return void
	 */
	public function testAnAdhocFilterOnItIsRefused(): void {
		$this->expectException(exception: NotAuthorizedException::class);
		$this->expectExceptionMessage(message: 'shown only on request');

		$this->runner()->runAdhoc(
			register: $this->register(),
			schema: $this->schema(),
			query: AggregationQuery::create(metric: 'count', filter: ['citizenServiceNumber' => '123457609'], groupBy: ['field' => 'municipality'])
		);
	}//end testAnAdhocFilterOnItIsRefused()

	/**
	 * A declared aggregation grouping on the BSN is refused.
	 *
	 * @return void
	 */
	public function testADeclaredGroupByOnItIsRefused(): void {
		$this->expectException(exception: NotAuthorizedException::class);
		$this->expectExceptionMessage(message: 'shown only on request');

		$this->runner()->run(registerRef: 'brp', schemaRef: 'brp-person', name: 'byBsn');
	}//end testADeclaredGroupByOnItIsRefused()

	/**
	 * A caller narrowing a declared aggregation by the BSN is refused.
	 *
	 * @return void
	 */
	public function testANarrowingFilterOnItIsRefused(): void {
		$this->expectException(exception: NotAuthorizedException::class);
		$this->expectExceptionMessage(message: 'shown only on request');

		$this->runner()->run(
			registerRef: 'brp',
			schemaRef: 'brp-person',
			name: 'perMunicipality',
			extraFilter: ['citizenServiceNumber' => '123457609']
		);
	}//end testANarrowingFilterOnItIsRefused()

	/**
	 * A bypassing caller (a materialised aggregate reference) is refused too:
	 * it would store the value in clear on another object.
	 *
	 * @return void
	 */
	public function testABypassingAdhocMaxOverItIsRefused(): void {
		$this->expectException(exception: NotAuthorizedException::class);
		$this->expectExceptionMessage(message: 'shown only on request');

		$this->runner()->runAdhocByRef(
			registerRef: 'brp',
			schemaRef: 'brp-person',
			query: AggregationQuery::create(metric: 'max', field: 'citizenServiceNumber'),
			bypassRbac: true
		);
	}//end testABypassingAdhocMaxOverItIsRefused()

	/**
	 * A bypassing declared aggregation grouping on it is refused too.
	 *
	 * @return void
	 */
	public function testABypassingDeclaredGroupByOnItIsRefused(): void {
		$this->expectException(exception: NotAuthorizedException::class);
		$this->expectExceptionMessage(message: 'shown only on request');

		$this->runner()->run(registerRef: 'brp', schemaRef: 'brp-person', name: 'byBsn', bypassRbac: true);
	}//end testABypassingDeclaredGroupByOnItIsRefused()

	/**
	 * The control: trusted server code and a system operation are not refused,
	 * and an ordinary property is not affected.
	 *
	 * @return void
	 */
	public function testTrustedCodeAndOrdinaryPropertiesAreNotWithheld(): void {
		$reveal     = new ExplicitReveal();
		$visibility = new AggregateVisibility(rbac: null, logger: null, reveal: $reveal);
		$schema     = $this->schema();

		$this->assertTrue(condition: $visibility->isMaskedExplicit(schema: $schema, property: 'citizenServiceNumber'));
		$this->assertFalse(
			condition: $reveal->trustedDuring(
				operation: fn (): bool => $visibility->isMaskedExplicit(schema: $schema, property: 'citizenServiceNumber')
			)
		);
		$this->assertFalse(condition: $visibility->isMaskedExplicit(schema: $schema, property: 'municipality'));
	}//end testTrustedCodeAndOrdinaryPropertiesAreNotWithheld()
}//end class
