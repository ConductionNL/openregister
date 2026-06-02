<?php

/**
 * Unit tests for the PaginatedResult list-response envelope normalizer.
 *
 * Covers the additive, backward-compatible normalization that lets a
 * provider return either a flat array or an envelope while the
 * controller always emits canonical `{items, total, nextCursor}`:
 *  - flat list → total = count, nextCursor null (contacts/deck shape)
 *  - `{items, total, nextCursor}` envelope → passed through (email shape)
 *  - legacy `{results, total}` envelope → coerced to items
 *  - non-array / scalar → empty envelope
 *  - toArray() mirrors items under results for frontend back-compat
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-19
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Service\Integration\PaginatedResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PaginatedResult.
 */
class PaginatedResultTest extends TestCase
{

    public function testFlatArrayDefaultsTotalToCount(): void
    {
        $result = PaginatedResult::fromMixed([['id' => 'a'], ['id' => 'b'], ['id' => 'c']]);

        $this->assertCount(3, $result->items);
        $this->assertSame(3, $result->total);
        $this->assertNull($result->nextCursor);
    }//end testFlatArrayDefaultsTotalToCount()

    public function testFullEnvelopeIsPreserved(): void
    {
        $result = PaginatedResult::fromMixed(
            [
                'items'      => [['id' => 'a']],
                'total'      => 99,
                'nextCursor' => '25',
            ]
        );

        $this->assertCount(1, $result->items);
        $this->assertSame(99, $result->total);
        $this->assertSame('25', $result->nextCursor);
    }//end testFullEnvelopeIsPreserved()

    public function testResultsKeyedEnvelopeIsCoercedToItems(): void
    {
        $result = PaginatedResult::fromMixed(
            [
                'results' => [['id' => 'x'], ['id' => 'y']],
                'total'   => 2,
            ]
        );

        $this->assertSame([['id' => 'x'], ['id' => 'y']], $result->items);
        $this->assertSame(2, $result->total);
        $this->assertNull($result->nextCursor);
    }//end testResultsKeyedEnvelopeIsCoercedToItems()

    public function testEnvelopeWithoutTotalFallsBackToItemCount(): void
    {
        $result = PaginatedResult::fromMixed(['items' => [['id' => 'a'], ['id' => 'b']]]);

        $this->assertSame(2, $result->total);
    }//end testEnvelopeWithoutTotalFallsBackToItemCount()

    public function testNumericNextCursorIsCastToString(): void
    {
        $result = PaginatedResult::fromMixed(['items' => [], 'total' => 0, 'nextCursor' => 7]);

        $this->assertSame('7', $result->nextCursor);
    }//end testNumericNextCursorIsCastToString()

    public function testNonArrayYieldsEmptyEnvelope(): void
    {
        $result = PaginatedResult::fromMixed('not-an-array');

        $this->assertSame([], $result->items);
        $this->assertSame(0, $result->total);
        $this->assertNull($result->nextCursor);
    }//end testNonArrayYieldsEmptyEnvelope()

    public function testPaginatedResultInstanceIsReturnedUnchanged(): void
    {
        $original = new PaginatedResult(items: [['id' => 'a']], total: 5, nextCursor: '1');

        $this->assertSame($original, PaginatedResult::fromMixed($original));
    }//end testPaginatedResultInstanceIsReturnedUnchanged()

    public function testToArrayMirrorsItemsUnderResults(): void
    {
        $result = new PaginatedResult(items: [['id' => 'a']], total: 1, nextCursor: null);
        $array  = $result->toArray();

        $this->assertSame($array['items'], $array['results']);
        $this->assertSame(1, $array['total']);
        $this->assertArrayHasKey('nextCursor', $array);
        $this->assertNull($array['nextCursor']);
    }//end testToArrayMirrorsItemsUnderResults()
}//end class
