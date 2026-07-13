<?php

/**
 * OpenRegister schema-versioning entity hydrate tests
 *
 * Regression cover for the missing hydrate() on the schema-versioning entities:
 * their mappers' createFromArray() called $entity->hydrate(), which fell through
 * to Entity::__call and threw "hydrate does not exist" — so every schema
 * changelog / migration-run write failed silently.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\SchemaChangelog;
use OCA\OpenRegister\Db\SchemaRun;
use OCA\OpenRegister\Db\SchemaRunEntry;
use PHPUnit\Framework\TestCase;

class SchemaVersioningEntityHydrateTest extends TestCase
{
    /**
     * SchemaChangelog::hydrate() must exist and populate every field the
     * SchemaVersioningService::recordChangelog() payload carries.
     *
     * @return void
     */
    public function testSchemaChangelogHydrates(): void
    {
        $entry = new SchemaChangelog();
        $this->assertTrue(method_exists($entry, 'hydrate'));

        $acknowledgedAt = new DateTime();
        $entry->hydrate(
            [
                'schemaId'       => 4501,
                'version'        => '1.2.0',
                'classification' => 'breaking',
                'changes'        => [['op' => 'remove', 'path' => '/foo']],
                'actor'          => 'admin',
                'acknowledgedBy' => 'admin',
                'acknowledgedAt' => $acknowledgedAt,
            ]
        );

        $this->assertSame(4501, $entry->getSchemaId());
        $this->assertSame('1.2.0', $entry->getVersion());
        $this->assertSame('breaking', $entry->getClassification());
        $this->assertSame([['op' => 'remove', 'path' => '/foo']], $entry->getChanges());
        $this->assertSame('admin', $entry->getActor());
        $this->assertSame('admin', $entry->getAcknowledgedBy());
        $this->assertSame($acknowledgedAt, $entry->getAcknowledgedAt());
        // Not supplied → stays null so the mapper stamps it.
        $this->assertNull($entry->getCreated());
    }//end testSchemaChangelogHydrates()

    /**
     * An empty json-typed field normalises to null (matching the SyncRecord
     * convention) rather than persisting an empty array.
     *
     * @return void
     */
    public function testEmptyJsonFieldNormalisesToNull(): void
    {
        $entry = new SchemaChangelog();
        $entry->hydrate(['schemaId' => 1, 'changes' => []]);

        $this->assertNull($entry->getChanges());
    }//end testEmptyJsonFieldNormalisesToNull()

    /**
     * Unknown keys are ignored rather than fatal.
     *
     * @return void
     */
    public function testUnknownKeyIsIgnored(): void
    {
        $entry = new SchemaChangelog();
        $entry->hydrate(['schemaId' => 7, 'notAField' => 'x']);

        $this->assertSame(7, $entry->getSchemaId());
    }//end testUnknownKeyIsIgnored()

    /**
     * SchemaRun::hydrate() must exist and populate its fields.
     *
     * @return void
     */
    public function testSchemaRunHydrates(): void
    {
        $run = new SchemaRun();
        $this->assertTrue(method_exists($run, 'hydrate'));

        $run->hydrate(
            [
                'schemaId'  => 12,
                'type'      => 'migration',
                'state'     => 'pending',
                'plan'      => ['steps' => 2],
                'startedBy' => 'admin',
            ]
        );

        $this->assertSame(12, $run->getSchemaId());
        $this->assertSame('migration', $run->getType());
        $this->assertSame('pending', $run->getState());
        $this->assertSame(['steps' => 2], $run->getPlan());
        $this->assertSame('admin', $run->getStartedBy());
    }//end testSchemaRunHydrates()

    /**
     * SchemaRunEntry::hydrate() must exist and populate its fields.
     *
     * @return void
     */
    public function testSchemaRunEntryHydrates(): void
    {
        $entry = new SchemaRunEntry();
        $this->assertTrue(method_exists($entry, 'hydrate'));

        $entry->hydrate(
            [
                'postVersion' => '2.0.0',
                'preData'     => ['a' => 1],
            ]
        );

        $this->assertSame('2.0.0', $entry->getPostVersion());
        $this->assertSame(['a' => 1], $entry->getPreData());
    }//end testSchemaRunEntryHydrates()
}//end class
