<?php

/**
 * RegisterResolverService Unit Test
 *
 * Unit tests for the RegisterResolverService class covering all public methods,
 * caching behaviour, tenant-awareness, and error paths.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/register-resolver-service/tasks.md#task-4.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\RegisterResolverService;
use OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException;
use OCA\OpenRegister\Service\Resolver\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Service\Resolver\Exception\SchemaNotFoundException;
use OCA\OpenRegister\Service\Resolver\RegisterSchemaPair;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RegisterResolverService.
 *
 * Covers:
 * - resolveRegisterId / resolveSchemaId (happy path + missing config + default).
 * - resolveRegister / resolveSchema (happy path, not found, request-scoped cache).
 * - resolvePair convenience method.
 * - enumerateAppConfigs key filtering.
 * - Organisation override parameter.
 * - clearCache.
 *
 * @package OCA\OpenRegister\Tests\Unit\Service
 *
 * @spec openspec/changes/register-resolver-service/tasks.md#task-4.2
 */
class RegisterResolverServiceTest extends TestCase
{

    /**
     * Mock IAppConfig.
     *
     * @var IAppConfig|MockObject
     */
    private MockObject $appConfig;

    /**
     * Mock RegisterMapper.
     *
     * @var RegisterMapper|MockObject
     */
    private MockObject $registerMapper;

    /**
     * Mock SchemaMapper.
     *
     * @var SchemaMapper|MockObject
     */
    private MockObject $schemaMapper;

    /**
     * Service under test.
     *
     * @var RegisterResolverService
     */
    private RegisterResolverService $service;

    /**
     * Set up test doubles and SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig      = $this->createMock(IAppConfig::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);

        $this->service = new RegisterResolverService(
            appConfig: $this->appConfig,
            registerMapper: $this->registerMapper,
            schemaMapper: $this->schemaMapper,
        );
    }//end setUp()

    /**
     * Helper to create a Register mock.
     *
     * Register uses magic __call for getters so we do not configure those
     * methods — just return a plain mock instance the service can hold.
     *
     * @return Register
     */
    private function makeRegister(): Register
    {
        return $this->createMock(Register::class);
    }//end makeRegister()

    /**
     * Helper to create a Schema mock.
     *
     * @return Schema
     */
    private function makeSchema(): Schema
    {
        return $this->createMock(Schema::class);
    }//end makeSchema()

    // -------------------------------------------------------------------------
    // resolveRegisterId
    // -------------------------------------------------------------------------

    /**
     * Happy path: resolveRegisterId returns the configured slug.
     *
     * @return void
     */
    public function testResolveRegisterIdReturnsConfiguredSlug(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->with('myapp', 'theme_register', '__OR_RESOLVER_MISSING__')
            ->willReturn('my-register-slug');

        $result = $this->service->resolveRegisterId(appId: 'myapp', configKey: 'theme_register');

        self::assertSame('my-register-slug', $result);
    }//end testResolveRegisterIdReturnsConfiguredSlug()

    /**
     * Missing config with no default throws MissingConfigException.
     *
     * @return void
     */
    public function testResolveRegisterIdThrowsMissingConfigWhenUnset(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturn('__OR_RESOLVER_MISSING__');

        $this->expectException(MissingConfigException::class);

        $this->service->resolveRegisterId(appId: 'myapp', configKey: 'theme_register');
    }//end testResolveRegisterIdThrowsMissingConfigWhenUnset()

    /**
     * Missing config with a default returns the default instead of throwing.
     *
     * @return void
     */
    public function testResolveRegisterIdUsesDefaultWhenUnset(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturn('__OR_RESOLVER_MISSING__');

        $result = $this->service->resolveRegisterId(
            appId: 'myapp',
            configKey: 'theme_register',
            default: 'fallback-slug'
        );

        self::assertSame('fallback-slug', $result);
    }//end testResolveRegisterIdUsesDefaultWhenUnset()

    // -------------------------------------------------------------------------
    // resolveSchemaId
    // -------------------------------------------------------------------------

    /**
     * Happy path: resolveSchemaId returns the configured slug.
     *
     * @return void
     */
    public function testResolveSchemaIdReturnsConfiguredSlug(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->with('myapp', 'theme_schema', '__OR_RESOLVER_MISSING__')
            ->willReturn('my-schema-slug');

        $result = $this->service->resolveSchemaId(appId: 'myapp', configKey: 'theme_schema');

        self::assertSame('my-schema-slug', $result);
    }//end testResolveSchemaIdReturnsConfiguredSlug()

    /**
     * Missing config + no default → MissingConfigException with correct metadata.
     *
     * @return void
     */
    public function testResolveSchemaIdMissingConfigExceptionCarriesMetadata(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturn('__OR_RESOLVER_MISSING__');

        try {
            $this->service->resolveSchemaId(appId: 'myapp', configKey: 'theme_schema');
            self::fail('Expected MissingConfigException.');
        } catch (MissingConfigException $e) {
            self::assertSame('myapp', $e->getAppId());
            self::assertSame('theme_schema', $e->getConfigKey());
        }
    }//end testResolveSchemaIdMissingConfigExceptionCarriesMetadata()

    // -------------------------------------------------------------------------
    // resolveRegister
    // -------------------------------------------------------------------------

    /**
     * Happy path: resolveRegister returns hydrated Register entity.
     *
     * @return void
     */
    public function testResolveRegisterReturnsHydratedEntity(): void
    {
        $register = $this->makeRegister();

        $this->appConfig
            ->method('getValueString')
            ->willReturn('my-register-slug');

        $this->registerMapper
            ->expects(self::once())
            ->method('find')
            ->willReturn($register);

        $result = $this->service->resolveRegister(appId: 'myapp', configKey: 'theme_register');

        self::assertSame($register, $result);
    }//end testResolveRegisterReturnsHydratedEntity()

    /**
     * RegisterNotFoundException thrown when mapper throws DoesNotExistException.
     *
     * @return void
     */
    public function testResolveRegisterThrowsNotFoundWhenMapperThrows(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturn('missing-slug');

        $this->registerMapper
            ->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $this->expectException(RegisterNotFoundException::class);

        $this->service->resolveRegister(appId: 'myapp', configKey: 'theme_register');
    }//end testResolveRegisterThrowsNotFoundWhenMapperThrows()

    /**
     * RegisterNotFoundException carries appId, configKey, and resolvedValue.
     *
     * @return void
     */
    public function testResolveRegisterNotFoundExceptionCarriesMetadata(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturn('stale-slug');

        $this->registerMapper
            ->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        try {
            $this->service->resolveRegister(appId: 'myapp', configKey: 'theme_register');
            self::fail('Expected RegisterNotFoundException.');
        } catch (RegisterNotFoundException $e) {
            self::assertSame('myapp', $e->getAppId());
            self::assertSame('theme_register', $e->getConfigKey());
            self::assertSame('stale-slug', $e->getResolvedValue());
        }
    }//end testResolveRegisterNotFoundExceptionCarriesMetadata()

    /**
     * Mapper is called only once for two resolve calls with the same key (cache hit).
     *
     * @return void
     */
    public function testResolveRegisterCachesResultAcrossCallsWithSameKey(): void
    {
        $register = $this->makeRegister();

        $this->appConfig
            ->method('getValueString')
            ->willReturn('my-register-slug');

        $this->registerMapper
            ->expects(self::once())
            ->method('find')
            ->willReturn($register);

        $first  = $this->service->resolveRegister(appId: 'myapp', configKey: 'theme_register');
        $second = $this->service->resolveRegister(appId: 'myapp', configKey: 'theme_register');

        self::assertSame($first, $second);
    }//end testResolveRegisterCachesResultAcrossCallsWithSameKey()

    /**
     * Cache is keyed per (appId, configKey) — different keys hit mapper twice.
     *
     * @return void
     */
    public function testResolveRegisterDifferentKeysMakeSeparateLookups(): void
    {
        $reg1 = $this->makeRegister();
        $reg2 = $this->makeRegister();

        $this->appConfig
            ->method('getValueString')
            ->willReturnOnConsecutiveCalls('slug1', 'slug2');

        $this->registerMapper
            ->expects(self::exactly(2))
            ->method('find')
            ->willReturnOnConsecutiveCalls($reg1, $reg2);

        $r1 = $this->service->resolveRegister(appId: 'myapp', configKey: 'key_one');
        $r2 = $this->service->resolveRegister(appId: 'myapp', configKey: 'key_two');

        self::assertSame($reg1, $r1);
        self::assertSame($reg2, $r2);
    }//end testResolveRegisterDifferentKeysMakeSeparateLookups()

    /**
     * Organisation override: mapper is still called exactly once, and the result is returned.
     *
     * @return void
     */
    public function testResolveRegisterWithOrganisationUuidCallsMapperOnce(): void
    {
        $register = $this->makeRegister();

        $this->appConfig
            ->method('getValueString')
            ->willReturn('my-register-slug');

        $this->registerMapper
            ->expects(self::once())
            ->method('find')
            ->willReturn($register);

        $result = $this->service->resolveRegister(
            appId: 'myapp',
            configKey: 'theme_register',
            organisationUuid: 'org-uuid-1234'
        );

        self::assertSame($register, $result);
    }//end testResolveRegisterWithOrganisationUuidCallsMapperOnce()

    // -------------------------------------------------------------------------
    // resolveSchema
    // -------------------------------------------------------------------------

    /**
     * Happy path: resolveSchema returns hydrated Schema entity.
     *
     * @return void
     */
    public function testResolveSchemaReturnsHydratedEntity(): void
    {
        $schema = $this->makeSchema();

        $this->appConfig
            ->method('getValueString')
            ->willReturn('my-schema-slug');

        $this->schemaMapper
            ->expects(self::once())
            ->method('find')
            ->willReturn($schema);

        $result = $this->service->resolveSchema(appId: 'myapp', configKey: 'theme_schema');

        self::assertSame($schema, $result);
    }//end testResolveSchemaReturnsHydratedEntity()

    /**
     * SchemaNotFoundException thrown when mapper throws DoesNotExistException.
     *
     * @return void
     */
    public function testResolveSchemaThrowsNotFoundWhenMapperThrows(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturn('missing-schema');

        $this->schemaMapper
            ->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $this->expectException(SchemaNotFoundException::class);

        $this->service->resolveSchema(appId: 'myapp', configKey: 'theme_schema');
    }//end testResolveSchemaThrowsNotFoundWhenMapperThrows()

    /**
     * Schema mapper called once per (appId, configKey) pair across two calls (cache).
     *
     * @return void
     */
    public function testResolveSchemaIsCached(): void
    {
        $schema = $this->makeSchema();

        $this->appConfig
            ->method('getValueString')
            ->willReturn('my-schema-slug');

        $this->schemaMapper
            ->expects(self::once())
            ->method('find')
            ->willReturn($schema);

        $first  = $this->service->resolveSchema(appId: 'myapp', configKey: 'theme_schema');
        $second = $this->service->resolveSchema(appId: 'myapp', configKey: 'theme_schema');

        self::assertSame($first, $second);
    }//end testResolveSchemaIsCached()

    // -------------------------------------------------------------------------
    // resolvePair
    // -------------------------------------------------------------------------

    /**
     * resolvePair returns a RegisterSchemaPair with both entities.
     *
     * @return void
     */
    public function testResolvePairReturnsBothEntities(): void
    {
        $register = $this->makeRegister();
        $schema   = $this->makeSchema();

        $this->appConfig
            ->method('getValueString')
            ->willReturnMap([
                ['myapp', 'theme_register', '__OR_RESOLVER_MISSING__', false, 'my-register-slug'],
                ['myapp', 'theme_schema', '__OR_RESOLVER_MISSING__', false, 'my-schema-slug'],
            ]);

        $this->registerMapper
            ->method('find')
            ->willReturn($register);

        $this->schemaMapper
            ->method('find')
            ->willReturn($schema);

        $pair = $this->service->resolvePair(
            appId: 'myapp',
            registerKey: 'theme_register',
            schemaKey: 'theme_schema'
        );

        self::assertInstanceOf(RegisterSchemaPair::class, $pair);
        self::assertSame($register, $pair->getRegister());
        self::assertSame($schema, $pair->getSchema());
        self::assertSame('my-register-slug', $pair->getResolvedRegisterId());
        self::assertSame('my-schema-slug', $pair->getResolvedSchemaId());
    }//end testResolvePairReturnsBothEntities()

    /**
     * resolvePair propagates RegisterNotFoundException when register is missing.
     *
     * @return void
     */
    public function testResolvePairPropagatesRegisterNotFoundException(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturn('bad-slug');

        $this->registerMapper
            ->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $this->expectException(RegisterNotFoundException::class);

        $this->service->resolvePair(
            appId: 'myapp',
            registerKey: 'theme_register',
            schemaKey: 'theme_schema'
        );
    }//end testResolvePairPropagatesRegisterNotFoundException()

    // -------------------------------------------------------------------------
    // enumerateAppConfigs
    // -------------------------------------------------------------------------

    /**
     * enumerateAppConfigs returns only register/schema keys.
     *
     * @return void
     */
    public function testEnumerateAppConfigsFiltersToRegisterAndSchemaKeys(): void
    {
        $this->appConfig
            ->method('getAllValues')
            ->with('myapp', '')
            ->willReturn([
                'theme_register'  => 'themes-reg',
                'listing_schema'  => 'listings-schema',
                'register'        => 'default-reg',
                'schema'          => 'default-schema',
                'unrelated_key'   => 'some-value',
                'api_token'       => 'secret',
                'page_register'   => 'pages-reg',
            ]);

        $result = $this->service->enumerateAppConfigs(appId: 'myapp');

        self::assertArrayHasKey('theme_register', $result);
        self::assertArrayHasKey('listing_schema', $result);
        self::assertArrayHasKey('register', $result);
        self::assertArrayHasKey('schema', $result);
        self::assertArrayHasKey('page_register', $result);
        self::assertArrayNotHasKey('unrelated_key', $result);
        self::assertArrayNotHasKey('api_token', $result);
        self::assertCount(5, $result);
    }//end testEnumerateAppConfigsFiltersToRegisterAndSchemaKeys()

    /**
     * enumerateAppConfigs returns empty array when no keys match.
     *
     * @return void
     */
    public function testEnumerateAppConfigsReturnsEmptyArrayWhenNoKeysMatch(): void
    {
        $this->appConfig
            ->method('getAllValues')
            ->willReturn(['api_token' => 'secret', 'debug' => 'true']);

        $result = $this->service->enumerateAppConfigs(appId: 'myapp');

        self::assertSame([], $result);
    }//end testEnumerateAppConfigsReturnsEmptyArrayWhenNoKeysMatch()

    // -------------------------------------------------------------------------
    // clearCache
    // -------------------------------------------------------------------------

    /**
     * After clearCache, the mapper is called again for the same key.
     *
     * @return void
     */
    public function testClearCacheForcesMapperCallAfterClear(): void
    {
        $register = $this->makeRegister();

        $this->appConfig
            ->method('getValueString')
            ->willReturn('my-register-slug');

        $this->registerMapper
            ->expects(self::exactly(2))
            ->method('find')
            ->willReturn($register);

        // First call — populates cache.
        $this->service->resolveRegister(appId: 'myapp', configKey: 'theme_register');

        // Clear cache.
        $this->service->clearCache();

        // Second call — must hit mapper again.
        $this->service->resolveRegister(appId: 'myapp', configKey: 'theme_register');
    }//end testClearCacheForcesMapperCallAfterClear()
}//end class
