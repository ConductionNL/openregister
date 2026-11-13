<?php

/**
 * Read-through test for a virtual register: introspect the permits SQLite
 * fixture, read its objects live through the dbal-source provider, and follow
 * a foreign-key relation to the object it names.
 *
 * Covers the dbal-virtual-registers task 7.2 path end to end without a
 * Nextcloud instance: the blueprint DatabaseIntrospectionService builds is the
 * one the provider is bound with, so a relation the introspection declares
 * (`permits.applicant_id` with `$ref: applicants`) is resolvable with a plain
 * find() on the target schema, which is what `_extend` does per related id.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Dbal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Dbal;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionService;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\Dbal\IntrospectionTools;
use OCA\OpenRegister\Service\Dbal\SqlTypeMapper;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use OCA\OpenRegister\Service\Schema\SchemaDiffService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Introspect, read and follow a relation over the permits fixture.
 *
 * @covers \OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionService
 * @covers \OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider
 */
class VirtualRegisterReadThroughTest extends TestCase {

	/**
	 * Path to the generated permits database.
	 *
	 * @var string
	 */
	private static string $fixturePath;

	/**
	 * Build the permits fixture once for the class.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		include_once __DIR__ . '/../../../fixtures/dbal/build-permits-sqlite.php';
		self::$fixturePath = sys_get_temp_dir() . '/or-dbal-read-through-permits.sqlite';
		build_permits_sqlite(path: self::$fixturePath);
	}//end setUpBeforeClass()

	/**
	 * Remove the fixture after the class.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		if (file_exists(self::$fixturePath) === true) {
			unlink(self::$fixturePath);
		}
	}//end tearDownAfterClass()

	/**
	 * The `type: database` source over the fixture.
	 *
	 * @return Source The source.
	 */
	private function source(): Source {
		$source = new Source();
		$source->setId(7);
		$source->setUuid('00000000-0000-0000-0000-000000000000');
		$source->setTitle('Permits demo');
		$source->setType('database');
		$source->setAuthConfig(['driver' => 'pdo_sqlite', 'path' => self::$fixturePath]);

		return $source;
	}//end source()

	/**
	 * A connection factory whose credential store holds nothing (sqlite needs no password).
	 *
	 * @return DbalConnectionFactory The factory.
	 */
	private function connectionFactory(): DbalConnectionFactory {
		$store = new class implements CredentialStore {
			/**
			 * {@inheritDoc}
			 *
			 * @param string $uuid   The credential UUID.
			 * @param string $secret The secret.
			 * @param string $scope  The scope.
			 *
			 * @return void
			 */
			public function put(string $uuid, string $secret, string $scope = 'personal'): void {
			}//end put()

			/**
			 * {@inheritDoc}
			 *
			 * @param string $uuid  The credential UUID.
			 * @param string $scope The scope.
			 *
			 * @return string|null Always null.
			 */
			public function get(string $uuid, string $scope = 'personal'): ?string {
				return null;
			}//end get()

			/**
			 * {@inheritDoc}
			 *
			 * @param string $uuid  The credential UUID.
			 * @param string $scope The scope.
			 *
			 * @return void
			 */
			public function delete(string $uuid, string $scope = 'personal'): void {
			}//end delete()
		};

		return new DbalConnectionFactory(credentialStore: $store, logger: new NullLogger());
	}//end connectionFactory()

	/**
	 * The introspected schemas, keyed by table, as the blueprint describes them.
	 *
	 * @return array<string, array<string, mixed>> The schema blueprints.
	 */
	private function blueprintSchemas(): array {
		$service = new DatabaseIntrospectionService(
			tools: new IntrospectionTools(
				connectionFactory: $this->connectionFactory(),
				typeMapper: new SqlTypeMapper(logger: new NullLogger()),
				diffService: new SchemaDiffService()
			),
			registerMapper: (new ReflectionClass(RegisterMapper::class))->newInstanceWithoutConstructor(),
			schemaMapper: (new ReflectionClass(SchemaMapper::class))->newInstanceWithoutConstructor(),
			logger: new NullLogger()
		);

		$byTable = [];
		foreach ($service->buildBlueprint(source: $this->source())['schemas'] as $schema) {
			$byTable[$schema['configuration']['x-openregister-object-source']['config']['table']] = $schema;
		}

		return $byTable;
	}//end blueprintSchemas()

	/**
	 * The provider, its source lookup answering with the fixture source.
	 *
	 * @return DbalObjectSourceProvider The provider.
	 */
	private function provider(): DbalObjectSourceProvider {
		$sourceMapper = $this->createMock(SourceMapper::class);
		$sourceMapper->method('find')->willReturn($this->source());
		$sourceMapper->method('findAll')->willReturn([$this->source()]);
		$sourceMapper->method('findForSystem')->willReturn($this->source());

		return new DbalObjectSourceProvider(
			sourceMapper: $sourceMapper,
			connectionFactory: $this->connectionFactory(),
			logger: new NullLogger()
		);
	}//end provider()

	/**
	 * Bind a blueprint schema the way the object-source path binds a stored one.
	 *
	 * @param array<string, mixed> $blueprint The introspected schema.
	 * @param int                  $id        The schema id to give it.
	 *
	 * @return SourcedTable The binding the provider reads through.
	 */
	private function bound(array $blueprint, int $id): SourcedTable {
		$register = new Register();
		$register->setId(1);

		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug((string)$blueprint['slug']);
		$schema->setProperties($blueprint['properties']);
		$schema->setConfiguration($blueprint['configuration']);

		return new SourcedTable(
			new RegisterSchema($register, $schema),
			$blueprint['configuration']['x-openregister-object-source']['config']
		);
	}//end bound()

	/**
	 * The introspected schemas read the fixture's rows live, and the declared
	 * foreign key leads from each permit to exactly the applicant it names.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
	 */
	public function testIntrospectedSchemasReadRowsAndResolveTheirRelation(): void {
		$schemas = $this->blueprintSchemas();
		$provider = $this->provider();
		$permits = $this->bound(blueprint: $schemas['permits'], id: 21);
		$applicants = $this->bound(blueprint: $schemas['applicants'], id: 22);

		// The relation the read follows is the one introspection declared.
		$this->assertSame('applicants', $schemas['permits']['properties']['applicant_id']['$ref']);

		$rows = $provider->findAll(source: $permits, query: ['sort' => ['reference' => 'asc']]);
		$this->assertCount(2, $rows);
		$this->assertSame(2, $provider->count(source: $permits));

		$expected = ['P-2026-0001' => 'Alice Example', 'P-2026-0002' => 'Bob Sample'];
		foreach ($rows as $permit) {
			$data = $permit->getObject();
			$applicant = $provider->find(source: $applicants, id: (string)$data['applicant_id']);

			$this->assertNotNull($applicant, 'the related applicant resolves by the id the permit carries');
			$this->assertSame((string)$data['applicant_id'], $applicant->getUuid());
			$this->assertSame($expected[$data['reference']], $applicant->getObject()['full_name']);
		}
	}//end testIntrospectedSchemasReadRowsAndResolveTheirRelation()

	/**
	 * A related id that names no row resolves to nothing rather than to another row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
	 */
	public function testARelatedIdWithoutARowResolvesToNull(): void {
		$schemas = $this->blueprintSchemas();

		$this->assertNull($this->provider()->find(source: $this->bound(blueprint: $schemas['applicants'], id: 22), id: '999'));
	}//end testARelatedIdWithoutARowResolvesToNull()
}//end class
