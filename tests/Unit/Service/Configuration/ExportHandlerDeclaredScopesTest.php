<?php

/**
 * An exported configuration must carry the groups it depends on.
 *
 * OasService emitted `components.securitySchemes...scopes` for Swagger
 * consumers, but the configuration document produced by ExportHandler never
 * carried that key and ImportHandler never read it — the whole declaration was
 * implicit, recoverable only by walking every authorization block.
 *
 * Scope note, so these tests are not read as claiming more than they check: the
 * exported scope map is DERIVED from the same registers and schemas the document
 * already carries, so it does not hand the importer groups it could not have
 * derived itself. What it adds is an explicit, self-describing declaration at
 * the OAS-native location, at parity with the generated API spec. Authored-only
 * declarations — a group named before any authorization block references it —
 * live in app config and are restored by GroupReconciler, not by this path.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/declared-group-provisioning/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Authorization\RbacGroupCollector;
use OCA\OpenRegister\Service\Configuration\ExportHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * Round-trip tests for the declared-scope block on exported configurations.
 */
class ExportHandlerDeclaredScopesTest extends TestCase {

	/**
	 * System under test.
	 *
	 * @var ExportHandler
	 */
	private ExportHandler $handler;

	/**
	 * Mocked register mapper.
	 *
	 * @var RegisterMapper&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $registerMapper;

	/**
	 * Mocked schema mapper.
	 *
	 * @var SchemaMapper&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $schemaMapper;

	/**
	 * Build the handler with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);

		$this->handler = new ExportHandler(
			$this->schemaMapper,
			$this->registerMapper,
			$this->createMock(MagicMapper::class),
			$this->createMock(ConfigurationMapper::class),
			$this->createMock(MappingMapper::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Build a register carrying an authorization block.
	 *
	 * @param array $authorization The authorization block.
	 *
	 * @return Register The register entity.
	 */
	private function registerWithAuthorization(array $authorization): Register {
		$register = new Register();
		$id = new ReflectionProperty($register, 'id');
		$id->setAccessible(true);
		$id->setValue($register, 1);

		$register->setTitle('Zaken');
		$register->setSlug('zaken');
		$register->setVersion('1.0.0');
		$register->setAuthorization($authorization);

		return $register;
	}//end registerWithAuthorization()

	/**
	 * Build a schema carrying schema- and property-level authorization.
	 *
	 * @return Schema The schema entity.
	 */
	private function schemaWithAuthorization(): Schema {
		$schema = new Schema();
		$id = new ReflectionProperty($schema, 'id');
		$id->setAccessible(true);
		$id->setValue($schema, 10);

		$schema->setTitle('Zaak');
		$schema->setSlug('zaak');
		$schema->setVersion('1.0.0');
		$schema->setAuthorization(['delete' => ['archivarissen']]);
		$schema->setProperties(
			[
				'bsn' => [
					'type' => 'string',
					'authorization' => ['read' => ['privacy-officers']],
				],
			]
		);

		return $schema;
	}//end schemaWithAuthorization()

	/**
	 * Export the fixture configuration.
	 *
	 * @return array The exported document.
	 */
	private function exportFixture(): array {
		$register = $this->registerWithAuthorization(['read' => ['medewerkers']]);
		$schema = $this->schemaWithAuthorization();

		$this->registerMapper->method('getSchemasByRegisterId')->with(1)->willReturn([$schema]);
		$this->schemaMapper->method('getIdToSlugMap')->willReturn([10 => 'zaak']);
		$this->registerMapper->method('getIdToSlugMap')->willReturn([1 => 'zaken']);

		return $this->handler->exportConfig($register);
	}//end exportFixture()

	/**
	 * Every group the configuration depends on appears in the scope map —
	 * including one that only gates a single property.
	 *
	 * @return void
	 */
	public function testExportDeclaresEveryGroupItDependsOn(): void {
		$scopes = $this->exportFixture()['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['scopes'];

		$this->assertArrayHasKey('medewerkers', $scopes, 'register-level group');
		$this->assertArrayHasKey('archivarissen', $scopes, 'schema-level group');
		$this->assertArrayHasKey('privacy-officers', $scopes, 'property-level group');
		$this->assertArrayHasKey('admin', $scopes, 'admin is always a valid scope');
		$this->assertArrayNotHasKey(
			'public',
			$scopes,
			'public is emitted only when the configuration actually grants anonymous access'
		);
	}//end testExportDeclaresEveryGroupItDependsOn()

	/**
	 * The scope map is complete ON ITS OWN.
	 *
	 * Deliberately read through `fromScopeMap()` rather than `fromDocument()`.
	 * `fromDocument()` also RE-DERIVES groups from the exported register and
	 * schema definitions, so it recovers the full set whether or not the scope
	 * map was written at all — an assertion through it stays green against the
	 * lossy export it is supposed to be pinning, and proves nothing.
	 *
	 * What the scope map adds is an EXPLICIT declaration: a consumer that reads
	 * only the security scheme — Swagger UI, an external client negotiating
	 * scopes — sees every group without having to walk and understand every
	 * authorization block first.
	 *
	 * @return void
	 */
	public function testScopeMapAloneCarriesEveryGroup(): void {
		$fromScopeMapOnly = (new RbacGroupCollector())->fromScopeMap(document: $this->exportFixture());

		$this->assertContains('medewerkers', $fromScopeMapOnly);
		$this->assertContains('archivarissen', $fromScopeMapOnly);
		$this->assertContains('privacy-officers', $fromScopeMapOnly, 'property-level group, declared explicitly');
	}//end testScopeMapAloneCarriesEveryGroup()

}//end class
