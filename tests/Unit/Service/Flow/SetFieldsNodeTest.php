<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\Nodes\SetFieldsNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class SetFieldsNodeTest extends TestCase
{
    private SetFieldsNode $node;

    protected function setUp(): void
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->node = new SetFieldsNode($l10n, $this->createMock(IURLGenerator::class));
    }

    /** @param array<int, array<string, mixed>> $records */
    private function items(array $records): array
    {
        return array_map(static fn (array $r): array => FlowItems::item(json: $r), $records);
    }

    /**
     * The property the item model exists for: one configuration, applied to
     * every item, with no loop drawn by the author.
     */
    public function testOneConfigurationIsAppliedToEveryItem(): void
    {
        $out = $this->node->execute(
            $this->items([['name' => 'a'], ['name' => 'b'], ['name' => 'c']]),
            ['set' => ['status' => 'reviewed']],
            []
        );

        $this->assertCount(3, $out);
        $this->assertSame(['reviewed', 'reviewed', 'reviewed'], array_column(array_column($out, 'json'), 'status'));
        $this->assertSame(['a', 'b', 'c'], array_column(array_column($out, 'json'), 'name'));
    }

    public function testEveryOutputItemPointsBackAtItsOwnInput(): void
    {
        $out = $this->node->execute($this->items([['n' => 1], ['n' => 2]]), ['set' => ['x' => 1]], []);

        $this->assertSame(['item' => 0], $out[0]['pairedItem']);
        $this->assertSame(['item' => 1], $out[1]['pairedItem']);
    }

    public function testFieldsAreRemovedRenamedAndSet(): void
    {
        $out = $this->node->execute(
            $this->items([['drop' => 1, 'old' => 'v', 'keep' => 'k']]),
            ['remove' => ['drop'], 'rename' => ['old' => 'new'], 'set' => ['added' => true]],
            []
        );

        $this->assertSame(['keep' => 'k', 'new' => 'v', 'added' => true], $out[0]['json']);
    }

    /**
     * Remove runs before rename, so a rename may legitimately reuse a name the
     * same step removed.
     */
    public function testARenameCanReuseAJustRemovedName(): void
    {
        $out = $this->node->execute(
            $this->items([['a' => 'old-a', 'b' => 'b-value']]),
            ['remove' => ['a'], 'rename' => ['b' => 'a']],
            []
        );

        $this->assertSame(['a' => 'b-value'], $out[0]['json']);
    }

    public function testKeepOnlySetDropsEverythingElse(): void
    {
        $out = $this->node->execute(
            $this->items([['noise' => 1, 'more' => 2]]),
            ['set' => ['only' => 'this'], 'keepOnlySet' => true],
            []
        );

        $this->assertSame(['only' => 'this'], $out[0]['json']);
    }

    public function testBinaryAttachmentsSurviveTheStep(): void
    {
        $item = FlowItems::item(json: ['a' => 1], binary: ['file' => ['mimeType' => 'text/plain']]);

        $out = $this->node->execute([$item], ['set' => ['b' => 2]], []);

        $this->assertSame(['file' => ['mimeType' => 'text/plain']], $out[0]['binary']);
    }

    public function testNoItemsInMeansNoItemsOut(): void
    {
        $this->assertSame([], $this->node->execute([], ['set' => ['a' => 1]], []));
    }

    /**
     * A step that silently does nothing is worse than one that refuses to save.
     */
    public function testAConfigurationThatWouldDoNothingIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->node->validateConfig([]);
    }

    public function testAUsefulConfigurationValidates(): void
    {
        $this->node->validateConfig(['set' => ['a' => 1]]);
        $this->addToAssertionCount(1);
    }
}
