<?php

/**
 * Unit tests for BaseSchemaProposer: a base proposed as a register, schemas and relations.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Service\Import\BaseColumn;
use OCA\OpenRegister\Service\Import\BaserowBaseReader;
use OCA\OpenRegister\Service\Import\BaserowCells;
use OCA\OpenRegister\Service\Import\BaseSchemaProposer;
use OCA\OpenRegister\Service\Import\BaseSet;
use OCA\OpenRegister\Service\Import\BaseTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BaseSchemaProposer::class)]
#[CoversClass(BaseSet::class)]
#[CoversClass(BaseTable::class)]
#[CoversClass(BaseColumn::class)]
#[UsesClass(BaserowBaseReader::class)]
#[UsesClass(BaserowCells::class)]
final class BaseSchemaProposerTest extends TestCase {

	private const FIXTURE = __DIR__.'/../../../Fixtures/import/baserow-projecten-taken.json';

	public function testTheTwoTableFixtureGivesTwoSchemasAndOneRelation(): void {
		$proposal = (new BaseSchemaProposer())->propose((new BaserowBaseReader())->read(self::FIXTURE));

		$this->assertSame(['title' => 'Projectbeheer', 'slug' => 'projectbeheer', 'source' => 'baserow'], $proposal['register']);
		$this->assertSame(['Projecten', 'Taken'], array_keys($proposal['schemas']));
		$this->assertSame('taken', $proposal['schemas']['Taken']['slug']);
		$this->assertSame(
			[['schema' => 'taken', 'property' => 'project', 'target' => 'projecten', 'multiple' => false]],
			$proposal['relations']
		);
	}

	public function testEveryColumnBecomesATypedProperty(): void {
		$proposal = (new BaseSchemaProposer())->propose((new BaserowBaseReader())->read(self::FIXTURE));
		$taken    = $proposal['schemas']['Taken'];

		$this->assertSame(
			['Titel' => 'titel', 'Project' => 'project', 'Status' => 'status', 'Deadline' => 'deadline', 'Afgerond' => 'afgerond'],
			$taken['columns']
		);
		$this->assertSame(['title' => 'Project', 'type' => 'string', 'format' => 'uuid', '$ref' => 'projecten'], $taken['properties']['project']);
		$this->assertSame(['title' => 'Deadline', 'type' => 'string', 'format' => 'date'], $taken['properties']['deadline']);
		$this->assertSame(['title' => 'Afgerond', 'type' => 'boolean'], $taken['properties']['afgerond']);
		$this->assertSame(['title' => 'Budget', 'type' => 'integer'], $proposal['schemas']['Projecten']['properties']['budget']);
	}

	public function testADanglingLinkIsAPerRowOutcomeNotAFailure(): void {
		$proposal = (new BaseSchemaProposer())->propose((new BaserowBaseReader())->read(self::FIXTURE));

		$this->assertCount(1, $proposal['outcomes']);
		$this->assertSame(
			['table' => 'Taken', 'row' => '3', 'column' => 'Project', 'missing' => '99'],
			array_diff_key($proposal['outcomes'][0], ['message' => true])
		);
		$this->assertStringContainsString('imported without that link', $proposal['outcomes'][0]['message']);
	}

	public function testAMultipleLinkIsAnArrayOfReferences(): void {
		$set = new BaseSet('B', 'test', [
			new BaseTable('A', [new BaseColumn('Naam', BaseColumn::TYPE_STRING, primary: true)], ['1' => ['Naam' => 'x']]),
			new BaseTable('C', [new BaseColumn('Links', BaseColumn::TYPE_LINK, 'A', true)], ['1' => ['Links' => ['1']]]),
		]);

		$proposal = (new BaseSchemaProposer())->propose($set);

		$this->assertSame(
			['title' => 'Links', 'type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid', '$ref' => 'a']],
			$proposal['schemas']['C']['properties']['links']
		);
		$this->assertSame([], $proposal['outcomes']);
	}

	public function testClashingNamesGetASuffixAndEmptyNamesAFallback(): void {
		$set = new BaseSet('!!!', 'test', [
			new BaseTable('Taak', [
				new BaseColumn('E-mail', BaseColumn::TYPE_EMAIL),
				new BaseColumn('E mail', BaseColumn::TYPE_URL),
				new BaseColumn('???', BaseColumn::TYPE_LIST),
				new BaseColumn('Extern', BaseColumn::TYPE_LINK),
			], []),
			new BaseTable('taak', [], []),
		]);

		$proposal = (new BaseSchemaProposer())->propose($set);

		$this->assertSame('base', $proposal['register']['slug']);
		$this->assertSame('taak_2', $proposal['schemas']['taak']['slug']);
		$this->assertSame(
			['E-mail' => 'e_mail', 'E mail' => 'e_mail_2', '???' => 'column', 'Extern' => 'extern'],
			$proposal['schemas']['Taak']['columns']
		);
		$this->assertSame('email', $proposal['schemas']['Taak']['properties']['e_mail']['format']);
		$this->assertSame('uri', $proposal['schemas']['Taak']['properties']['e_mail_2']['format']);
		$this->assertSame(['title' => 'Extern', 'type' => 'array', 'items' => ['type' => 'string']], $proposal['schemas']['Taak']['properties']['extern']);
		$this->assertSame([], $proposal['relations']);
	}

	public function testASetRefusesDuplicateTablesAndLinksOutsideIt(): void {
		try {
			new BaseSet('B', 'test', [new BaseTable('A', [], []), new BaseTable('A', [], [])]);
			$this->fail('Two tables named A were accepted.');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('two tables named "A"', $e->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('links to table "Elders", which is not in the base');
		new BaseSet('B', 'test', [new BaseTable('A', [new BaseColumn('L', BaseColumn::TYPE_LINK, 'Elders')], [])]);
	}
}
