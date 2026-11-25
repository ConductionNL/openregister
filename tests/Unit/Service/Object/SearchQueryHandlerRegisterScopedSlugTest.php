<?php

/**
 * A schema slug in a search resolves among the searched register's own schemas first.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\SearchReferenceResolver;
use OCA\OpenRegister\Service\Object\ViewScopeApplier;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The parameter parsing of buildSearchQuery() reads the register's schema for a slug.
 *
 * Two schemas answer the slug `zaak`: the searched register's own one declares a
 * property called `filter`, the other register's one does not. Which one is read
 * decides whether `filter[x]=v` stays a filter on that property or is lifted to `x=v`.
 *
 * @covers \OCA\OpenRegister\Service\Object\SearchQueryHandler
 * @uses   \OCA\OpenRegister\Service\Schemas\SchemaRefInRegister
 */
final class SearchQueryHandlerRegisterScopedSlugTest extends TestCase {

	/**
	 * The register's own schema decides: its `filter` property keeps the bracket filter.
	 *
	 * @return void
	 */
	public function testTheRegistersOwnSchemaDecides(): void {
		$schemas = $this->schemas();
		$schemas->expects($this->atLeastOnce())->method('findBySlugInIds')->with('zaak', [11, 12])->willReturn($this->schema(declaresFilter: true));
		$schemas->expects($this->never())->method('find');

		$query = $this->handler(schemas: $schemas, registers: $this->registers())
			->buildSearchQuery(['filter' => ['origin' => 'manual']], 5, 'zaak');

		$this->assertSame(['origin' => 'manual'], ($query['filter'] ?? null));
		$this->assertArrayNotHasKey('origin', $query);
	}//end testTheRegistersOwnSchemaDecides()

	/**
	 * A slug the register does not list falls back to the global lookup.
	 *
	 * @return void
	 */
	public function testASlugOutsideTheRegisterFallsBackToTheGlobalLookup(): void {
		$schemas = $this->schemas();
		$schemas->method('findBySlugInIds')->willReturn(null);
		$schemas->expects($this->atLeastOnce())->method('find')->with('zaak')->willReturn($this->schema(declaresFilter: false));

		$query = $this->handler(schemas: $schemas, registers: $this->registers())
			->buildSearchQuery(['filter' => ['origin' => 'manual']], 5, 'zaak');

		$this->assertSame('manual', ($query['origin'] ?? null));
	}//end testASlugOutsideTheRegisterFallsBackToTheGlobalLookup()

	/**
	 * Without a register reader the slug resolves globally, as before.
	 *
	 * @return void
	 */
	public function testWithoutARegisterReaderTheSlugResolvesGlobally(): void {
		$schemas = $this->schemas();
		$schemas->expects($this->never())->method('findBySlugInIds');
		$schemas->method('find')->willReturn($this->schema(declaresFilter: false));

		$query = $this->handler(schemas: $schemas, registers: null)
			->buildSearchQuery(['filter' => ['origin' => 'manual']], 5, 'zaak');

		$this->assertSame('manual', ($query['origin'] ?? null));
	}//end testWithoutARegisterReaderTheSlugResolvesGlobally()

	/**
	 * A search over several registers has no one register to scope to.
	 *
	 * @return void
	 */
	public function testSeveralRegistersResolveGlobally(): void {
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->expects($this->never())->method('find');
		$schemas = $this->schemas();
		$schemas->expects($this->never())->method('findBySlugInIds');
		$schemas->method('find')->willReturn($this->schema(declaresFilter: true));

		$query = $this->handler(schemas: $schemas, registers: $registers)
			->buildSearchQuery(['filter' => ['origin' => 'manual']], [5, 6], 'zaak');

		$this->assertSame(['origin' => 'manual'], ($query['filter'] ?? null));
	}//end testSeveralRegistersResolveGlobally()

	/**
	 * A register reader that finds register 5 listing schemas 11 and 12.
	 *
	 * @return RegisterMapperInterface
	 */
	private function registers(): RegisterMapperInterface {
		$register = $this->createMock(Register::class);
		$register->method('getSchemas')->willReturn([11, 12]);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->with(5)->willReturn($register);
		return $registers;
	}//end registers()

	/**
	 * A schema mapper mock.
	 *
	 * @return SchemaMapperInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function schemas(): SchemaMapperInterface {
		return $this->createMock(SchemaMapperInterface::class);
	}//end schemas()

	/**
	 * A schema without an object source, declaring a `filter` property or not.
	 *
	 * @param bool $declaresFilter Whether it declares the property.
	 *
	 * @return Schema
	 */
	private function schema(bool $declaresFilter): Schema {
		$properties = ['origin' => ['type' => 'string']];
		if ($declaresFilter === true) {
			$properties['filter'] = ['type' => 'object'];
		}

		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties);
		$schema->method('getObjectSource')->willReturn(null);
		return $schema;
	}//end schema()

	/**
	 * The handler under test.
	 *
	 * @param SchemaMapperInterface        $schemas   The schema mapper.
	 * @param RegisterMapperInterface|null $registers The register reader.
	 *
	 * @return SearchQueryHandler
	 */
	private function handler(SchemaMapperInterface $schemas, ?RegisterMapperInterface $registers): SearchQueryHandler {
		// The scope filter reads the references to ids through its own resolver.
		$references = $this->createMock(SearchReferenceResolver::class);
		$references->method('register')->willReturnArgument(0);
		$references->method('schema')->willReturn(11);

		return new SearchQueryHandler(
			viewScope: $this->createMock(ViewScopeApplier::class),
			schemaMapper: $schemas,
			settingsService: $this->createMock(SettingsServiceInterface::class),
			logger: $this->createMock(LoggerInterface::class),
			searchTrailService: $this->createMock(SearchTrailService::class),
			referenceResolver: $references,
			registerMapper: $registers
		);
	}//end handler()
}//end class
