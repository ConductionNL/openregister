<?php

namespace Unit\Twig;

use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Twig\MappingRuntime;
use OCA\OpenRegister\Twig\MappingRuntimeLoader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Twig\RuntimeLoader\RuntimeLoaderInterface;

class MappingRuntimeLoaderTest extends TestCase
{
    private MappingService&MockObject $mappingService;
    private MappingMapper&MockObject $mappingMapper;
    private MappingRuntimeLoader $loader;

    protected function setUp(): void
    {
        $this->mappingService = $this->createMock(MappingService::class);
        $this->mappingMapper = $this->createMock(MappingMapper::class);
        $this->loader = new MappingRuntimeLoader($this->mappingService, $this->mappingMapper);
    }

    public function testImplementsRuntimeLoaderInterface(): void
    {
        $this->assertInstanceOf(RuntimeLoaderInterface::class, $this->loader);
    }

    public function testLoadReturnsMappingRuntimeForCorrectClass(): void
    {
        $result = $this->loader->load(MappingRuntime::class);
        $this->assertInstanceOf(MappingRuntime::class, $result);
    }

    public function testLoadReturnsNewInstanceEachTime(): void
    {
        $result1 = $this->loader->load(MappingRuntime::class);
        $result2 = $this->loader->load(MappingRuntime::class);
        $this->assertNotSame($result1, $result2);
    }

    public function testLoadReturnsNullForOtherClass(): void
    {
        $result = $this->loader->load(\stdClass::class);
        $this->assertNull($result);
    }

    public function testLoadReturnsNullForRandomString(): void
    {
        $result = $this->loader->load('Some\Nonexistent\Class');
        $this->assertNull($result);
    }

    public function testLoadReturnsNullForEmptyString(): void
    {
        $result = $this->loader->load('');
        $this->assertNull($result);
    }

    public function testLoadReturnsNullForRuntimeExtensionInterface(): void
    {
        $result = $this->loader->load(\Twig\Extension\RuntimeExtensionInterface::class);
        $this->assertNull($result);
    }
}
