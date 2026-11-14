<?php

/**
 * A database source's query is guarded when the schema configuration is saved.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\Schema;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 */
final class ObjectSourceQueryGuardOnSaveTest extends TestCase {

	/**
	 * A configuration whose object source reads the given query.
	 *
	 * @param string $query    The query.
	 * @param string $provider The provider.
	 *
	 * @return array<string, mixed> The configuration.
	 */
	private static function sourced(string $query, string $provider = 'dbal-source'): array {
		return [
			'x-openregister-object-source' => [
				'provider' => $provider,
				'readOnly' => true,
				'config' => ['sourceId' => 4, 'query' => $query],
			],
		];
	}//end sourced()

	public function testAJoinIsSaved(): void {
		$query = 'SELECT p.id, p.name, o.title FROM people p JOIN orgs o ON o.id = p.org_id';
		$schema = new Schema();

		$schema->setConfiguration(self::sourced($query));

		$this->assertSame($query, $schema->getObjectSource()['config']['query']);
	}//end testAJoinIsSaved()

	public function testADataChangingQueryFailsTheWholeSaveAndKeepsTheStoredConfiguration(): void {
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-object-source' => ['provider' => 'dbal-source', 'config' => ['sourceId' => 4, 'table' => 'people']]]);

		try {
			$schema->setConfiguration(self::sourced('WITH gone AS (DELETE FROM people RETURNING id) SELECT * FROM gone'));
			$this->fail('A query that deletes must not be saved.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame(
				'x-openregister-object-source.config.query was refused: A query-backed schema may not use "DELETE".',
				$e->getMessage()
			);
		}

		$this->assertSame('people', $schema->getObjectSource()['config']['table'], 'The stored source is left as it was.');
		$this->assertArrayNotHasKey('query', $schema->getObjectSource()['config']);
	}//end testADataChangingQueryFailsTheWholeSaveAndKeepsTheStoredConfiguration()

	public function testTwoStatementsAreRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('only one statement');

		(new Schema())->setConfiguration(self::sourced('SELECT 1; DROP TABLE people'));
	}//end testTwoStatementsAreRefused()

	public function testAKeywordInsideALiteralOrAHashCommentIsNotARefusal(): void {
		$query = "SELECT id FROM people WHERE note = 'update later' # delete when migrated\n";
		$schema = new Schema();

		$schema->setConfiguration(self::sourced($query));

		$this->assertSame($query, $schema->getObjectSource()['config']['query']);
	}//end testAKeywordInsideALiteralOrAHashCommentIsNotARefusal()

	public function testAnotherProvidersConfigIsNotReadAsSql(): void {
		$schema = new Schema();

		$schema->setConfiguration(self::sourced('update the list weekly', 'tables'));

		$this->assertSame('tables', $schema->getObjectSource()['provider']);
	}//end testAnotherProvidersConfigIsNotReadAsSql()

	public function testATableSourceWithoutAQueryIsSaved(): void {
		$schema = new Schema();

		$config = ['sourceId' => 4, 'table' => 'people', 'query' => ' '];
		$schema->setConfiguration(['x-openregister-object-source' => ['provider' => 'dbal-source', 'config' => $config]]);

		$this->assertSame('people', $schema->getObjectSource()['config']['table']);
	}//end testATableSourceWithoutAQueryIsSaved()
}//end class
