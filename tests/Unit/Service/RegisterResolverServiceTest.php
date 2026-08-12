<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\RegisterResolverService}.
 *
 * Covers the five public resolve methods plus enumerateAppConfigs(),
 * exercising happy paths, missing-config defaults, tenant mismatches,
 * request-scoped caching, and cache invalidation on tenant switch.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/register-resolver-service/specs/register-resolver-service/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\RegisterResolverService;
use OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException;
use OCA\OpenRegister\Service\Resolver\Exception\PropertyNotFoundException;
use OCA\OpenRegister\Service\Resolver\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Service\Resolver\Exception\SchemaNotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * RegisterResolverServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class RegisterResolverServiceTest extends TestCase {

	private IAppConfig&MockObject $appConfig;

	private RegisterMapper&MockObject $registerMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private OrganisationService&MockObject $organisationService;

	private LoggerInterface&MockObject $logger;

	private RegisterResolverService $resolver;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->resolver = new RegisterResolverService(
			appConfig: $this->appConfig,
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper,
			organisationService: $this->organisationService,
			logger: $this->logger,
		);

	}//end setUp()

	public function testResolveRegisterIdHappyPath(): void {
		$this->appConfig->method('getValueString')
			->with('opencatalogi', 'theme_register', '')
			->willReturn('theme-2026');

		$this->assertSame('theme-2026', $this->resolver->resolveRegisterId('opencatalogi', 'theme_register'));

	}//end testResolveRegisterIdHappyPath()

	public function testResolveRegisterIdFallsBackToDefault(): void {
		$this->appConfig->method('getValueString')->willReturn('');

		$result = $this->resolver->resolveRegisterId('opencatalogi', 'theme_register', 'theme-default');

		$this->assertSame('theme-default', $result);

	}//end testResolveRegisterIdFallsBackToDefault()

	public function testResolveRegisterIdThrowsMissingConfigExceptionWithDiagnostics(): void {
		$this->appConfig->method('getValueString')->willReturn('');

		try {
			$this->resolver->resolveRegisterId('opencatalogi', 'theme_register');
			$this->fail('Expected MissingConfigException');
		} catch (MissingConfigException $e) {
			$this->assertSame('opencatalogi', $e->getAppId());
			$this->assertSame('theme_register', $e->getConfigKey());
		}

	}//end testResolveRegisterIdThrowsMissingConfigExceptionWithDiagnostics()

	public function testResolveSchemaIdHappyPath(): void {
		$this->appConfig->method('getValueString')
			->with('opencatalogi', 'listing_schema', '')
			->willReturn('listing-v2');

		$this->assertSame('listing-v2', $this->resolver->resolveSchemaId('opencatalogi', 'listing_schema'));

	}//end testResolveSchemaIdHappyPath()

	public function testResolveRegisterReturnsEntity(): void {
		$register = $this->makeRegister(7, 'theme-2026');

		$this->appConfig->method('getValueString')->willReturn('theme-2026');
		$this->registerMapper->expects($this->once())
			->method('find')
			->with('theme-2026')
			->willReturn($register);

		$resolved = $this->resolver->resolveRegister('opencatalogi', 'theme_register');

		$this->assertSame($register, $resolved);

	}//end testResolveRegisterReturnsEntity()

	public function testResolveRegisterThrowsWhenSlugNotInTenant(): void {
		$this->appConfig->method('getValueString')->willReturn('theme-2026');
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('nope'));

		try {
			$this->resolver->resolveRegister('opencatalogi', 'theme_register');
			$this->fail('Expected RegisterNotFoundException');
		} catch (RegisterNotFoundException $e) {
			$this->assertSame('theme-2026', $e->getResolvedValue());
			$this->assertSame('opencatalogi', $e->getAppId());
			$this->assertSame('theme_register', $e->getConfigKey());
		}

	}//end testResolveRegisterThrowsWhenSlugNotInTenant()

	public function testResolveRegisterCachesResultWithinRequest(): void {
		$register = $this->makeRegister(7, 'theme-2026');

		$this->appConfig->method('getValueString')->willReturn('theme-2026');
		$this->registerMapper->expects($this->once())
			->method('find')
			->willReturn($register);

		$first = $this->resolver->resolveRegister('opencatalogi', 'theme_register');
		$second = $this->resolver->resolveRegister('opencatalogi', 'theme_register');

		$this->assertSame($first, $second, 'Cached resolve must return the same instance.');

	}//end testResolveRegisterCachesResultWithinRequest()

	public function testResolveSchemaReturnsEntity(): void {
		$schema = $this->makeSchema(20, 'listing-v2');

		$this->appConfig->method('getValueString')->willReturn('listing-v2');
		$this->schemaMapper->expects($this->once())
			->method('find')
			->with('listing-v2')
			->willReturn($schema);

		$this->assertSame($schema, $this->resolver->resolveSchema('opencatalogi', 'listing_schema'));

	}//end testResolveSchemaReturnsEntity()

	public function testResolveSchemaThrowsWhenSlugNotInTenant(): void {
		$this->appConfig->method('getValueString')->willReturn('listing-v2');
		$this->schemaMapper->method('find')->willThrowException(new DoesNotExistException('nope'));

		$this->expectException(SchemaNotFoundException::class);
		$this->resolver->resolveSchema('opencatalogi', 'listing_schema');

	}//end testResolveSchemaThrowsWhenSlugNotInTenant()

	public function testResolvePairBundlesBothEntities(): void {
		$register = $this->makeRegister(7, 'cms');
		$schema = $this->makeSchema(20, 'listing-v2');

		$this->appConfig->method('getValueString')->willReturnMap(
			[
				['opencatalogi', 'listing_register', '', false, 'cms'],
				['opencatalogi', 'listing_schema', '', false, 'listing-v2'],
			]
		);
		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('find')->willReturn($schema);

		$pair = $this->resolver->resolvePair('opencatalogi', 'listing_register', 'listing_schema');

		$this->assertSame('cms', $pair->getRegisterId());
		$this->assertSame('listing-v2', $pair->getSchemaId());
		$this->assertSame($register, $pair->getRegister());
		$this->assertSame($schema, $pair->getSchema());

	}//end testResolvePairBundlesBothEntities()

	public function testOrganisationOverrideRejectsForeignTenant(): void {
		$register = $this->makeRegister(7, 'theme-2026', 'org-other');

		$this->appConfig->method('getValueString')->willReturn('theme-2026');
		$this->registerMapper->method('find')
			->with('theme-2026', true, false)
			->willReturn($register);

		$this->expectException(RegisterNotFoundException::class);
		$this->resolver->resolveRegister('opencatalogi', 'theme_register', null, 'org-caller');

	}//end testOrganisationOverrideRejectsForeignTenant()

	public function testCacheClearsOnTenantSwitch(): void {
		$register = $this->makeRegister(7, 'theme-2026');

		$this->appConfig->method('getValueString')->willReturn('theme-2026');
		$this->registerMapper->expects($this->exactly(2))
			->method('find')
			->willReturn($register);

		// getUuid()/setUuid() are magic methods on the Entity (resolved via
		// __call), so they cannot be configured on a PHPUnit mock. Use real
		// Organisation instances and seed the uuid via setUuid().
		$orgA = new Organisation();
		$orgA->setUuid('org-a');
		$orgB = new Organisation();
		$orgB->setUuid('org-b');

		$this->organisationService->method('getActiveOrganisation')
			->willReturnOnConsecutiveCalls($orgA, $orgB);

		// First call → caches under org-a.
		$this->resolver->resolveRegister('opencatalogi', 'theme_register');
		// Second call detects tenant switch and re-fetches.
		$this->resolver->resolveRegister('opencatalogi', 'theme_register');

	}//end testCacheClearsOnTenantSwitch()

	public function testEnumerateAppConfigsReturnsResolverShapedKeysOnly(): void {
		$this->appConfig->method('getAllValues')
			->with('opencatalogi')
			->willReturn(
				[
					'theme_register' => 'theme-2026',
					'theme_schema' => 'theme-v1',
					'listing_register' => 'cms',
					'auto_listing_threshold' => '500',
					'register' => 'legacy-default',
					'schema' => 'legacy-default-schema',
					'foo_other' => 'bar',
				]
			);

		$result = $this->resolver->enumerateAppConfigs('opencatalogi');

		$this->assertArrayHasKey('theme_register', $result);
		$this->assertArrayHasKey('theme_schema', $result);
		$this->assertArrayHasKey('listing_register', $result);
		$this->assertArrayHasKey('default_register', $result);
		$this->assertArrayHasKey('default_schema', $result);
		$this->assertSame('theme-2026', $result['theme_register']);
		$this->assertSame('legacy-default', $result['default_register']);
		$this->assertArrayNotHasKey('auto_listing_threshold', $result);
		$this->assertArrayNotHasKey('foo_other', $result);

	}//end testEnumerateAppConfigsReturnsResolverShapedKeysOnly()

	public function testEnumerateAppConfigsSkipsEmptyValues(): void {
		$this->appConfig->method('getAllValues')->willReturn(
			[
				'theme_register' => '',
				'theme_schema' => 'theme-v1',
			]
		);

		$result = $this->resolver->enumerateAppConfigs('opencatalogi');

		$this->assertArrayNotHasKey('theme_register', $result);
		$this->assertArrayHasKey('theme_schema', $result);

	}//end testEnumerateAppConfigsSkipsEmptyValues()

	public function testResolvePropertyIdHappyPath(): void {
		$this->appConfig->method('getValueString')
			->with('openconnector', 'swc_type_property', '')
			->willReturn('id-9358c742-a631-47b5-80d4-f8e69b3a5d12');

		$this->assertSame(
			'id-9358c742-a631-47b5-80d4-f8e69b3a5d12',
			$this->resolver->resolvePropertyId('openconnector', 'swc_type_property')
		);

	}//end testResolvePropertyIdHappyPath()

	public function testResolvePropertyIdFallsBackToDefault(): void {
		$this->appConfig->method('getValueString')->willReturn('');

		$result = $this->resolver->resolvePropertyId('openconnector', 'swc_type_property', 'id-default');
		$this->assertSame('id-default', $result);

	}//end testResolvePropertyIdFallsBackToDefault()

	public function testResolvePropertyIdThrowsMissingConfigExceptionWithDiagnostics(): void {
		$this->appConfig->method('getValueString')->willReturn('');

		try {
			$this->resolver->resolvePropertyId('openconnector', 'swc_type_property');
			$this->fail('Expected MissingConfigException');
		} catch (MissingConfigException $exception) {
			$this->assertSame('openconnector', $exception->getAppId());
			$this->assertSame('swc_type_property', $exception->getConfigKey());
		}

	}//end testResolvePropertyIdThrowsMissingConfigExceptionWithDiagnostics()

	public function testResolvePropertyMatchesByArrayKey(): void {
		$schema = $this->makeSchema(id: 5, slug: 'extendview');
		$schema->setProperties([
			'type' => ['type' => 'string', 'id' => 'prop-7', 'slug' => 'type'],
			'name' => ['type' => 'string', 'id' => 'prop-8', 'slug' => 'name'],
		]);

		$this->appConfig->method('getValueString')->willReturnMap([
			['openconnector', 'swc_schema', '', 'extendview'],
			['openconnector', 'swc_type_property', '', 'type'],
		]);
		$this->schemaMapper->method('find')->with(id: 'extendview')->willReturn($schema);

		[$key, $definition] = $this->resolver->resolveProperty(
			'openconnector',
			'swc_schema',
			'swc_type_property'
		);

		$this->assertSame('type', $key);
		$this->assertSame('prop-7', $definition['id']);

	}//end testResolvePropertyMatchesByArrayKey()

	public function testResolvePropertyMatchesById(): void {
		$schema = $this->makeSchema(id: 5, slug: 'extendview');
		$schema->setProperties([
			'type' => ['type' => 'string', 'id' => 'prop-7', 'slug' => 'type'],
			'name' => ['type' => 'string', 'id' => 'prop-8', 'slug' => 'name'],
		]);

		$this->appConfig->method('getValueString')->willReturnMap([
			['openconnector', 'swc_schema', '', 'extendview'],
			['openconnector', 'swc_type_property', '', 'prop-7'],
		]);
		$this->schemaMapper->method('find')->with(id: 'extendview')->willReturn($schema);

		[$key, $definition] = $this->resolver->resolveProperty(
			'openconnector',
			'swc_schema',
			'swc_type_property'
		);

		$this->assertSame('type', $key);
		$this->assertSame('prop-7', $definition['id']);

	}//end testResolvePropertyMatchesById()

	public function testResolvePropertyThrowsWhenPropertyMissing(): void {
		$schema = $this->makeSchema(id: 5, slug: 'extendview');
		$schema->setProperties([
			'type' => ['type' => 'string', 'id' => 'prop-7'],
		]);

		$this->appConfig->method('getValueString')->willReturnMap([
			['openconnector', 'swc_schema', '', 'extendview'],
			['openconnector', 'swc_type_property', '', 'does-not-exist'],
		]);
		$this->schemaMapper->method('find')->with(id: 'extendview')->willReturn($schema);

		try {
			$this->resolver->resolveProperty(
				'openconnector',
				'swc_schema',
				'swc_type_property'
			);
			$this->fail('Expected PropertyNotFoundException');
		} catch (PropertyNotFoundException $exception) {
			$this->assertSame('openconnector', $exception->getAppId());
			$this->assertSame('swc_type_property', $exception->getConfigKey());
			$this->assertSame('does-not-exist', $exception->getResolvedValue());
			$this->assertSame('extendview', $exception->getSchemaIdentifier());
		}

	}//end testResolvePropertyThrowsWhenPropertyMissing()

	public function testEnumerateAppConfigsIncludesPropertyKeys(): void {
		$this->appConfig->method('getAllValues')->willReturn([
			'theme_register' => 'theme-2026',
			'swc_type_property' => 'id-9358c742-a631-47b5-80d4-f8e69b3a5d12',
			'foo_property' => 'prop-foo',
			'unrelated_threshold' => '500',
		]);

		$result = $this->resolver->enumerateAppConfigs('openconnector');

		$this->assertArrayHasKey('swc_type_property', $result);
		$this->assertArrayHasKey('foo_property', $result);
		$this->assertSame('id-9358c742-a631-47b5-80d4-f8e69b3a5d12', $result['swc_type_property']);
		$this->assertArrayNotHasKey('unrelated_threshold', $result);

	}//end testEnumerateAppConfigsIncludesPropertyKeys()

	private function makeRegister(int $id, string $slug, ?string $organisation = null): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug($slug);
		if ($organisation !== null) {
			$register->setOrganisation($organisation);
		}
		return $register;
	}//end makeRegister()

	private function makeSchema(int $id, string $slug, ?string $organisation = null): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		if ($organisation !== null) {
			$schema->setOrganisation($organisation);
		}
		return $schema;
	}//end makeSchema()

}//end class
