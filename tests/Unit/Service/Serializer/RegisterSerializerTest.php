<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Serializer\RegisterSerializer}.
 *
 * Covers `_extend` expansion (`schemas`, `@self.stats`), orphan-ID
 * retention, unknown-key tolerance, and the entity-contract
 * preservation (`Register::jsonSerialize()` stays ID-only).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Serializer
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
 * @spec openspec/changes/extend-schemas-in-register-service/specs/register-service-extensions/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Serializer;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * RegisterSerializerTest.
 */
class RegisterSerializerTest extends TestCase
{

    private SchemaMapper&MockObject $schemaMapper;

    private LoggerInterface&MockObject $logger;

    private RegisterSerializer $serializer;


    protected function setUp(): void
    {
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->serializer = new RegisterSerializer(
            schemaMapper: $this->schemaMapper,
            logger: $this->logger,
        );

    }//end setUp()


    public function testNoExtendKeepsSchemasAsIds(): void
    {
        $register = $this->makeRegister(7, [10, 20]);

        $this->schemaMapper->expects($this->never())->method('find');

        $result = $this->serializer->serialize($register);

        $this->assertSame([10, 20], $result['schemas']);

    }//end testNoExtendKeepsSchemasAsIds()


    public function testExtendSchemasReplacesIdsWithObjects(): void
    {
        $register = $this->makeRegister(7, [10, 20]);
        $schema10 = $this->makeSchema(10, 'foo');
        $schema20 = $this->makeSchema(20, 'bar');

        // The serializer calls find() with NAMED args (id:, _multitenancy:),
        // so PHPUnit records only the supplied arguments — a positional
        // willReturnMap never matches. Dispatch on the id instead.
        $this->schemaMapper->method('find')->willReturnCallback(
            function ($id) use ($schema10, $schema20) {
                return ($id === 10) ? $schema10 : $schema20;
            }
        );

        $result = $this->serializer->serialize($register, ['schemas']);

        $this->assertCount(2, $result['schemas']);
        $this->assertSame(10, $result['schemas'][0]['id']);
        $this->assertSame(20, $result['schemas'][1]['id']);

    }//end testExtendSchemasReplacesIdsWithObjects()


    public function testOrphanIdIsRetainedAtOriginalPosition(): void
    {
        $register = $this->makeRegister(7, [10, 999, 20]);
        $schema10 = $this->makeSchema(10, 'foo');
        $schema20 = $this->makeSchema(20, 'bar');

        $this->schemaMapper->method('find')->willReturnCallback(
            function ($id) use ($schema10, $schema20) {
                if ($id === 10) {
                    return $schema10;
                }

                if ($id === 20) {
                    return $schema20;
                }

                throw new DoesNotExistException('schema not found');
            }
        );

        $this->logger->expects($this->once())->method('warning');

        $result = $this->serializer->serialize($register, ['schemas']);

        $this->assertIsArray($result['schemas'][0]);
        $this->assertSame(999, $result['schemas'][1]);
        $this->assertIsArray($result['schemas'][2]);

    }//end testOrphanIdIsRetainedAtOriginalPosition()


    public function testOrphanStringIdRetainsItsType(): void
    {
        $register = $this->makeRegister(7, ['uuid-abc-123', 20]);
        $schema20 = $this->makeSchema(20, 'bar');

        $this->schemaMapper->method('find')->willReturnCallback(
            function ($id) use ($schema20) {
                if ($id === 20) {
                    return $schema20;
                }

                throw new DoesNotExistException('schema not found');
            }
        );

        $result = $this->serializer->serialize($register, ['schemas']);

        $this->assertSame('uuid-abc-123', $result['schemas'][0]);
        $this->assertIsArray($result['schemas'][1]);

    }//end testOrphanStringIdRetainsItsType()


    public function testStatsExtendAttachesPerSchemaTotals(): void
    {
        $register = $this->makeRegister(7, [10, 20]);
        $schema10 = $this->makeSchema(10, 'foo');
        $schema20 = $this->makeSchema(20, 'bar');

        $this->schemaMapper->method('find')->willReturnCallback(
            function ($id) use ($schema10, $schema20) {
                if ($id === 10) {
                    return $schema10;
                }

                return $schema20;
            }
        );

        $stats = [
            10 => ['total' => 5],
            20 => ['total' => 0],
        ];

        $result = $this->serializer->serialize($register, ['schemas', '@self.stats'], $stats);

        $this->assertSame(5, $result['schemas'][0]['stats']['objects']['total']);
        $this->assertSame(0, $result['schemas'][1]['stats']['objects']['total']);

    }//end testStatsExtendAttachesPerSchemaTotals()


    public function testStatsAreNotAttachedToOrphanIds(): void
    {
        $register = $this->makeRegister(7, [10, 999]);
        $schema10 = $this->makeSchema(10, 'foo');

        $this->schemaMapper->method('find')->willReturnCallback(
            function ($id) use ($schema10) {
                if ($id === 10) {
                    return $schema10;
                }

                throw new DoesNotExistException('schema not found');
            }
        );

        $stats  = [10 => ['total' => 5]];
        $result = $this->serializer->serialize($register, ['schemas', '@self.stats'], $stats);

        $this->assertIsArray($result['schemas'][0]);
        $this->assertSame(5, $result['schemas'][0]['stats']['objects']['total']);
        // Orphan stays a bare ID with no stats.
        $this->assertSame(999, $result['schemas'][1]);

    }//end testStatsAreNotAttachedToOrphanIds()


    public function testStatsAloneHasNoEffectOnSchemas(): void
    {
        $register = $this->makeRegister(7, [10, 20]);

        $this->schemaMapper->expects($this->never())->method('find');

        $result = $this->serializer->serialize($register, ['@self.stats']);

        $this->assertSame([10, 20], $result['schemas']);

    }//end testStatsAloneHasNoEffectOnSchemas()


    public function testUnknownExtendKeyIsIgnoredSilently(): void
    {
        $register = $this->makeRegister(7, [10]);
        $schema10 = $this->makeSchema(10, 'foo');

        $this->schemaMapper->method('find')->willReturn($schema10);
        $this->logger->expects($this->never())->method('warning');

        $result = $this->serializer->serialize($register, ['schemas', 'nonexistent-key']);

        $this->assertCount(1, $result['schemas']);
        $this->assertIsArray($result['schemas'][0]);

    }//end testUnknownExtendKeyIsIgnoredSilently()


    public function testEmptySchemasArrayResultsInEmptyOutput(): void
    {
        $register = $this->makeRegister(7, []);

        $this->schemaMapper->expects($this->never())->method('find');

        $result = $this->serializer->serialize($register, ['schemas']);

        $this->assertSame([], $result['schemas']);

    }//end testEmptySchemasArrayResultsInEmptyOutput()


    public function testEntityJsonSerializeRemainsIdOnly(): void
    {
        $register = $this->makeRegister(7, [10, 20]);

        $raw = $register->jsonSerialize();

        $this->assertSame([10, 20], $raw['schemas']);

    }//end testEntityJsonSerializeRemainsIdOnly()


    public function testSerializeManyDelegatesPerRegister(): void
    {
        $registerA = $this->makeRegister(1, [10]);
        $registerB = $this->makeRegister(2, [20]);
        $schema10  = $this->makeSchema(10, 'foo');
        $schema20  = $this->makeSchema(20, 'bar');

        $this->schemaMapper->method('find')->willReturnCallback(
            function ($id) use ($schema10, $schema20) {
                if ($id === 10) {
                    return $schema10;
                }

                return $schema20;
            }
        );

        $stats = [
            1 => [10 => ['total' => 3]],
            2 => [20 => ['total' => 0]],
        ];

        $result = $this->serializer->serializeMany([$registerA, $registerB], ['schemas', '@self.stats'], $stats);

        $this->assertCount(2, $result);
        $this->assertSame(3, $result[0]['schemas'][0]['stats']['objects']['total']);
        $this->assertSame(0, $result[1]['schemas'][0]['stats']['objects']['total']);

    }//end testSerializeManyDelegatesPerRegister()


    private function makeRegister(int $id, array $schemas): Register
    {
        $register = new Register();
        $register->setId($id);
        $register->setSchemas($schemas);
        return $register;

    }//end makeRegister()


    private function makeSchema(int $id, string $slug): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setSlug($slug);
        return $schema;

    }//end makeSchema()


    /**
     * Registers share schemas, and serializeMany() expands the schemas of EVERY register.
     * Each schema used to be re-fetched once per register that referenced it — and
     * SchemaMapper::find() resolves an identifier with `WHERE uuid = ? OR slug = ? OR
     * id = ?`, which no index covers. On a dev instance (76 registers, 1,231 schemas) that
     * was the hottest query in the request; `GET /api/registers?_extend[]=schemas&
     * _extend[]=@self.stats` took 76 seconds.
     *
     * @return void
     */
    public function testSchemasSharedAcrossRegistersAreFetchedOnce(): void
    {
        // Three registers, all referencing the same two schemas.
        $registers = [
            $this->makeRegister(1, [10, 20]),
            $this->makeRegister(2, [10, 20]),
            $this->makeRegister(3, [10, 20]),
        ];

        $schema10 = $this->makeSchema(10, 'foo');
        $schema20 = $this->makeSchema(20, 'bar');

        $fetched = [];
        $this->schemaMapper->method('find')->willReturnCallback(
            function ($id) use ($schema10, $schema20, &$fetched) {
                $fetched[] = $id;
                return ($id === 10) ? $schema10 : $schema20;
            }
        );

        $result = $this->serializer->serializeMany($registers, ['schemas']);

        // Six references, two distinct schemas → two reads, not six.
        $this->assertSame([10, 20], $fetched);

        // ...and every register still gets both schemas expanded.
        $this->assertCount(3, $result);
        foreach ($result as $register) {
            $this->assertCount(2, $register['schemas']);
            $this->assertSame(10, $register['schemas'][0]['id']);
            $this->assertSame(20, $register['schemas'][1]['id']);
        }

    }//end testSchemasSharedAcrossRegistersAreFetchedOnce()


    /**
     * The cache must not swallow a miss: an orphan id still has to throw out of the mapper
     * so expandSchemas() can retain it in place, and it must not be cached as a "hit".
     *
     * @return void
     */
    public function testOrphanIdIsStillRetainedWhenSchemasAreCached(): void
    {
        $registers = [
            $this->makeRegister(1, [10, 999]),
            $this->makeRegister(2, [10, 999]),
        ];

        $schema10 = $this->makeSchema(10, 'foo');

        $this->schemaMapper->method('find')->willReturnCallback(
            function ($id) use ($schema10) {
                if ($id === 10) {
                    return $schema10;
                }

                throw new DoesNotExistException('schema not found');
            }
        );

        $result = $this->serializer->serializeMany($registers, ['schemas']);

        foreach ($result as $register) {
            $this->assertSame(10, $register['schemas'][0]['id']);
            $this->assertSame(999, $register['schemas'][1]);
        }

    }//end testOrphanIdIsStillRetainedWhenSchemasAreCached()


}//end class
