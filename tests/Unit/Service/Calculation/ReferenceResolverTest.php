<?php

/**
 * OpenRegister ReferenceResolverTest
 *
 * Unit coverage for the cross-object reference pre-resolver feeding the
 * calculation engine's `@ref.<name>.<field>` payload tokens.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Calculation;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\Calculation\ReferenceResolver;
use OCA\OpenRegister\Service\Calculation\ReferenceTenantGuard;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SharedMasterDataService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-4
 */
class ReferenceResolverTest extends TestCase {
	/** @var ObjectService&MockObject */
	private $objectService;

	/** @var LoggerInterface&MockObject */
	private $logger;

	/** @var OrganisationMapper&MockObject */
	private $organisations;

	/** @var SharedMasterDataService&MockObject */
	private $sharedMasterData;

	private ReferenceResolver $resolver;

	protected function setUp(): void {
		$this->objectService = $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'findAll'])
			->getMock();
		$this->logger = $this->createMock(LoggerInterface::class);
		// The two DB-backed reads the tenant guard makes. Each test that
		// needs a parent chain or a share states it; the default is none.
		$this->organisations = $this->getMockBuilder(OrganisationMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findParentChain'])
			->getMock();
		$this->organisations->method('findParentChain')->willReturn([]);
		$this->sharedMasterData = $this->getMockBuilder(SharedMasterDataService::class)
			->disableOriginalConstructor()
			->onlyMethods(['holdersForResource'])
			->getMock();
		$this->resolver = new ReferenceResolver(
			$this->objectService,
			new ReferenceTenantGuard($this->organisations, $this->sharedMasterData, $this->logger),
			$this->logger
		);
	}//end setUp()

	private function entity(array $data, ?string $organisation = 'org-a'): ObjectEntity {
		$e = new ObjectEntity();
		$e->setUuid('ref-uuid');
		$e->setRegister('22');
		$e->setSchema('237');
		$e->setOwner('admin');
		$e->setOrganisation($organisation);
		$e->setObject($data);
		return $e;
	}//end entity()

	/**
	 * The declaration dossiq's case schema carries for its case type.
	 *
	 * @return array<string, mixed>
	 */
	private function caseTypeReference(): array {
		return ['caseType' => ['schema' => 'caseType', 'mode' => 'relatedObject', 'field' => 'caseType']];
	}//end caseTypeReference()

	public function testResolveRelatedObjectByFk(): void {
		$captured = null;
		$this->objectService->expects($this->once())
			->method('find')
			->willReturnCallback(function (...$args) use (&$captured) {
				$captured = $args;
				return $this->entity(['acquisitionCost' => 10000]);
			});

		$payload = [
			'fixedAssetId' => 'fa-1',
			'@self' => ['uuid' => 'obj-1'],
		];
		$refs = $this->resolver->resolveAll(
			$payload,
			['asset' => ['schema' => 'FixedAsset', 'mode' => 'relatedObject', 'field' => 'fixedAssetId']],
			'reg-1',
			'org-a'
		);

		$this->assertSame(10000, $refs['asset']['acquisitionCost']);
		$this->assertSame('ref-uuid', $refs['asset']['@self']['uuid']);
		// The FK read runs as the system: the saver's session never narrows it.
		$this->assertNotNull($captured);
		$this->assertFalse($captured['_rbac'] ?? $captured[5]);
		$this->assertFalse($captured['_multitenancy'] ?? $captured[6]);
	}//end testResolveRelatedObjectByFk()

	public function testResolveLookupEffectiveDated(): void {
		$captured = null;
		$this->objectService->expects($this->once())
			->method('findAll')
			->willReturnCallback(function (array $config, bool $rbac, bool $multi) use (&$captured) {
				$captured = [$config, $rbac, $multi];
				// Most-recent valid row first (DESC sort applied by resolver).
				return [$this->entity(['ratePerKm' => 0.21, 'fiscalYear' => 2026])];
			});

		$payload = [
			'vehicleType' => 'car',
			'country' => 'NL',
			'journeyDate' => '2026-03-10',
			'@self' => [
				'vehicleType' => 'car',
				'country' => 'NL',
				'journeyDate' => '2026-03-10',
			],
		];
		$refs = $this->resolver->resolveAll(
			$payload,
			[
				'rate' => [
					'schema' => 'MileageRate',
					'mode' => 'lookup',
					'filters' => [
						'fiscalYear' => ['year' => '@self.journeyDate'],
						'vehicleType' => '@self.vehicleType',
						'country' => '@self.country',
					],
					'effectiveDate' => ['field' => 'fiscalYear', 'op' => 'lte', 'value' => '@self.journeyDate'],
				],
			],
			'reg-1',
			'org-a'
		);

		$this->assertSame(0.21, $refs['rate']['ratePerKm']);
		// Criteria parameterised by @self: year(journeyDate) => 2026.
		$this->assertSame(2026, $captured[0]['filters']['fiscalYear']);
		$this->assertSame('car', $captured[0]['filters']['vehicleType']);
		$this->assertSame('MileageRate', $captured[0]['filters']['schema']);
		// Read as the system; the tenant guard bounds the rows instead.
		$this->assertFalse($captured[1]);
		$this->assertFalse($captured[2]);
		// Effective-date selector applies a DESC sort to pick the latest row.
		$this->assertSame('DESC', $captured[0]['sort']['fiscalYear']);
	}//end testResolveLookupEffectiveDated()

	public function testMissingFkInjectsNull(): void {
		$this->objectService->expects($this->never())->method('find');
		$refs = $this->resolver->resolveAll(
			['@self' => []],
			['asset' => ['schema' => 'FixedAsset', 'mode' => 'relatedObject', 'field' => 'fixedAssetId']],
			'reg-1',
			'org-a'
		);
		$this->assertNull($refs['asset']);
	}//end testMissingFkInjectsNull()

	public function testEmptyLookupInjectsNull(): void {
		$this->objectService->method('findAll')->willReturn([]);
		$refs = $this->resolver->resolveAll(
			['vehicleType' => 'car', '@self' => ['vehicleType' => 'car']],
			['rate' => ['schema' => 'MileageRate', 'mode' => 'lookup', 'filters' => ['vehicleType' => '@self.vehicleType']]],
			'reg-1',
			'org-a'
		);
		$this->assertNull($refs['rate']);
	}//end testEmptyLookupInjectsNull()

	public function testExceptionDuringResolutionInjectsNullAndLogs(): void {
		$this->objectService->method('find')->willThrowException(new \RuntimeException('boom'));
		$this->logger->expects($this->once())->method('warning');
		$refs = $this->resolver->resolveAll(
			['fixedAssetId' => 'fa-1', '@self' => []],
			['asset' => ['schema' => 'FixedAsset', 'mode' => 'relatedObject', 'field' => 'fixedAssetId']],
			'reg-1',
			'org-a'
		);
		$this->assertNull($refs['asset']);
	}//end testExceptionDuringResolutionInjectsNullAndLogs()

	/**
	 * An anonymous portal write resolves the case type: the read is not
	 * narrowed by the (absent) session, and the row is in the case's tenant.
	 *
	 * @return void
	 */
	public function testAnonymousWebSaveResolvesASameTenantReference(): void {
		$this->objectService->expects($this->once())
			->method('find')
			->with('ct-1', $this->anything(), $this->anything(), 'reg-22', 'caseType', false, false)
			->willReturn($this->entity(['processingDeadline' => 'P8W'], 'org-a'));

		$outcome = $this->resolver->resolveAllWithOutcome(
			['caseType' => 'ct-1', '@self' => []],
			$this->caseTypeReference(),
			'reg-22',
			'org-a'
		);

		$this->assertSame('P8W', $outcome['refs']['caseType']['processingDeadline']);
		$this->assertSame([], $outcome['unresolved']);
	}//end testAnonymousWebSaveResolvesASameTenantReference()

	/**
	 * A reference to another tenant's object resolves empty and is reported unresolved.
	 *
	 * @return void
	 */
	public function testACrossTenantReferenceIsRefused(): void {
		$this->objectService->method('find')->willReturn($this->entity(['processingDeadline' => 'P8W'], 'org-b'));
		$this->sharedMasterData->method('holdersForResource')->willReturn([]);

		$outcome = $this->resolver->resolveAllWithOutcome(
			['caseType' => 'ct-1', '@self' => []],
			$this->caseTypeReference(),
			'reg-22',
			'org-a'
		);

		$this->assertNull($outcome['refs']['caseType']);
		$this->assertSame(['caseType'], $outcome['unresolved']);
	}//end testACrossTenantReferenceIsRefused()

	/**
	 * An object with no organisation cannot reach an organisation-owned one.
	 *
	 * @return void
	 */
	public function testAnOrglessObjectCannotReachAnOrganisationOwnedReference(): void {
		$this->objectService->method('find')->willReturn($this->entity(['processingDeadline' => 'P8W'], 'org-a'));

		$outcome = $this->resolver->resolveAllWithOutcome(
			['caseType' => 'ct-1', '@self' => []],
			$this->caseTypeReference(),
			'reg-22',
			null
		);

		$this->assertNull($outcome['refs']['caseType']);
		$this->assertSame(['caseType'], $outcome['unresolved']);
	}//end testAnOrglessObjectCannotReachAnOrganisationOwnedReference()

	/**
	 * A referenced object with no organisation belongs to no tenant and resolves.
	 *
	 * @return void
	 */
	public function testAnOrglessReferenceResolves(): void {
		$this->objectService->method('find')->willReturn($this->entity(['processingDeadline' => 'P8W'], null));

		$outcome = $this->resolver->resolveAllWithOutcome(
			['caseType' => 'ct-1', '@self' => []],
			$this->caseTypeReference(),
			'reg-22',
			'org-a'
		);

		$this->assertSame('P8W', $outcome['refs']['caseType']['processingDeadline']);
	}//end testAnOrglessReferenceResolves()

	/**
	 * A row held by a parent organisation resolves, as it would for a member.
	 *
	 * @return void
	 */
	public function testAParentOrganisationsReferenceResolves(): void {
		$organisations = $this->getMockBuilder(OrganisationMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findParentChain'])
			->getMock();
		$organisations->method('findParentChain')->with('org-a')->willReturn(['org-parent']);
		$resolver = new ReferenceResolver(
			$this->objectService,
			new ReferenceTenantGuard($organisations, $this->sharedMasterData, $this->logger),
			$this->logger
		);
		$this->objectService->method('find')->willReturn($this->entity(['processingDeadline' => 'P8W'], 'org-parent'));

		$outcome = $resolver->resolveAllWithOutcome(
			['caseType' => 'ct-1', '@self' => []],
			$this->caseTypeReference(),
			'reg-22',
			'org-a'
		);

		$this->assertSame('P8W', $outcome['refs']['caseType']['processingDeadline']);
	}//end testAParentOrganisationsReferenceResolves()

	/**
	 * A row in shared master data resolves for the consuming organisation.
	 *
	 * @return void
	 */
	public function testASharedMasterDataReferenceResolves(): void {
		$this->sharedMasterData->expects($this->once())
			->method('holdersForResource')
			->with(22, 237, ['org-a'])
			->willReturn(['org-holder']);
		$this->objectService->method('find')->willReturn($this->entity(['processingDeadline' => 'P8W'], 'org-holder'));

		$outcome = $this->resolver->resolveAllWithOutcome(
			['caseType' => 'ct-1', '@self' => []],
			$this->caseTypeReference(),
			'reg-22',
			'org-a'
		);

		$this->assertSame('P8W', $outcome['refs']['caseType']['processingDeadline']);
	}//end testASharedMasterDataReferenceResolves()

	/**
	 * A filled key whose target is gone is unresolved; an empty key is a real null.
	 *
	 * @return void
	 */
	public function testAMissingTargetIsUnresolvedButAnEmptyKeyIsNot(): void {
		$this->objectService->method('find')->willReturn(null);

		$missing = $this->resolver->resolveAllWithOutcome(
			['caseType' => 'ct-gone', '@self' => []],
			$this->caseTypeReference(),
			'reg-22',
			'org-a'
		);
		$empty = $this->resolver->resolveAllWithOutcome(
			['caseType' => '', '@self' => []],
			$this->caseTypeReference(),
			'reg-22',
			'org-a'
		);

		$this->assertSame(['caseType'], $missing['unresolved']);
		$this->assertNull($empty['refs']['caseType']);
		$this->assertSame([], $empty['unresolved']);
	}//end testAMissingTargetIsUnresolvedButAnEmptyKeyIsNot()

	/**
	 * A lookup skips another tenant's rows and takes the first admitted one.
	 *
	 * @return void
	 */
	public function testALookupSkipsAnotherTenantsRows(): void {
		$foreign = $this->entity(['ratePerKm' => 0.99], 'org-b');
		$own = $this->entity(['ratePerKm' => 0.21], 'org-a');
		$this->objectService->method('findAll')->willReturn([$foreign, $own]);
		$this->sharedMasterData->method('holdersForResource')->willReturn([]);

		$outcome = $this->resolver->resolveAllWithOutcome(
			['vehicleType' => 'car', '@self' => []],
			['rate' => ['schema' => 'MileageRate', 'mode' => 'lookup', 'filters' => ['vehicleType' => '@self.vehicleType']]],
			'reg-1',
			'org-a'
		);

		$this->assertSame(0.21, $outcome['refs']['rate']['ratePerKm']);
		$this->assertSame([], $outcome['unresolved']);
	}//end testALookupSkipsAnotherTenantsRows()
}//end class
