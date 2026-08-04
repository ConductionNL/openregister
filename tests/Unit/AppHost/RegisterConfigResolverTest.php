<?php
/**
 * AppHost RegisterConfigResolver — fail-closed resolution tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Exception\ConfigurationMissingException;
use OCA\OpenRegister\AppHost\Exception\FoundationUnavailableException;
use OCA\OpenRegister\AppHost\Service\RegisterConfigResolver;
use OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Empty config ⇒ typed error, unavailable foundation ⇒ typed error — never
 * null, never an empty string (ADR-049).
 */
class RegisterConfigResolverTest extends TestCase
{
    /**
     * Builds a stub standing in for OR's (final) RegisterResolverService — the
     * AppHost resolver only relies on its documented resolve* surface.
     *
     * @param string $registerId Value returned for register lookups.
     * @param string $schemaId   Value returned for schema lookups.
     */
    private function resolverStub(string $registerId='reg-1', string $schemaId='sch-1'): object
    {
        return new class($registerId, $schemaId)
        {
            public function __construct(private string $registerId, private string $schemaId)
            {
            }//end __construct()

            public function resolveRegisterId(string $appId, string $configKey): string
            {
                if ($this->registerId === '') {
                    throw new MissingConfigException(appId: $appId, configKey: $configKey);
                }

                return $this->registerId;
            }//end resolveRegisterId()

            public function resolveSchemaId(string $appId, string $configKey): string
            {
                if ($this->schemaId === '') {
                    throw new MissingConfigException(appId: $appId, configKey: $configKey);
                }

                return $this->schemaId;
            }//end resolveSchemaId()
        };
    }//end resolverStub()

    private function resolver(bool $orInstalled, ?object $orResolver=null, bool $containerThrows=false): RegisterConfigResolver
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->with('openregister')->willReturn($orInstalled);

        $container = $this->createMock(ContainerInterface::class);
        if ($containerThrows === true) {
            $container->method('get')->willThrowException(new \RuntimeException('no binding'));
        } else {
            $container->method('get')
                ->with('OCA\OpenRegister\Service\RegisterResolverService')
                ->willReturn($orResolver ?? $this->resolverStub());
        }

        return new RegisterConfigResolver(
            appId: 'myapp',
            appManager: $appManager,
            container: $container,
            logger: $this->createMock(LoggerInterface::class)
        );
    }//end resolver()

    public function testResolvesConfiguredRegisterAndSchemaIds(): void
    {
        $resolver = $this->resolver(orInstalled: true, orResolver: $this->resolverStub('reg-uuid', 'pet-schema'));

        $this->assertSame('reg-uuid', $resolver->resolveRegisterId());
        $this->assertSame('pet-schema', $resolver->resolveSchemaId(configKey: 'pet_schema'));
        $this->assertSame(
            ['register' => 'reg-uuid', 'schema' => 'pet-schema'],
            $resolver->resolveConfiguration(registerKey: 'register', schemaKey: 'pet_schema')
        );
    }//end testResolvesConfiguredRegisterAndSchemaIds()

    public function testEmptyConfigurationFailsClosedWithTypedException(): void
    {
        // Scenario: Empty configuration fails closed — OR's MissingConfigException
        // is translated to the typed AppHost ConfigurationMissingException.
        $resolver = $this->resolver(orInstalled: true, orResolver: $this->resolverStub('', ''));

        try {
            $resolver->resolveRegisterId(configKey: 'register');
            $this->fail('Expected ConfigurationMissingException');
        } catch (ConfigurationMissingException $e) {
            $this->assertSame('myapp', $e->getAppId());
            $this->assertSame('register', $e->getConfigKey());
            $this->assertInstanceOf(MissingConfigException::class, $e->getPrevious());
        }
    }//end testEmptyConfigurationFailsClosedWithTypedException()

    public function testEmptyResolvedValueFailsClosed(): void
    {
        // Defence in depth: even a resolver returning '' must not leak an
        // empty identifier that would silently match zero objects.
        $stub = new class
        {
            public function resolveRegisterId(string $appId, string $configKey): string
            {
                return '';
            }//end resolveRegisterId()

            public function resolveSchemaId(string $appId, string $configKey): string
            {
                return '';
            }//end resolveSchemaId()
        };

        $this->expectException(ConfigurationMissingException::class);
        $this->resolver(orInstalled: true, orResolver: $stub)->resolveRegisterId();
    }//end testEmptyResolvedValueFailsClosed()

    public function testOrAbsentFailsClosedWithFoundationException(): void
    {
        $this->expectException(FoundationUnavailableException::class);
        $this->resolver(orInstalled: false)->resolveRegisterId();
    }//end testOrAbsentFailsClosedWithFoundationException()

    public function testUnresolvableResolverServiceFailsClosedWithFoundationException(): void
    {
        // The nullable catch-Throwable→null anti-pattern is banned: an
        // unresolvable resolver surfaces as a typed exception.
        $this->expectException(FoundationUnavailableException::class);
        $this->resolver(orInstalled: true, containerThrows: true)->resolveSchemaId(configKey: 'pet_schema');
    }//end testUnresolvableResolverServiceFailsClosedWithFoundationException()
}//end class
