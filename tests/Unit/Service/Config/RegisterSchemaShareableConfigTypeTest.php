<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Config;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Service\Config\Types\RegisterSchemaShareableConfigType;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\RegisterService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class RegisterSchemaShareableConfigTypeTest extends TestCase
{
    private RegisterService&MockObject $registers;

    private ConfigurationService&MockObject $config;

    private RegisterSchemaShareableConfigType $type;

    protected function setUp(): void
    {
        $this->registers = $this->createMock(RegisterService::class);
        $this->config    = $this->createMock(ConfigurationService::class);
        $this->type      = new RegisterSchemaShareableConfigType($this->registers, $this->config);
    }

    public function testIdentity(): void
    {
        $this->assertSame('openregister.registers', $this->type->getId());
        $this->assertSame('openregister-register', $this->type->getTopic());
    }

    public function testSerialiseExportsTheNamedRegister(): void
    {
        $this->registers->method('find')->with('flows')->willReturn($this->createMock(Register::class));
        $this->config->expects($this->once())->method('exportConfig')
            ->willReturn(['openapi' => '3.0.0', 'components' => ['registers' => ['flows' => []]]]);

        $bundle = $this->type->serialise(['register' => 'flows']);
        $this->assertArrayHasKey('registers', $bundle['components']);
    }

    public function testSerialiseNeedsARegister(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->type->serialise([]);
    }

    public function testSerialiseUnknownRegisterIsRefused(): void
    {
        $this->registers->method('find')->willThrowException(new \RuntimeException('nope'));
        $this->expectException(UnexpectedValueException::class);
        $this->type->serialise(['register' => 'ghost']);
    }

    public function testDeserialiseImportsWithTheBundleVersion(): void
    {
        $this->config->expects($this->once())->method('importFromApp')
            ->with('openregister', $this->anything(), '2.3.0', false)
            ->willReturn(['registers' => ['flows'], 'schemas' => ['flow']]);

        $result = $this->type->deserialise([
            'info'       => ['version' => '2.3.0'],
            'components' => ['registers' => ['flows' => []]],
        ]);
        $this->assertContains('flows', $result['registers']);
    }
}
