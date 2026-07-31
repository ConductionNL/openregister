<?php

/**
 * Unit tests for FlowStateHandle.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowStateHandle;
use PHPUnit\Framework\TestCase;

final class FlowStateHandleTest extends TestCase
{
    /**
     * A handle built from stored values reads them back.
     *
     * This is the property that makes a scheduled flow's next tick useful: the
     * fifth run sees what the fourth wrote.
     *
     * @return void
     */
    public function testStoredValuesAreReadable(): void
    {
        $handle = new FlowStateHandle(values: ['cursor' => 12, 'slots' => ['1' => 'job-7']]);

        self::assertSame(expected: 12, actual: $handle->get('cursor'));
        self::assertSame(expected: ['1' => 'job-7'], actual: $handle->get('slots'));

    }//end testStoredValuesAreReadable()

    /**
     * An unknown key returns the caller's default.
     *
     * A flow's FIRST tick has nothing stored, and that must not be a special
     * case the flow author has to handle.
     *
     * @return void
     */
    public function testUnknownKeyReturnsTheDefault(): void
    {
        $handle = new FlowStateHandle();

        self::assertSame(expected: 'none', actual: $handle->get('cursor', 'none'));
        self::assertNull(actual: $handle->get('cursor'));

    }//end testUnknownKeyReturnsTheDefault()

    /**
     * A fresh handle is clean; writing marks it dirty.
     *
     * This is what stops a flow that merely READS its state from rewriting the
     * row on every tick. On a five-minute schedule that difference is thousands
     * of pointless writes a week.
     *
     * @return void
     */
    public function testOnlyWritingMarksTheHandleDirty(): void
    {
        $handle = new FlowStateHandle(values: ['cursor' => 1]);
        self::assertFalse(condition: $handle->isDirty());

        $handle->get('cursor');
        self::assertFalse(condition: $handle->isDirty());

        $handle->set('cursor', 2);
        self::assertTrue(condition: $handle->isDirty());

    }//end testOnlyWritingMarksTheHandleDirty()

    /**
     * Forgetting a key removes it and marks the handle dirty.
     *
     * Releasing a slot is a delete, not a write of null — `has()` must go false
     * so a later claim sees the slot as free.
     *
     * @return void
     */
    public function testForgettingRemovesTheKeyAndDirties(): void
    {
        $handle = new FlowStateHandle(values: ['slot1' => 'job-7']);

        $handle->forget('slot1');

        self::assertFalse(condition: $handle->has('slot1'));
        self::assertTrue(condition: $handle->isDirty());

    }//end testForgettingRemovesTheKeyAndDirties()

    /**
     * Forgetting an absent key does not dirty the handle.
     *
     * Otherwise a flow that releases an already-free slot would rewrite the row
     * for nothing on every tick.
     *
     * @return void
     */
    public function testForgettingAnAbsentKeyDoesNotDirty(): void
    {
        $handle = new FlowStateHandle();

        $handle->forget('slot1');

        self::assertFalse(condition: $handle->isDirty());

    }//end testForgettingAnAbsentKeyDoesNotDirty()

    /**
     * `has()` distinguishes a stored null from an absent key.
     *
     * A flow may deliberately store null — an empty slot, say — and that is not
     * the same as never having written the key.
     *
     * @return void
     */
    public function testHasDistinguishesStoredNullFromAbsent(): void
    {
        $handle = new FlowStateHandle(values: ['slot1' => null]);

        self::assertTrue(condition: $handle->has('slot1'));
        self::assertFalse(condition: $handle->has('slot2'));
        self::assertNull(actual: $handle->get('slot1'));

    }//end testHasDistinguishesStoredNullFromAbsent()

    /**
     * The handle serialises to exactly what was stored.
     *
     * @return void
     */
    public function testSerialisesToItsValues(): void
    {
        $handle = new FlowStateHandle(values: ['cursor' => 1]);
        $handle->set('slot1', 'job-7');

        self::assertSame(
            expected: ['cursor' => 1, 'slot1' => 'job-7'],
            actual: $handle->jsonSerialize()
        );

    }//end testSerialisesToItsValues()
}//end class
