<?php

/**
 * A paged export reads one bounded page of its selection and counts the whole of it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Export\ExportPage;
use OCA\OpenRegister\Service\Export\ExportRequest;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\Rbac\AnonymousEnvelope;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExportService::class)]
#[UsesClass(ExportRequest::class)]
#[UsesClass(ExportPage::class)]
#[UsesClass(AnonymousEnvelope::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(Register::class)]
#[UsesClass(Schema::class)]
final class ExportServiceAnonymousPagingTest extends TestCase {

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objects;

	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectService::class);
	}//end setUp()

	/**
	 * The export service over the mocked object service.
	 *
	 * @return ExportService
	 */
	private function service(): ExportService {
		return new ExportService(
			$this->createMock(RegisterMapper::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->objects,
			$this->createMock(CacheHandler::class),
			$this->createMock(PropertyRbacHandler::class)
		);
	}//end service()

	/**
	 * An anonymous export of register 3, schema 9, filtered on `thema`.
	 *
	 * @param ExportPage|null $page The page, or null for every row.
	 *
	 * @return ExportRequest
	 */
	private function request(?ExportPage $page): ExportRequest {
		$register = new Register();
		$register->setId(3);
		$schema = new Schema();
		$schema->setId(9);

		$request = new ExportRequest(register: $register, schema: $schema, filters: ['thema' => 'milieu', '_page' => '2', '@self.owner' => 'jan']);
		if ($page === null) {
			return $request;
		}

		return $request->withPage(page: $page);
	}//end request()

	/**
	 * The second page reads 10,000 rows from offset 10,000, under RBAC and multitenancy.
	 *
	 * @return void
	 */
	public function testAPageReadsOneBoundedSlice(): void {
		$this->objects->expects($this->once())->method('searchObjects')->with(
			$this->callback(function (array $query): bool {
				$this->assertSame(10000, $query['_limit']);
				$this->assertSame(10000, $query['_offset']);
				$this->assertSame(['register' => 3, 'schema' => 9, 'owner' => 'jan'], $query['@self']);
				$this->assertSame('milieu', $query['thema']);
				$this->assertArrayNotHasKey('_page', $query);
				return true;
			}),
			true,
			true
		)->willReturn([]);

		$this->service()->fetchExportObjects(request: $this->request(page: new ExportPage(number: 2)));
	}//end testAPageReadsOneBoundedSlice()

	/**
	 * Without a page the read is unbounded, as a signed-in export always was.
	 *
	 * @return void
	 */
	public function testWithoutAPageEveryRowIsRead(): void {
		$this->objects->expects($this->once())->method('searchObjects')->with(
			$this->callback(function (array $query): bool {
				$this->assertFalse($query['_limit']);
				$this->assertArrayNotHasKey('_offset', $query);
				return true;
			})
		)->willReturn([]);

		$this->service()->fetchExportObjects(request: $this->request(page: null));
	}//end testWithoutAPageEveryRowIsRead()

	/**
	 * The total counts the same selection, without a limit or offset.
	 *
	 * @return void
	 */
	public function testTheTotalCountsTheWholeSelection(): void {
		$this->objects->expects($this->never())->method('searchObjects');
		$this->objects->expects($this->once())->method('countSearchObjects')->with(
			$this->callback(function (array $query): bool {
				$this->assertArrayNotHasKey('_limit', $query);
				$this->assertArrayNotHasKey('_offset', $query);
				$this->assertSame(['register' => 3, 'schema' => 9, 'owner' => 'jan'], $query['@self']);
				$this->assertSame('milieu', $query['thema']);
				return true;
			}),
			true,
			true,
			null,
			null
		)->willReturn(23500);

		$this->assertSame(23500, $this->service()->countExportTotal(request: $this->request(page: new ExportPage(number: 2))));
	}//end testTheTotalCountsTheWholeSelection()

	/**
	 * A decision owned by alice, with an envelope nested in a property.
	 *
	 * @return ObjectEntity
	 */
	private function decision(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('11111111-1111-4111-8111-111111111111');
		$object->setOwner('alice');
		$object->setObject(['titel' => 'Besluit milieu', 'zaak' => ['naam' => 'Z1', '@self' => ['owner' => 'bob', 'id' => 'z1']]]);

		return $object;
	}//end decision()

	/**
	 * A public JSON export carries the data without the `@self` block or a nested owner.
	 *
	 * @return void
	 */
	public function testAPublicJsonExportLeavesOutTheAdministration(): void {
		$this->objects->method('searchObjects')->willReturn([$this->decision()]);

		$rows = json_decode($this->service()->jsonFor(request: $this->request(page: new ExportPage())->forThePublic()), true);

		$this->assertCount(1, $rows);
		$this->assertArrayNotHasKey('@self', $rows[0]);
		$this->assertSame('Besluit milieu', $rows[0]['titel']);
		$this->assertSame('Z1', $rows[0]['zaak']['naam']);
		$this->assertSame(['id' => 'z1'], $rows[0]['zaak']['@self']);
		$this->assertStringNotContainsString('alice', json_encode($rows));
	}//end testAPublicJsonExportLeavesOutTheAdministration()

	/**
	 * A public XML export leaves out the owner too, and keeps the record id.
	 *
	 * @return void
	 */
	public function testAPublicXmlExportLeavesOutTheAdministration(): void {
		$this->objects->method('searchObjects')->willReturn([$this->decision()]);

		$xml = $this->service()->xmlFor(request: $this->request(page: new ExportPage())->forThePublic());

		$this->assertStringNotContainsString('alice', $xml);
		$this->assertStringContainsString('Besluit milieu', $xml);
	}//end testAPublicXmlExportLeavesOutTheAdministration()

	/**
	 * A signed-in JSON export keeps its `@self` block, as before.
	 *
	 * @return void
	 */
	public function testASignedInJsonExportKeepsTheAdministration(): void {
		$this->objects->method('searchObjects')->willReturn([$this->decision()]);

		$rows = json_decode($this->service()->jsonFor(request: $this->request(page: null)), true);

		$this->assertSame('alice', $rows[0]['@self']['owner']);
	}//end testASignedInJsonExportKeepsTheAdministration()
}//end class
