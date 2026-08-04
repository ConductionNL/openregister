<?php

/**
 * Unit tests for SchemaDiffService.
 *
 * Pure tests (no NC container) covering every classification rule, the
 * derived version bump, the metadata-only no-op, declared renames, and
 * constraint tightening/relaxation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

use OCA\OpenRegister\Service\Schema\SchemaChangeSet;
use OCA\OpenRegister\Service\Schema\SchemaDiffService;
use PHPUnit\Framework\TestCase;

class SchemaDiffServiceTest extends TestCase
{
    private SchemaDiffService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SchemaDiffService();
    }

    private function def(array $properties, array $required = []): array
    {
        return ['properties' => $properties, 'required' => $required];
    }

    public function testAddedOptionalPropertyIsCompatibleMinor(): void
    {
        $old = $this->def(['name' => ['type' => 'string']]);
        $new = $this->def([
            'name'     => ['type' => 'string'],
            'nickname' => ['type' => 'string'],
        ]);

        $cs = $this->service->diff($old, $new);

        $this->assertSame(SchemaChangeSet::CLASS_COMPATIBLE, $cs->getClassification());
        $this->assertSame('minor', $cs->getBump());
        $this->assertSame('1.3.0', $this->service->nextVersion('1.2.0', $cs));
    }

    public function testTypeChangeIsBreakingMajor(): void
    {
        $old = $this->def(['age' => ['type' => 'string']]);
        $new = $this->def(['age' => ['type' => 'integer']]);

        $cs = $this->service->diff($old, $new);

        $this->assertTrue($cs->isBreaking());
        $this->assertSame('major', $cs->getBump());
        $this->assertSame('2.0.0', $this->service->nextVersion('1.3.0', $cs));

        $found = false;
        foreach ($cs->getChanges() as $c) {
            if ($c['kind'] === 'type_changed') {
                $this->assertSame('string', $c['old']);
                $this->assertSame('integer', $c['new']);
                $found = true;
            }
        }
        $this->assertTrue($found, 'type_changed entry recorded with old/new types');
    }

    public function testNewRequiredPropertyWithoutDefaultIsBreaking(): void
    {
        $old = $this->def(['email' => ['type' => 'string']], []);
        $new = $this->def(['email' => ['type' => 'string']], ['email']);

        $cs = $this->service->diff($old, $new);

        $this->assertTrue($cs->isBreaking());
        $this->assertSame('major', $cs->getBump());
    }

    public function testNewRequiredPropertyWithDefaultIsCompatible(): void
    {
        $old = $this->def(['email' => ['type' => 'string']], []);
        $new = $this->def(['email' => ['type' => 'string', 'default' => 'x@example.org']], ['email']);

        $cs = $this->service->diff($old, $new);

        $this->assertFalse($cs->isBreaking());
        $this->assertSame(SchemaChangeSet::CLASS_COMPATIBLE, $cs->getClassification());
    }

    public function testNewAddedRequiredPropertyNoDefaultIsBreaking(): void
    {
        $old = $this->def(['name' => ['type' => 'string']]);
        $new = $this->def([
            'name'  => ['type' => 'string'],
            'phone' => ['type' => 'string'],
        ], ['phone']);

        $cs = $this->service->diff($old, $new);

        $this->assertTrue($cs->isBreaking());
    }

    public function testRemovedPropertyIsBreaking(): void
    {
        $old = $this->def(['a' => ['type' => 'string'], 'b' => ['type' => 'string']]);
        $new = $this->def(['a' => ['type' => 'string']]);

        $cs = $this->service->diff($old, $new);

        $this->assertTrue($cs->isBreaking());
        $kinds = array_column($cs->getChanges(), 'kind');
        $this->assertContains('removed', $kinds);
    }

    public function testTightenedMinLengthIsBreaking(): void
    {
        $old = $this->def(['name' => ['type' => 'string', 'minLength' => 1]]);
        $new = $this->def(['name' => ['type' => 'string', 'minLength' => 5]]);

        $cs = $this->service->diff($old, $new);

        $this->assertTrue($cs->isBreaking());
        $this->assertContains('constraint_tightened', array_column($cs->getChanges(), 'kind'));
    }

    public function testRelaxedMinLengthIsCompatiblePatch(): void
    {
        $old = $this->def(['name' => ['type' => 'string', 'minLength' => 5]]);
        $new = $this->def(['name' => ['type' => 'string', 'minLength' => 1]]);

        $cs = $this->service->diff($old, $new);

        $this->assertFalse($cs->isBreaking());
        $this->assertSame('patch', $cs->getBump());
        $this->assertSame('1.2.4', $this->service->nextVersion('1.2.3', $cs));
    }

    public function testNarrowedEnumIsBreaking(): void
    {
        $old = $this->def(['status' => ['type' => 'string', 'enum' => ['a', 'b', 'c']]]);
        $new = $this->def(['status' => ['type' => 'string', 'enum' => ['a', 'b']]]);

        $cs = $this->service->diff($old, $new);

        $this->assertTrue($cs->isBreaking());
    }

    public function testWidenedEnumIsCompatible(): void
    {
        $old = $this->def(['status' => ['type' => 'string', 'enum' => ['a', 'b']]]);
        $new = $this->def(['status' => ['type' => 'string', 'enum' => ['a', 'b', 'c']]]);

        $cs = $this->service->diff($old, $new);

        $this->assertFalse($cs->isBreaking());
    }

    public function testNewFormatIsBreaking(): void
    {
        $old = $this->def(['when' => ['type' => 'string']]);
        $new = $this->def(['when' => ['type' => 'string', 'format' => 'date-time']]);

        $cs = $this->service->diff($old, $new);

        $this->assertTrue($cs->isBreaking());
    }

    public function testMetadataOnlyChangeIsNoChange(): void
    {
        $old = $this->def(['name' => ['type' => 'string', 'description' => 'old']]);
        $new = $this->def(['name' => ['type' => 'string', 'description' => 'old']]);

        $cs = $this->service->diff($old, $new);

        $this->assertFalse($cs->hasChanges());
        $this->assertSame(SchemaChangeSet::CLASS_NONE, $cs->getClassification());
        $this->assertSame('none', $cs->getBump());
        // No version bump on a no-op.
        $this->assertSame('1.2.3', $this->service->nextVersion('1.2.3', $cs));
    }

    public function testDescriptionOnlyChangeIsCompatible(): void
    {
        $old = $this->def(['name' => ['type' => 'string', 'description' => 'old']]);
        $new = $this->def(['name' => ['type' => 'string', 'description' => 'new']]);

        $cs = $this->service->diff($old, $new);

        // Description is metadata only — not a structural change.
        $this->assertFalse($cs->hasChanges());
    }

    public function testDeclaredRenameIsSingleBreakingChange(): void
    {
        $old = $this->def(['fullname' => ['type' => 'string']]);
        $new = $this->def(['name' => ['type' => 'string']]);

        $cs = $this->service->diff($old, $new, ['fullname' => 'name']);

        $this->assertTrue($cs->isBreaking());
        $kinds = array_column($cs->getChanges(), 'kind');
        $this->assertContains('renamed', $kinds);
        // Not double counted as remove + add.
        $this->assertNotContains('removed', $kinds);
        $this->assertNotContains('added', $kinds);
    }

    public function testUndeclaredRenameReadsAsRemoveAndAddBreaking(): void
    {
        $old = $this->def(['fullname' => ['type' => 'string']]);
        $new = $this->def(['name' => ['type' => 'string']]);

        $cs = $this->service->diff($old, $new);

        $this->assertTrue($cs->isBreaking());
        $kinds = array_column($cs->getChanges(), 'kind');
        $this->assertContains('removed', $kinds);
        $this->assertContains('added', $kinds);
    }

    public function testNextVersionFromNullStartsAtZero(): void
    {
        $old = $this->def(['name' => ['type' => 'string']]);
        $new = $this->def([
            'name'  => ['type' => 'string'],
            'extra' => ['type' => 'string'],
        ]);

        $cs = $this->service->diff($old, $new);
        $this->assertSame('0.1.0', $this->service->nextVersion(null, $cs));
    }

    public function testDroppedRequirementIsCompatible(): void
    {
        $old = $this->def(['name' => ['type' => 'string']], ['name']);
        $new = $this->def(['name' => ['type' => 'string']], []);

        $cs = $this->service->diff($old, $new);

        $this->assertFalse($cs->isBreaking());
        $this->assertContains('required_removed', array_column($cs->getChanges(), 'kind'));
    }
}
