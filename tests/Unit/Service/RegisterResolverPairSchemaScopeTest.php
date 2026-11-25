<?php

/**
 * lookupPair() resolves the schema slug inside the resolved register, under the same tenancy rule.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/register-scoped-schema-slug-resolution/specs/schema-slug-resolution/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConfigLookup;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\RegisterResolverService;
use OCA\OpenRegister\Service\RegisterSchemaMappers;
use OCA\OpenRegister\Service\Resolver\Exception\SchemaNotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Two registers carry a schema with one slug; the pair's register decides which one the app config resolves to.
 *
 * @covers \OCA\OpenRegister\Service\RegisterResolverService
 * @uses   \OCA\OpenRegister\Service\ConfigLookup
 * @uses   \OCA\OpenRegister\Service\RegisterSchemaMappers
 * @uses   \OCA\OpenRegister\Service\Resolver\RegisterSchemaPair
 * @uses   \OCA\OpenRegister\Service\Resolver\Exception\SchemaNotFoundException
 * @uses   \OCA\OpenRegister\Service\Resolver\Exception\ResolverConfigKey
 * @uses   \OCA\OpenRegister\Service\Schemas\SchemaRefInRegister
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class RegisterResolverPairSchemaScopeTest extends TestCase {

	private IAppConfig&MockObject $appConfig;

	private RegisterMapper&MockObject $registers;

	private SchemaMapper&MockObject $schemas;

	private RegisterResolverService $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->registers = $this->createMock(RegisterMapper::class);
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->resolver = new RegisterResolverService(
			appConfig: $this->appConfig,
			mappers: new RegisterSchemaMappers($this->registers, $this->schemas),
			organisationService: $this->createMock(OrganisationService::class),
			logger: new NullLogger()
		);
		$this->appConfig->method('getValueString')->willReturnMap(
			[
				['hermiq', 'agent_register', '', false, 'hermiq'],
				['hermiq', 'agent_schema', '', false, 'agent'],
			]
		);
		$register = new Register();
		$register->setId(5);
		$register->setSlug('hermiq');
		$register->setSchemas([36, 37]);
		$this->registers->method('find')->willReturn($register);
	}

	private static function schema(int $id, ?string $organisation = null): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('agent');
		if ($organisation !== null) {
			$schema->setOrganisation($organisation);
		}

		return $schema;
	}

	private function pairSchema(?string $organisation = null): Schema {
		return $this->resolver->lookupPair(
			new ConfigLookup('hermiq', 'agent_register', null, $organisation),
			new ConfigLookup('hermiq', 'agent_schema', null, $organisation)
		)->getSchema();
	}

	public function testTheSlugResolvesToTheRegistersOwnSchemaTheCallerCanSee(): void {
		$own = self::schema(36);
		$this->schemas->expects($this->once())->method('findBySlugInIds')->with('agent', [36, 37])->willReturn($own);
		// The tenancy check: the caller's tenant-filtered lookup must see it, by id.
		$this->schemas->expects($this->once())->method('find')->with(36)->willReturn($own);

		$this->assertSame(36, $this->pairSchema()->getId());
		// Cached per register: a second pair resolves without another read.
		$this->assertSame(36, $this->pairSchema()->getId());
	}

	public function testAScopedSchemaOutsideTheCallersTenantFallsBackToTheTenantFilteredSlugLookup(): void {
		$this->schemas->method('findBySlugInIds')->willReturn(self::schema(36));
		$this->schemas->expects($this->exactly(2))->method('find')->willReturnCallback(
			static function (string|int $id): Schema {
				if ($id === 36) {
					throw new DoesNotExistException('not in this tenant');
				}

				throw new DoesNotExistException('no such slug in this tenant');
			}
		);

		$this->expectException(SchemaNotFoundException::class);
		$this->pairSchema();
	}

	public function testWithAnExplicitOrganisationAForeignScopedSchemaIsNotTaken(): void {
		$this->schemas->method('findBySlugInIds')->willReturn(self::schema(36, 'org-other'));
		$this->schemas->expects($this->once())->method('find')->with('agent', [], true, false)->willReturn(self::schema(22, 'org-caller'));

		$this->assertSame(22, $this->pairSchema(organisation: 'org-caller')->getId());
	}

	public function testWithAnExplicitOrganisationTheOwnScopedSchemaIsTaken(): void {
		$this->schemas->method('findBySlugInIds')->willReturn(self::schema(36, 'org-caller'));
		$this->schemas->expects($this->never())->method('find');

		$this->assertSame(36, $this->pairSchema(organisation: 'org-caller')->getId());
	}

	public function testASlugTheRegisterLacksResolvesAsBefore(): void {
		$this->schemas->method('findBySlugInIds')->willReturn(null);
		$this->schemas->expects($this->once())->method('find')->with('agent')->willReturn(self::schema(22));

		$this->assertSame(22, $this->pairSchema()->getId());
	}
}
