<?php

/**
 * The name enricher stamps each trail's register and schema title, falling back to a label on a miss.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\SearchTrail;
use OCA\OpenRegister\Service\SearchTrailNameEnricher;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\SearchTrailNameEnricher
 */
class SearchTrailNameEnricherTest extends TestCase {

	private function trail(?int $register, ?int $schema): SearchTrail {
		$trail = new SearchTrail();
		$trail->setRegister($register);
		$trail->setSchema($schema);

		return $trail;
	}//end trail()

	public function testEachTrailGetsItsRegisterAndSchemaTitle(): void {
		$registerMapper = $this->createMock(RegisterMapperInterface::class);
		$schemaMapper = $this->createMock(SchemaMapperInterface::class);

		$register = new Register();
		$register->setTitle('Klanten');
		$schema = new Schema();
		$schema->setTitle('Klant');

		// Ids are de-duplicated before lookup: one find per distinct id.
		$registerMapper->expects($this->once())->method('find')->with(5)->willReturn($register);
		$schemaMapper->expects($this->once())->method('find')->with(9)->willReturn($schema);

		$trails = [$this->trail(5, 9), $this->trail(5, 9)];
		$enriched = (new SearchTrailNameEnricher($registerMapper, $schemaMapper))->enrich($trails);

		$this->assertSame('Klanten', $enriched[0]->getRegisterName());
		$this->assertSame('Klant', $enriched[0]->getSchemaName());
		$this->assertSame('Klanten', $enriched[1]->getRegisterName());
	}//end testEachTrailGetsItsRegisterAndSchemaTitle()

	public function testAMissingRegisterOrSchemaFallsBackToALabel(): void {
		$registerMapper = $this->createMock(RegisterMapperInterface::class);
		$schemaMapper = $this->createMock(SchemaMapperInterface::class);

		$registerMapper->method('find')->willThrowException(new DoesNotExistException('gone'));
		$schemaMapper->method('find')->willThrowException(new DoesNotExistException('gone'));

		$enriched = (new SearchTrailNameEnricher($registerMapper, $schemaMapper))->enrich([$this->trail(7, 8)]);

		$this->assertSame('Register 7', $enriched[0]->getRegisterName());
		$this->assertSame('Schema 8', $enriched[0]->getSchemaName());
	}//end testAMissingRegisterOrSchemaFallsBackToALabel()

	public function testATrailWithoutIdsIsLeftUnnamedAndNothingIsLookedUp(): void {
		$registerMapper = $this->createMock(RegisterMapperInterface::class);
		$schemaMapper = $this->createMock(SchemaMapperInterface::class);

		$registerMapper->expects($this->never())->method('find');
		$schemaMapper->expects($this->never())->method('find');

		$enriched = (new SearchTrailNameEnricher($registerMapper, $schemaMapper))->enrich([$this->trail(null, null)]);

		$this->assertNull($enriched[0]->getRegisterName());
		$this->assertNull($enriched[0]->getSchemaName());
	}//end testATrailWithoutIdsIsLeftUnnamedAndNothingIsLookedUp()
}//end class
