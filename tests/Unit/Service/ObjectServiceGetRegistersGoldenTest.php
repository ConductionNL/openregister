<?php

/**
 * Pins ObjectService::getRegisters().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;

/**
 * @covers \OCA\OpenRegister\Service\ObjectService
 */
class ObjectServiceGetRegistersGoldenTest extends TestCase
{
    public function testEachRegisterCarriesItsResolvableSchemas(): void
    {
        $withSchemas = $this->createMock(Register::class);
        $withSchemas->method('jsonSerialize')->willReturn(['id' => 1, 'schemas' => [10, 11]]);
        $withSchemas->method('getSchemas')->willReturn([10, '11']);
        $withoutSchemas = $this->createMock(Register::class);
        $withoutSchemas->method('jsonSerialize')->willReturn(['id' => 2]);
        $withoutSchemas->method('getSchemas')->willReturn([]);

        $registers = $this->createMock(RegisterMapper::class);
        $registers->method('findAll')->willReturn([$withSchemas, $withoutSchemas]);

        $found = $this->createMock(Schema::class);
        $found->method('jsonSerialize')->willReturn(['id' => 10]);
        $schemas = $this->createMock(SchemaMapper::class);
        $schemas->method('find')->willReturnCallback(
            static function (int $id) use ($found): Schema {
                if ($id === 10) {
                    return $found;
                }

                throw new DoesNotExistException('gone');
            }
        );

        $service = (new ReflectionClass(ObjectService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(ObjectService::class, 'registerMapper'))->setValue($service, $registers);
        (new ReflectionProperty(ObjectService::class, 'schemaMapper'))->setValue($service, $schemas);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('debug')->with(
            '[ObjectService] Skipping a register schema that cannot be loaded',
            ['schemaId' => '11', 'exception' => 'gone']
        );
        (new ReflectionProperty(ObjectService::class, 'logger'))->setValue($service, $logger);

        $this->assertSame(
            [['id' => 1, 'schemas' => [['id' => 10]]], ['id' => 2, 'schemas' => []]],
            $service->getRegisters()
        );
    }
}
