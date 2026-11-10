<?php

/**
 * The view-backed object source's identity and its single-object lookup:
 * find asks the view's source table for exactly that uuid, one row, and
 * answers null when the view does not hold it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use OCA\OpenRegister\Service\ObjectSource\ViewObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\ViewSourceMappers;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Lookup tests for {@see ViewObjectSourceProvider}.
 */
class ViewObjectSourceProviderFindTest extends TestCase {

	/**
	 * A provider over one view naming register 3 / schema 9, whose source
	 * search returns the given rows and records the query it got.
	 *
	 * @param array<int, ObjectEntity> $rows The rows the search returns.
	 * @param array<int, array<string, mixed>> $queries Where the queries land.
	 *
	 * @return ViewObjectSourceProvider The provider.
	 */
	private function provider(array $rows, array &$queries): ViewObjectSourceProvider {
		$view = new View();
		$view->setQuery(['registers' => [3], 'schemas' => [9]]);
		$views = $this->createMock(ViewMapperInterface::class);
		$views->method('find')->with('v-1', false, false)->willReturn($view);
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->with(3)->willReturn(new Register());
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->with(9)->willReturn(new Schema());
		$reader = $this->createMock(MagicMapperInterface::class);
		$reader->method('searchObjectsInRegisterSchemaTable')->willReturnCallback(
			static function (array $query) use ($rows, &$queries): array {
				$queries[] = $query;

				return $rows;
			}
		);

		return new ViewObjectSourceProvider(
			mappers: new ViewSourceMappers(viewMapper: $views, catalog: new SchemaCatalog(registers: $registers, schemas: $schemas, tables: $reader)),
			logger: new NullLogger()
		);
	}//end provider()

	/**
	 * Find asks for exactly that uuid and one row, under RBAC, and returns
	 * the row the source holds.
	 *
	 * @return void
	 */
	public function testFindAsksTheSourceForThatUuidOnly(): void {
		$row = new ObjectEntity();
		$row->setUuid('o-1');
		$queries = [];
		$provider = $this->provider(rows: [$row], queries: $queries);

		$found = $provider->find(register: new Register(), schema: new Schema(), id: 'o-1', config: ['view' => 'v-1']);

		$this->assertSame($row, $found);
		$this->assertSame(['o-1'], $queries[0]['_ids']);
		$this->assertSame(1, $queries[0]['_limit']);
		$this->assertTrue($queries[0]['_rbac']);
		$this->assertSame('view', $provider->getId());
		$this->assertTrue($provider->isEnabled());
	}//end testFindAsksTheSourceForThatUuidOnly()

	/**
	 * A uuid the view does not hold reads as null.
	 *
	 * @return void
	 */
	public function testFindAnswersNullWhenTheViewDoesNotHoldIt(): void {
		$queries = [];

		$this->assertNull($this->provider(rows: [], queries: $queries)->find(register: new Register(), schema: new Schema(), id: 'o-2', config: ['view' => 'v-1']));
		$this->assertSame(['o-2'], $queries[0]['_ids']);
	}//end testFindAnswersNullWhenTheViewDoesNotHoldIt()
}//end class
