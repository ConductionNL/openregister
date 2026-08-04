<?php
/**
 * The friendly slug-collision message had been dead since 2026-07-23.
 *
 * `Version1Date20260723000000` renamed the unique indexes when it widened the
 * key from (organisation, slug) to (organisation, application, slug), and this
 * parser was not renamed with it. On every migrated instance the specific
 * message silently degraded to the generic "This schema already exists" — a
 * control that still existed, was still referenced, and no longer fired.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Exception;

use Exception;
use OCA\OpenRegister\Exception\DatabaseConstraintException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Exception\DatabaseConstraintException
 */
class DatabaseConstraintExceptionSlugTest extends TestCase
{

    /**
     * The index names in use since 2026-07-23 produce the specific message.
     *
     * @dataProvider currentIndexProvider
     *
     * @param string $index    The index name in the DB error.
     * @param string $entity   The entity type.
     * @param string $expected A phrase the message must contain.
     *
     * @return void
     */
    public function testCurrentIndexNamesAreRecognised(string $index, string $entity, string $expected): void
    {
        $message = DatabaseConstraintException::fromDatabaseException(
            new Exception(sprintf("Duplicate entry 'agentflow' for key '%s'", $index)),
            $entity
        )->getMessage();

        $this->assertStringContainsString($expected, $message);
    }

    /**
     * The post-migration index names.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function currentIndexProvider(): array
    {
        return [
            'schema (new)'   => ['schemas_org_app_slug_unique', 'schema', 'A schema with this slug already exists'],
            'register (new)' => ['registers_org_app_slug_unique', 'register', 'A register with this slug already exists'],
        ];
    }

    /**
     * The pre-migration names still work — an unmigrated instance keeps its message.
     *
     * @dataProvider legacyIndexProvider
     *
     * @param string $index    The legacy index name.
     * @param string $entity   The entity type.
     * @param string $expected A phrase the message must contain.
     *
     * @return void
     */
    public function testLegacyIndexNamesStillRecognised(string $index, string $entity, string $expected): void
    {
        $message = DatabaseConstraintException::fromDatabaseException(
            new Exception(sprintf("Duplicate entry 'agentflow' for key '%s'", $index)),
            $entity
        )->getMessage();

        $this->assertStringContainsString($expected, $message);
    }

    /**
     * The pre-migration index names.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function legacyIndexProvider(): array
    {
        return [
            'schema (old)'   => ['schemas_organisation_slug_unique', 'schema', 'A schema with this slug already exists'],
            'register (old)' => ['registers_organisation_slug_unique', 'register', 'A register with this slug already exists'],
        ];
    }

    /**
     * POSITIVE CONTROL: an unrelated unique index still gets the generic message.
     *
     * Without this the tests above pass for a parser that returns the schema
     * message unconditionally.
     *
     * @return void
     */
    public function testAnUnrelatedUniqueIndexKeepsTheGenericMessage(): void
    {
        $message = DatabaseConstraintException::fromDatabaseException(
            new Exception("Duplicate entry 'x' for key 'some_other_unique_idx'"),
            'schema'
        )->getMessage();

        $this->assertStringNotContainsString('with this slug', $message);
        $this->assertStringContainsString('already exists', $message);
    }
}
