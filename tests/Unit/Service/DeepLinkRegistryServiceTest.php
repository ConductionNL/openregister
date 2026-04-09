<?php

namespace Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Dto\DeepLinkRegistration;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class DeepLinkRegistryServiceTest extends TestCase
{

    /**
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface $container;

    /**
     * @var RegisterMapper&MockObject
     */
    private RegisterMapper $registerMapper;

    /**
     * @var SchemaMapper&MockObject
     */
    private SchemaMapper $schemaMapper;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    private DeepLinkRegistryService $service;

    protected function setUp(): void
    {
        // Reset static state before each test.
        DeepLinkRegistryService::reset();

        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->container = $this->createMock(ContainerInterface::class);
        $this->container->method('get')->willReturnCallback(function (string $class) {
            if ($class === RegisterMapper::class) {
                return $this->registerMapper;
            }
            if ($class === SchemaMapper::class) {
                return $this->schemaMapper;
            }
            return null;
        });

        $this->service = new DeepLinkRegistryService(
            $this->container,
            $this->logger
        );
    }

    protected function tearDown(): void
    {
        DeepLinkRegistryService::reset();
    }

    // --- register ---

    public function testRegisterAddsRegistration(): void
    {
        $this->service->register('procest', 'my-register', 'my-schema', '/apps/procest/#/cases/{uuid}');

        $this->assertTrue($this->service->hasRegistrations());
    }

    public function testRegisterIgnoresDuplicateKey(): void
    {
        $this->service->register('procest', 'reg', 'schema', '/apps/procest/{uuid}');
        $this->service->register('pipelinq', 'reg', 'schema', '/apps/pipelinq/{uuid}');

        // The first registration should win.
        $this->assertTrue($this->service->hasRegistrations());
    }

    public function testRegisterWithCustomIcon(): void
    {
        $this->service->register('procest', 'reg', 'schema', '/apps/procest/{uuid}', 'custom-icon');

        $this->assertTrue($this->service->hasRegistrations());
    }

    public function testRegisterWithDefaultIcon(): void
    {
        $this->service->register('procest', 'reg', 'schema', '/apps/procest/{uuid}');

        $this->assertTrue($this->service->hasRegistrations());
    }

    // --- resolve ---

    public function testResolveReturnsNullWhenNoRegistrations(): void
    {
        $result = $this->service->resolve(1, 1);

        $this->assertNull($result);
    }

    public function testResolveReturnsRegistrationByIds(): void
    {
        // Register a deep link.
        $this->service->register('procest', 'cases', 'case-schema', '/apps/procest/#/cases/{uuid}');

        // Mock register mapper to return a register with slug "cases".
        $register = new Register();
        $reflection = new \ReflectionClass($register);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($register, 1);
        $register->setSlug('cases');

        $this->registerMapper
            ->method('findAll')
            ->willReturn([$register]);

        // Mock schema mapper.
        $schema = new Schema();
        $sReflection = new \ReflectionClass($schema);
        $sIdProp = $sReflection->getProperty('id');
        $sIdProp->setAccessible(true);
        $sIdProp->setValue($schema, 10);
        $schema->setSlug('case-schema');

        $this->schemaMapper
            ->method('findAll')
            ->willReturn([$schema]);

        $result = $this->service->resolve(1, 10);

        $this->assertNotNull($result);
        $this->assertInstanceOf(DeepLinkRegistration::class, $result);
        $this->assertSame('procest', $result->appId);
    }

    public function testResolveReturnsNullForUnknownIds(): void
    {
        $this->service->register('procest', 'cases', 'case-schema', '/apps/procest/{uuid}');

        $this->registerMapper
            ->method('findAll')
            ->willReturn([]);

        $this->schemaMapper
            ->method('findAll')
            ->willReturn([]);

        $result = $this->service->resolve(999, 999);

        $this->assertNull($result);
    }

    // --- resolveUrl ---

    public function testResolveUrlReturnsNullWhenNoRegistration(): void
    {
        $result = $this->service->resolveUrl(1, 1, ['uuid' => 'abc']);

        $this->assertNull($result);
    }

    public function testResolveUrlResolvesTemplate(): void
    {
        $this->service->register('procest', 'cases', 'case-schema', '/apps/procest/#/cases/{uuid}');

        $register = new Register();
        $reflection = new \ReflectionClass($register);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($register, 1);
        $register->setSlug('cases');

        $schema = new Schema();
        $sReflection = new \ReflectionClass($schema);
        $sIdProp = $sReflection->getProperty('id');
        $sIdProp->setAccessible(true);
        $sIdProp->setValue($schema, 10);
        $schema->setSlug('case-schema');

        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $result = $this->service->resolveUrl(1, 10, ['uuid' => 'abc-123']);

        $this->assertSame('/apps/procest/#/cases/abc-123', $result);
    }

    // --- resolveIcon ---

    public function testResolveIconReturnsNullWhenNoRegistration(): void
    {
        $result = $this->service->resolveIcon(1, 1);

        $this->assertNull($result);
    }

    public function testResolveIconReturnsIcon(): void
    {
        $this->service->register('procest', 'cases', 'case-schema', '/apps/procest/{uuid}', 'my-icon');

        $register = new Register();
        $reflection = new \ReflectionClass($register);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($register, 1);
        $register->setSlug('cases');

        $schema = new Schema();
        $sReflection = new \ReflectionClass($schema);
        $sIdProp = $sReflection->getProperty('id');
        $sIdProp->setAccessible(true);
        $sIdProp->setValue($schema, 10);
        $schema->setSlug('case-schema');

        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $result = $this->service->resolveIcon(1, 10);

        $this->assertSame('my-icon', $result);
    }

    // --- hasRegistrations ---

    public function testHasRegistrationsReturnsFalseWhenEmpty(): void
    {
        $this->assertFalse($this->service->hasRegistrations());
    }

    public function testHasRegistrationsReturnsTrueAfterRegister(): void
    {
        $this->service->register('procest', 'reg', 'schema', '/url/{uuid}');

        $this->assertTrue($this->service->hasRegistrations());
    }

    // --- reset ---

    public function testResetClearsAllRegistrations(): void
    {
        $this->service->register('procest', 'reg', 'schema', '/url/{uuid}');
        $this->assertTrue($this->service->hasRegistrations());

        DeepLinkRegistryService::reset();

        $this->assertFalse($this->service->hasRegistrations());
    }

    // --- Edge cases with mapper errors ---

    public function testResolveHandlesRegisterMapperException(): void
    {
        $this->service->register('procest', 'cases', 'case-schema', '/url/{uuid}');

        $this->registerMapper
            ->method('findAll')
            ->willThrowException(new \Exception('DB error'));

        $this->schemaMapper
            ->method('findAll')
            ->willReturn([]);

        // Should return null when slugs can't be resolved.
        $result = $this->service->resolve(1, 1);

        $this->assertNull($result);
    }

    public function testResolveHandlesSchemaMapperException(): void
    {
        $this->service->register('procest', 'cases', 'case-schema', '/url/{uuid}');

        $register = new Register();
        $reflection = new \ReflectionClass($register);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($register, 1);
        $register->setSlug('cases');

        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->schemaMapper->method('findAll')->willThrowException(new \Exception('DB error'));

        $result = $this->service->resolve(1, 10);

        $this->assertNull($result);
    }
}
