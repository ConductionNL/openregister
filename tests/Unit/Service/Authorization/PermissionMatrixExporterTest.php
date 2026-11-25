<?php

/**
 * An administrator exports the full authorisation matrix with provenance (REQ-RAH-001).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Authorization;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Authorization\PermissionMatrixExport;
use OCA\OpenRegister\Service\Authorization\PermissionMatrixExporter;
use OCA\OpenRegister\Service\Authorization\PermissionMatrixProvenance;
use OCA\OpenRegister\Service\Authorization\PermissionMatrixRequest;
use OCA\OpenRegister\Service\Authorization\PermissionMatrixSubject;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see PermissionMatrixExporter} and the export it seals.
 */
#[CoversClass(PermissionMatrixExporter::class)]
#[CoversClass(PermissionMatrixExport::class)]
#[CoversClass(PermissionMatrixSubject::class)]
class PermissionMatrixExporterTest extends TestCase {

	/**
	 * The verbs the catalogue grants in these tests.
	 *
	 * @var array<int, string>
	 */
	private const VERBS = ['read', 'update', 'delete'];

	/**
	 * The exporter under test.
	 */
	private PermissionMatrixExporter $exporter;

	/**
	 * Build the exporter over a three-verb catalogue.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$catalogue = $this->createMock(PermissionCatalogueInterface::class);
		$catalogue->method('verbs')->willReturn(self::VERBS);
		$catalogue->method('isGrantable')->willReturn(true);

		$this->exporter = new PermissionMatrixExporter(catalogue: $catalogue);
	}//end setUp()

	/**
	 * Every group, schema and action is a row, granted or not, and a schema
	 * outside the register is not paired with it.
	 *
	 * @return void
	 */
	public function testEveryGroupSchemaActionIsARow(): void {
		$besluit = $this->schema(id: 1, slug: 'besluit', authorization: ['read' => ['redactie', 'woo-coordinator'], 'update' => ['woo-coordinator']]);
		$document = $this->schema(id: 2, slug: 'document', authorization: null);
		$elsewhere = $this->schema(id: 3, slug: 'elders', authorization: null);
		$register = $this->register(slug: 'woo', schemas: [1, 2]);

		$rows = $this->rowsOf(
			new PermissionMatrixRequest(
				registers: [$register],
				schemas: [$besluit, $document, $elsewhere],
				subjects: [PermissionMatrixSubject::group('woo-coordinator'), PermissionMatrixSubject::group('redactie')]
			)
		);

		// 2 schemas x 2 groups x 3 verbs; `elders` belongs to no register in hand.
		self::assertCount(12, $rows);
		self::assertSame([], array_values(array_filter($rows, static fn (array $row): bool => $row['schema'] === 'elders')));

		self::assertSame(['true', 'schema-rule', 'schema'], $this->cell($rows, 'redactie', 'besluit', 'read'));
		self::assertSame(['false', 'none', ''], $this->cell($rows, 'redactie', 'besluit', 'update'));
		self::assertSame(['true', 'schema-rule', 'schema'], $this->cell($rows, 'woo-coordinator', 'besluit', 'update'));
		self::assertSame(['false', 'none', ''], $this->cell($rows, 'woo-coordinator', 'besluit', 'delete'));

		// No block on the schema or its register is the open default.
		self::assertSame(['true', 'default', ''], $this->cell($rows, 'redactie', 'document', 'delete'));
	}//end testEveryGroupSchemaActionIsARow()

	/**
	 * A deny is printed as the rule, and wins over the grant beside it.
	 *
	 * @return void
	 */
	public function testADenyIsPrintedAsTheRule(): void {
		$besluit = $this->schema(
			id: 1,
			slug: 'besluit',
			authorization: ['read' => ['redactie'], 'update' => ['redactie'], 'deny' => ['update' => ['redactie']]]
		);
		$request = new PermissionMatrixRequest(
			registers: [$this->register(slug: 'woo', schemas: [1])],
			schemas: [$besluit],
			subjects: [PermissionMatrixSubject::group('redactie')]
		);

		$rows = $this->rowsOf($request);

		self::assertSame(['false', 'deny', 'schema'], $this->cell($rows, 'redactie', 'besluit', 'update'));
		self::assertSame(['true', 'schema-rule', 'schema'], $this->cell($rows, 'redactie', 'besluit', 'read'));

		// Below enforcing the deny has not started biting: it is named, the grant stands.
		$staged = $this->rowsOf(
			new PermissionMatrixRequest(registers: $request->registers, schemas: $request->schemas, subjects: $request->subjects, denyEnforcing: false)
		);
		self::assertSame(['true', 'deny-staged', 'schema'], $this->cell($staged, 'redactie', 'besluit', 'update'));
	}//end testADenyIsPrintedAsTheRule()

	/**
	 * A register deny reaches a schema that writes its own block, and a register
	 * block stands in for a schema without one, roles included.
	 *
	 * @return void
	 */
	public function testTheRegisterBlockAndItsRolesDecideASchemaWithoutOne(): void {
		$besluit = $this->schema(id: 1, slug: 'besluit', authorization: ['read' => ['redactie'], 'delete' => ['redactie']]);
		$zaak = $this->schema(id: 2, slug: 'zaak', authorization: null);
		$register = $this->register(
			slug: 'woo',
			schemas: [1, 2],
			authorization: ['roles' => ['behandelaar' => ['redactie']], 'deny' => ['delete' => ['redactie']]],
			roles: [['name' => 'behandelaar', 'actions' => ['read', 'update']]]
		);

		$rows = $this->rowsOf(
			new PermissionMatrixRequest(registers: [$register], schemas: [$besluit, $zaak], subjects: [PermissionMatrixSubject::group('redactie')])
		);

		self::assertSame(['false', 'deny', 'register'], $this->cell($rows, 'redactie', 'besluit', 'delete'));
		self::assertSame(['true', 'role', 'register'], $this->cell($rows, 'redactie', 'zaak', 'update'));
		self::assertSame('behandelaar', $this->rowFor($rows, 'redactie', 'zaak', 'update')['role']);
		self::assertSame(['false', 'none', ''], $this->cell($rows, 'redactie', 'besluit', 'update'));
	}//end testTheRegisterBlockAndItsRolesDecideASchemaWithoutOne()

	/**
	 * A user row holds what any of their groups holds, and an administrator holds everything.
	 *
	 * @return void
	 */
	public function testAUserHoldsWhatTheirGroupsHoldAndAnAdministratorEverything(): void {
		$besluit = $this->schema(id: 1, slug: 'besluit', authorization: ['update' => ['redactie'], 'read' => ['authenticated']]);
		$rows = $this->rowsOf(
			new PermissionMatrixRequest(
				registers: [$this->register(slug: 'woo', schemas: [1])],
				schemas: [$besluit],
				subjects: [
					PermissionMatrixSubject::user('sanne', ['medewerkers', 'redactie']),
					PermissionMatrixSubject::user('pieter', ['admin']),
				]
			)
		);

		self::assertSame(['true', 'schema-rule', 'schema'], $this->cell($rows, 'sanne', 'besluit', 'update'));
		self::assertSame(['true', 'schema-rule', 'schema'], $this->cell($rows, 'sanne', 'besluit', 'read'));
		self::assertSame(['false', 'none', ''], $this->cell($rows, 'sanne', 'besluit', 'delete'));
		self::assertSame('medewerkers|redactie', $this->rowFor($rows, 'sanne', 'besluit', 'read')['groups']);
		self::assertSame(['true', 'administrator', ''], $this->cell($rows, 'pieter', 'besluit', 'delete'));
	}//end testAUserHoldsWhatTheirGroupsHoldAndAnAdministratorEverything()

	/**
	 * Per-object grants are rows of their own, at the object level.
	 *
	 * @return void
	 */
	public function testObjectGrantsAreRows(): void {
		$rows = $this->rowsOf(
			new PermissionMatrixRequest(
				registers: [],
				schemas: [],
				subjects: [],
				objectGrants: [['register' => 'woo', 'schema' => 'besluit', 'object' => 'uuid-1', 'principal' => 'sanne', 'principalType' => 'user', 'action' => 'read']]
			)
		);

		self::assertSame(
			[
				[
					'subjectType' => 'user',
					'subject' => 'sanne',
					'groups' => '',
					'register' => 'woo',
					'schema' => 'besluit',
					'object' => 'uuid-1',
					'action' => 'read',
					'granted' => 'true',
					'rule' => 'object-grant',
					'level' => 'object',
					'role' => '',
					'conditional' => 'false',
				],
			],
			$rows
		);
	}//end testObjectGrantsAreRows()

	/**
	 * The header carries the count and the hash of the rows, and altering a row breaks it.
	 *
	 * @return void
	 */
	public function testTheHashCoversTheRows(): void {
		$besluit = $this->schema(id: 1, slug: 'besluit', authorization: ['read' => ['redactie']]);
		$request = new PermissionMatrixRequest(
			registers: [$this->register(slug: 'woo', schemas: [1])],
			schemas: [$besluit],
			subjects: [PermissionMatrixSubject::group('redactie')]
		);
		$export = $this->exporter->export(request: $request, provenance: $this->provenance());

		$csv = implode('', iterator_to_array($export->chunks(format: PermissionMatrixExport::FORMAT_CSV), false));
		[$head, $body] = $this->split(csv: $csv);

		self::assertSame(3, $export->rowCount());
		self::assertStringContainsString("# rowCount: 3\n", $head);
		self::assertStringContainsString('# sha256: '.$export->sha256()."\n", $head);
		self::assertStringContainsString("# generatedBy: beheerder\n", $head);
		self::assertSame($export->sha256(), hash('sha256', $body));

		$altered = str_replace('redactie,,woo,besluit,,update,false', 'redactie,,woo,besluit,,update,true', $body);
		self::assertNotSame($body, $altered);
		self::assertNotSame($export->sha256(), hash('sha256', $altered));
	}//end testTheHashCoversTheRows()

	/**
	 * The JSON export carries the same header and the same rows.
	 *
	 * @return void
	 */
	public function testTheJsonExportCarriesTheSameRowsAndHash(): void {
		$request = new PermissionMatrixRequest(
			registers: [$this->register(slug: 'woo', schemas: [1])],
			schemas: [$this->schema(id: 1, slug: 'besluit', authorization: ['read' => ['=evil,"x"']])],
			subjects: [PermissionMatrixSubject::group('=evil,"x"')]
		);
		$export = $this->exporter->export(request: $request, provenance: $this->provenance());

		$json = json_decode(implode('', iterator_to_array($export->chunks(format: PermissionMatrixExport::FORMAT_JSON), false)), true, 512, JSON_THROW_ON_ERROR);

		self::assertSame($export->sha256(), $json['sha256']);
		self::assertSame(3, $json['rowCount']);
		self::assertFalse($json['objectGrantsListed']);
		self::assertSame('inst-1', $json['instanceId']);
		self::assertCount(3, $json['rows']);
		self::assertSame('=evil,"x"', $json['rows'][0]['subject']);

		// In CSV the name is quoted and defused, so a spreadsheet shows it rather than runs it.
		$csv = implode('', iterator_to_array($export->chunks(format: PermissionMatrixExport::FORMAT_CSV), false));
		self::assertStringContainsString("group,\"'=evil,\"\"x\"\"\",,woo,besluit,,read,true", $csv);
	}//end testTheJsonExportCarriesTheSameRowsAndHash()

	/**
	 * The rows of a request.
	 *
	 * @param PermissionMatrixRequest $request The request.
	 *
	 * @return array<int, array<string, string>> The rows.
	 */
	private function rowsOf(PermissionMatrixRequest $request): array {
		return iterator_to_array($this->exporter->rows(request: $request), false);
	}//end rowsOf()

	/**
	 * The one row for a subject, schema and verb.
	 *
	 * @param array<int, array<string, string>> $rows    The rows.
	 * @param string                            $subject The subject.
	 * @param string                            $schema  The schema slug.
	 * @param string                            $verb    The verb.
	 *
	 * @return array<string, string> The row.
	 */
	private function rowFor(array $rows, string $subject, string $schema, string $verb): array {
		$found = array_values(
			array_filter(
				$rows,
				static fn (array $row): bool => $row['subject'] === $subject && $row['schema'] === $schema && $row['action'] === $verb
			)
		);
		self::assertCount(1, $found, "one row for $subject / $schema / $verb");

		return $found[0];
	}//end rowFor()

	/**
	 * Granted, rule and level of one row.
	 *
	 * @param array<int, array<string, string>> $rows    The rows.
	 * @param string                            $subject The subject.
	 * @param string                            $schema  The schema slug.
	 * @param string                            $verb    The verb.
	 *
	 * @return array<int, string> The three cells.
	 */
	private function cell(array $rows, string $subject, string $schema, string $verb): array {
		$row = $this->rowFor($rows, $subject, $schema, $verb);

		return [$row['granted'], $row['rule'], $row['level']];
	}//end cell()

	/**
	 * The `#` header lines and the column line, then the row lines.
	 *
	 * @param string $csv The export.
	 *
	 * @return array{0: string, 1: string} Head and body.
	 */
	private function split(string $csv): array {
		$columns = implode(',', PermissionMatrixExporter::COLUMNS)."\n";
		$at = strpos($csv, $columns);
		self::assertNotFalse($at);

		return [substr($csv, 0, $at), substr($csv, $at + strlen($columns))];
	}//end split()

	/**
	 * A provenance for these tests.
	 *
	 * @return PermissionMatrixProvenance The provenance.
	 */
	private function provenance(): PermissionMatrixProvenance {
		return new PermissionMatrixProvenance(
			generatedAt: '2026-11-24T21:00:00+00:00',
			generatedBy: 'beheerder',
			instanceId: 'inst-1',
			level: 'group',
			denyEnforcement: 'enforcing'
		);
	}//end provenance()

	/**
	 * A schema.
	 *
	 * @param int        $id            The id.
	 * @param string     $slug          The slug.
	 * @param array|null $authorization The block.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $id, string $slug, ?array $authorization): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schema()

	/**
	 * A register.
	 *
	 * @param string     $slug          The slug.
	 * @param array      $schemas       The schema ids it holds.
	 * @param array|null $authorization The block.
	 * @param array|null $roles         The role definitions.
	 *
	 * @return Register The register.
	 */
	private function register(string $slug, array $schemas, ?array $authorization = null, ?array $roles = null): Register {
		$register = new Register();
		$register->setSlug($slug);
		$register->setSchemas($schemas);
		$register->setAuthorization($authorization);
		if ($roles !== null) {
			$register->setConfiguration(['roles' => $roles]);
		}

		return $register;
	}//end register()
}//end class
