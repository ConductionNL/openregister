<?php

namespace Unit\Dto;

use OCA\OpenRegister\Dto\DeletionAnalysis;
use PHPUnit\Framework\TestCase;

class DeletionAnalysisTest extends TestCase
{
    // --- Constructor ---

    public function testConstructorWithAllParameters(): void
    {
        $cascade = [['id' => 1, 'type' => 'child']];
        $nullify = [['id' => 2, 'type' => 'ref']];
        $default = [['id' => 3, 'type' => 'default']];
        $blockers = [['id' => 4, 'type' => 'restrict']];
        $chains = [['path' => 'a -> b -> c']];

        $analysis = new DeletionAnalysis(
            false,
            $cascade,
            $nullify,
            $default,
            $blockers,
            $chains
        );

        $this->assertFalse($analysis->deletable);
        $this->assertSame($cascade, $analysis->cascadeTargets);
        $this->assertSame($nullify, $analysis->nullifyTargets);
        $this->assertSame($default, $analysis->defaultTargets);
        $this->assertSame($blockers, $analysis->blockers);
        $this->assertSame($chains, $analysis->chainPaths);
    }

    public function testConstructorWithDefaults(): void
    {
        $analysis = new DeletionAnalysis(true);

        $this->assertTrue($analysis->deletable);
        $this->assertSame([], $analysis->cascadeTargets);
        $this->assertSame([], $analysis->nullifyTargets);
        $this->assertSame([], $analysis->defaultTargets);
        $this->assertSame([], $analysis->blockers);
        $this->assertSame([], $analysis->chainPaths);
    }

    public function testConstructorNotDeletable(): void
    {
        $analysis = new DeletionAnalysis(false);
        $this->assertFalse($analysis->deletable);
    }

    public function testReadonlyProperties(): void
    {
        $analysis = new DeletionAnalysis(true, [['id' => 1]]);

        $reflection = new \ReflectionClass($analysis);
        $property = $reflection->getProperty('deletable');
        $this->assertTrue($property->isReadOnly());
    }

    // --- empty() ---

    public function testEmptyReturnsDeletableAnalysis(): void
    {
        $analysis = DeletionAnalysis::empty();
        $this->assertTrue($analysis->deletable);
    }

    public function testEmptyReturnsEmptyTargets(): void
    {
        $analysis = DeletionAnalysis::empty();
        $this->assertSame([], $analysis->cascadeTargets);
        $this->assertSame([], $analysis->nullifyTargets);
        $this->assertSame([], $analysis->defaultTargets);
        $this->assertSame([], $analysis->blockers);
        $this->assertSame([], $analysis->chainPaths);
    }

    public function testEmptyReturnsNewInstanceEachTime(): void
    {
        $a = DeletionAnalysis::empty();
        $b = DeletionAnalysis::empty();
        $this->assertNotSame($a, $b);
    }

    // --- toArray() ---

    public function testToArrayWithAllData(): void
    {
        $cascade = [['id' => 1]];
        $nullify = [['id' => 2]];
        $default = [['id' => 3]];
        $blockers = [['id' => 4]];
        $chains = ['a -> b'];

        $analysis = new DeletionAnalysis(false, $cascade, $nullify, $default, $blockers, $chains);

        $expected = [
            'deletable'      => false,
            'cascadeTargets' => $cascade,
            'nullifyTargets' => $nullify,
            'defaultTargets' => $default,
            'blockers'       => $blockers,
            'chainPaths'     => $chains,
        ];

        $this->assertSame($expected, $analysis->toArray());
    }

    public function testToArrayWithDefaults(): void
    {
        $analysis = new DeletionAnalysis(true);

        $expected = [
            'deletable'      => true,
            'cascadeTargets' => [],
            'nullifyTargets' => [],
            'defaultTargets' => [],
            'blockers'       => [],
            'chainPaths'     => [],
        ];

        $this->assertSame($expected, $analysis->toArray());
    }

    public function testToArrayFromEmpty(): void
    {
        $analysis = DeletionAnalysis::empty();
        $array = $analysis->toArray();

        $this->assertTrue($array['deletable']);
        $this->assertCount(6, $array);
    }

    public function testToArrayIsJsonSerializable(): void
    {
        $analysis = new DeletionAnalysis(true, [['id' => 1]]);
        $json = json_encode($analysis->toArray());
        $decoded = json_decode($json, true);

        $this->assertTrue($decoded['deletable']);
        $this->assertSame([['id' => 1]], $decoded['cascadeTargets']);
    }

    public function testToArrayKeys(): void
    {
        $array = DeletionAnalysis::empty()->toArray();
        $this->assertArrayHasKey('deletable', $array);
        $this->assertArrayHasKey('cascadeTargets', $array);
        $this->assertArrayHasKey('nullifyTargets', $array);
        $this->assertArrayHasKey('defaultTargets', $array);
        $this->assertArrayHasKey('blockers', $array);
        $this->assertArrayHasKey('chainPaths', $array);
    }

    // --- Mixed scenarios ---

    public function testMixedTargetsNoBlockers(): void
    {
        $analysis = new DeletionAnalysis(
            true,
            [['objectUuid' => 'c1']],
            [['objectUuid' => 'n1']],
            [['objectUuid' => 'd1']],
            []
        );

        $this->assertTrue($analysis->deletable);
        $this->assertCount(1, $analysis->cascadeTargets);
        $this->assertCount(1, $analysis->nullifyTargets);
        $this->assertCount(1, $analysis->defaultTargets);
        $this->assertEmpty($analysis->blockers);
    }

    public function testBlockedWithBlockers(): void
    {
        $blockers = [
            ['objectUuid' => 'service-uuid-1', 'action' => 'RESTRICT'],
        ];

        $analysis = new DeletionAnalysis(false, [], [], [], $blockers);

        $this->assertFalse($analysis->deletable);
        $this->assertCount(1, $analysis->blockers);
        $this->assertSame('RESTRICT', $analysis->blockers[0]['action']);
    }
}
