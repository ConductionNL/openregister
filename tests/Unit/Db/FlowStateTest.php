<?php

/**
 * Unit tests for the FlowState entity.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\FlowState;
use PHPUnit\Framework\TestCase;

final class FlowStateTest extends TestCase
{
    /**
     * State round-trips as an array.
     *
     * The `json` field type is what lets a flow keep a structured value — a
     * slot table, a cursor, a counter — rather than a string it has to parse
     * itself on every tick.
     *
     * @return void
     */
    public function testStateRoundTripsAsAnArray(): void
    {
        $entity = new FlowState();
        $entity->setFlowId('flow-1');
        $entity->setState(['slots' => ['1' => 'job-7'], 'cursor' => 12]);

        self::assertSame(expected: 'flow-1', actual: $entity->getFlowId());
        self::assertSame(expected: ['slots' => ['1' => 'job-7'], 'cursor' => 12], actual: $entity->getState());

    }//end testStateRoundTripsAsAnArray()

    /**
     * A flow with no state yet serialises as an empty map, not null.
     *
     * A consumer reading `state` should be able to index into it without a null
     * check on the very first tick, which is exactly when a scheduled flow has
     * nothing stored.
     *
     * @return void
     */
    public function testAbsentStateSerialisesAsAnEmptyMap(): void
    {
        $entity = new FlowState();
        $entity->setFlowId('flow-1');

        $json = $entity->jsonSerialize();

        self::assertSame(expected: [], actual: $json['state']);

    }//end testAbsentStateSerialisesAsAnEmptyMap()

    /**
     * `updated` serialises as ISO-8601, or null when never written.
     *
     * @return void
     */
    public function testUpdatedSerialisesAsIso8601OrNull(): void
    {
        $entity = new FlowState();
        self::assertNull(actual: $entity->jsonSerialize()['updated']);

        $entity->setUpdated(new DateTime('2026-07-31 08:00:00+00:00'));
        self::assertStringStartsWith(prefix: '2026-07-31T08:00:00', string: $entity->jsonSerialize()['updated']);

    }//end testUpdatedSerialisesAsIso8601OrNull()
}//end class
