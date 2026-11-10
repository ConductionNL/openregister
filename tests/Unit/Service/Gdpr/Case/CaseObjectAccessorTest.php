<?php

/**
 * Loading, listing and saving DSAR cases through the object layer.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Case;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\DsarService;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessorInterface;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every case read is RBAC- and tenant-scoped; a listed row without uuid, or one the caller
 * cannot load, is dropped; a save stamps the DSAR processing activity when one exists.
 *
 * @covers \OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor
 * @uses \OCA\OpenRegister\Service\WriteCause
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
final class CaseObjectAccessorTest extends TestCase {

	private ObjectOperationsInterface&MockObject $objects;

	private DsarService&MockObject $dsar;

	private CaseObjectAccessor $accessor;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->dsar = $this->createMock(DsarService::class);
		$this->accessor = new CaseObjectAccessor(objectService: $this->objects, dsarService: $this->dsar);
	}//end setUp()

	/**
	 * Listed rows are reloaded by uuid (from @self or id); rows without uuid or unloadable are dropped.
	 *
	 * @return void
	 */
	public function testListedCasesAreReloadedAndUnloadableRowsDropped(): void {
		$caseA = new ObjectEntity();
		$caseA->setUuid('a');
		$this->objects->expects($this->once())->method('findAll')->with(
			['filters' => ['register' => CaseObjectAccessorInterface::REGISTER_SLUG, 'schema' => CaseObjectAccessorInterface::SCHEMA_SLUG]],
			true,
			true
		)->willReturn([['@self' => ['uuid' => 'a']], ['id' => 'b'], 'not a row', ['@self' => []]]);
		$loaded = [];
		$this->objects->method('find')->willReturnCallback(
			static function (...$args) use ($caseA, &$loaded): ?ObjectEntity {
				[$id, $_extend, $files, $register, $schema, $_rbac, $_multitenancy] = FindCallShape::find(args: $args);
				$loaded[] = [$id, $register, $schema, $_rbac, $_multitenancy];
				return $id === 'a' ? $caseA : null;
			}
		);

		$this->assertSame([$caseA], $this->accessor->findAllCaseEntities());
		$this->assertSame(
			[
				['a', CaseObjectAccessorInterface::REGISTER_SLUG, CaseObjectAccessorInterface::SCHEMA_SLUG, true, true],
				['b', CaseObjectAccessorInterface::REGISTER_SLUG, CaseObjectAccessorInterface::SCHEMA_SLUG, true, true],
			],
			$loaded
		);
	}//end testListedCasesAreReloadedAndUnloadableRowsDropped()

	/**
	 * The retention sweep deletes through the scoped object layer with the sweep flag.
	 *
	 * @return void
	 */
	public function testTheSweepDeletesWithTheRetentionFlag(): void {
		$this->objects->expects($this->once())->method('deleteObject')->with(
			'case-1',
			CaseObjectAccessorInterface::REGISTER_SLUG,
			CaseObjectAccessorInterface::SCHEMA_SLUG,
			true,
			true,
			true
		)->willReturn(false);

		$this->assertFalse($this->accessor->deleteForSweep(caseUuid: 'case-1'));
	}//end testTheSweepDeletesWithTheRetentionFlag()

	/**
	 * A save writes the data, stamps the processing activity, and saves scoped under the case uuid.
	 *
	 * @return void
	 */
	public function testASaveStampsTheProcessingActivity(): void {
		$case = new ObjectEntity();
		$case->setUuid('case-1');
		$saved = new ObjectEntity();
		$this->dsar->method('getDsarProcessingActivityUuid')->willReturn('pa-9');
		$this->objects->expects($this->once())->method('saveObject')->with(
			$case,
			[],
			CaseObjectAccessorInterface::REGISTER_SLUG,
			CaseObjectAccessorInterface::SCHEMA_SLUG,
			'case-1',
			true,
			true
		)->willReturn($saved);

		$this->assertSame($saved, $this->accessor->save(case: $case, data: ['status' => 'open']));
		$this->assertSame('open', $case->getObject()['status']);
		$this->assertSame('pa-9', $case->getProcessingActivityId());
	}//end testASaveStampsTheProcessingActivity()

	/**
	 * Without a DSAR processing activity the case keeps the one it had.
	 *
	 * @return void
	 */
	public function testWithoutAnActivityTheCaseKeepsItsOwn(): void {
		$case = new ObjectEntity();
		$case->setUuid('case-1');
		$case->setProcessingActivityId('existing');
		$this->dsar->method('getDsarProcessingActivityUuid')->willReturn(null);
		$this->objects->method('saveObject')->willReturnArgument(0);

		$this->assertSame('existing', $this->accessor->save(case: $case, data: [])->getProcessingActivityId());
	}//end testWithoutAnActivityTheCaseKeepsItsOwn()
}//end class
