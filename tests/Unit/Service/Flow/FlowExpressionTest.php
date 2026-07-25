<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use DateTime;
use OCA\OpenRegister\Service\Flow\FlowExpression;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\Nodes\FilterNode;
use OCA\OpenRegister\Service\Flow\Nodes\WaitNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class FlowExpressionTest extends TestCase
{
    private function data(array $json, int $index = 0, int $count = 1, array $context = []): array
    {
        return FlowExpression::dataFor(FlowItems::item(json: $json), $index, $count, $context);
    }

    public function testAnExpressionReadsTheCurrentItemsRecord(): void
    {
        $result = FlowExpression::evaluate(['var' => 'json.status'], $this->data(['status' => 'open']));

        $this->assertSame('open', $result);
    }

    public function testItemPositionIsInScope(): void
    {
        $data = $this->data(['a' => 1], 2, 5);

        $this->assertSame(2, FlowExpression::evaluate(['var' => 'itemIndex'], $data));
        $this->assertSame(5, FlowExpression::evaluate(['var' => 'itemCount'], $data));
    }

    public function testRunContextIsInScope(): void
    {
        $data = $this->data(['a' => 1], 0, 1, ['trigger' => 'object.created']);

        $this->assertSame('object.created', FlowExpression::evaluate(['var' => 'context.trigger'], $data));
    }

    /**
     * A branch whose condition could not be evaluated must NOT be taken.
     */
    public function testAnUnevaluableConditionIsFalseNotTrue(): void
    {
        $this->assertFalse(FlowExpression::isTrue(['nosuchoperator' => [1]], $this->data([])));
    }

    public function testAMalformedExpressionReturnsNullRatherThanThrowing(): void
    {
        $this->assertNull(FlowExpression::evaluate(['nosuchoperator' => [1]], $this->data([])));
    }

    public function testEmptyResultsAreFalsey(): void
    {
        $this->assertFalse(FlowExpression::isTrue(['var' => 'json.missing'], $this->data([])));
        $this->assertTrue(FlowExpression::isTrue(['var' => 'json.yes'], $this->data(['yes' => true])));
    }

    /** @dataProvider customOperators */
    public function testCustomOperators(array $logic, array $json, mixed $expected): void
    {
        $this->assertSame($expected, FlowExpression::evaluate($logic, $this->data($json)));
    }

    public static function customOperators(): array
    {
        return [
            'upper'    => [['upper' => [['var' => 'json.n']]], ['n' => 'rex'], 'REX'],
            'lower'    => [['lower' => [['var' => 'json.n']]], ['n' => 'REX'], 'rex'],
            'trim'     => [['trim' => [['var' => 'json.n']]], ['n' => '  x  '], 'x'],
            'split'    => [['split' => [['var' => 'json.n'], ',']], ['n' => 'a,b'], ['a', 'b']],
            'join'     => [['join' => [['var' => 'json.n'], '-']], ['n' => ['a', 'b']], 'a-b'],
            'replace'  => [['replace' => [['var' => 'json.n'], 'a', 'b']], ['n' => 'aa'], 'bb'],
            'matches'  => [['matches' => [['var' => 'json.n'], '/^r/']], ['n' => 'rex'], true],
            'unique'   => [['unique' => [['var' => 'json.n']]], ['n' => [1, 1, 2]], [1, 2]],
            'lengthArr' => [['length' => [['var' => 'json.n']]], ['n' => [1, 2, 3]], 3],
            'lengthStr' => [['length' => [['var' => 'json.n']]], ['n' => 'abcd'], 4],
            'coalesce' => [['coalesce' => [['var' => 'json.missing'], 'fallback']], [], 'fallback'],
            'fromJson' => [['fromJson' => [['var' => 'json.n']]], ['n' => '{"a":1}'], ['a' => 1]],
        ];
    }

    public function testDateOperatorsResolve(): void
    {
        $formatted = FlowExpression::evaluate(
            ['dateFormat' => [['var' => 'json.d'], 'Y-m-d']],
            $this->data(['d' => '2026-07-24T10:00:00+00:00'])
        );
        $this->assertSame('2026-07-24', $formatted);

        $added = FlowExpression::evaluate(
            ['dateFormat' => [['dateAdd' => [['var' => 'json.d'], '+1 day']], 'Y-m-d']],
            $this->data(['d' => '2026-07-24T10:00:00+00:00'])
        );
        $this->assertSame('2026-07-25', $added);
    }

    public function testABadDateReturnsNullRatherThanNow(): void
    {
        $this->assertNull(FlowExpression::evaluate(['dateFormat' => [['var' => 'json.d']]], $this->data(['d' => 'not a date'])));
    }

    public function testValidityIsCheckableBeforeStoring(): void
    {
        $this->assertTrue(FlowExpression::isValid(['==' => [['var' => 'json.a'], 1]]));
        $this->assertTrue(FlowExpression::isValid('a literal'));
        $this->assertFalse(FlowExpression::isValid(['nosuchoperator' => [1]]));
    }
}

class FilterNodeTest extends TestCase
{
    private FilterNode $node;

    protected function setUp(): void
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $this->node = new FilterNode($l10n, $this->createMock(IURLGenerator::class));
    }

    private function items(array $records): array
    {
        return array_map(static fn (array $r): array => FlowItems::item(json: $r), $records);
    }

    public function testOnlyMatchingItemsSurvive(): void
    {
        $out = $this->node->execute(
            $this->items([['n' => 1], ['n' => 2], ['n' => 3]]),
            ['condition' => ['>' => [['var' => 'json.n'], 1]]],
            []
        );

        $this->assertSame([2, 3], array_column(array_column($out, 'json'), 'n'));
    }

    /**
     * Provenance must survive the drop: output item 0 came from input item 1.
     */
    public function testSurvivingItemsPointAtTheirOriginalInputIndex(): void
    {
        $out = $this->node->execute(
            $this->items([['n' => 1], ['n' => 2]]),
            ['condition' => ['>' => [['var' => 'json.n'], 1]]],
            []
        );

        $this->assertSame(['item' => 1], $out[0]['pairedItem']);
    }

    public function testMatchingNothingIsALegitimateOutcome(): void
    {
        $out = $this->node->execute(
            $this->items([['n' => 1]]),
            ['condition' => ['>' => [['var' => 'json.n'], 99]]],
            []
        );

        $this->assertSame([], $out);
    }

    public function testAFilterWithNoConditionIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->node->validateConfig([]);
    }

    public function testAMalformedConditionIsRefusedAtSaveTime(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->node->validateConfig(['condition' => ['nosuchoperator' => [1]]]);
    }
}

class WaitNodeTest extends TestCase
{
    private WaitNode $node;

    protected function setUp(): void
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $this->node = new WaitNode($l10n, $this->createMock(IURLGenerator::class));
    }

    public function testTheFirstPassSuspendsTheRun(): void
    {
        $this->expectException(FlowSuspension::class);
        $this->node->execute([], ['for' => '15 minutes'], []);
    }

    public function testTheSuspensionCarriesWhenToResume(): void
    {
        try {
            $this->node->execute([], ['for' => '1 hour'], []);
            $this->fail('expected a suspension');
        } catch (FlowSuspension $e) {
            $this->assertInstanceOf(DateTime::class, $e->getResumeAt());
            $this->assertGreaterThan(time() + 3000, $e->getResumeAt()->getTimestamp());
        }
    }

    /**
     * The marking does not advance past a suspended step, so this node runs
     * again on resume — and must let the items through, not suspend forever.
     */
    public function testResumingPassesItemsThroughRatherThanWaitingAgain(): void
    {
        $items = [FlowItems::item(json: ['a' => 1])];

        $out = $this->node->execute($items, ['for' => '1 hour'], ['resuming' => true]);

        $this->assertSame($items, $out);
    }

    public function testABareNumberIsReadAsSeconds(): void
    {
        try {
            $this->node->execute([], ['for' => '30'], []);
            $this->fail('expected a suspension');
        } catch (FlowSuspension $e) {
            $this->assertLessThanOrEqual(time() + 31, $e->getResumeAt()->getTimestamp());
            $this->assertGreaterThan(time() + 25, $e->getResumeAt()->getTimestamp());
        }
    }

    public function testAnAbsoluteMomentIsAccepted(): void
    {
        try {
            $this->node->execute([], ['until' => '2030-01-01T00:00:00+00:00'], []);
            $this->fail('expected a suspension');
        } catch (FlowSuspension $e) {
            $this->assertSame('2030-01-01', $e->getResumeAt()->format('Y-m-d'));
        }
    }

    /**
     * Suspending forever on a time that will never arrive is worse than
     * carrying on.
     */
    public function testAnUnreadableTimeAtRunTimePassesThroughRatherThanHanging(): void
    {
        $items = [FlowItems::item(json: ['a' => 1])];

        $this->assertSame($items, $this->node->execute($items, ['for' => 'whenever'], []));
    }

    public function testAWaitWithNoTimeIsRefusedAtSaveTime(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->node->validateConfig([]);
    }
}
