<?php

/**
 * Regression: a `set` / `compute` PATH must be templatable.
 *
 * `SetFieldsNode::execute()` rendered the value and used the key raw, so
 * `{"set": {"stages.{{stage}}.status": "done"}}` created a key literally named
 * `stages.{{stage}}.status`. Nothing failed. The flow ran, the item came out,
 * and the position the author meant to write was never touched —
 * indistinguishable from success until something downstream read the place that
 * stayed empty. Identical failure mode to the dotted-path bug fixed in #2244.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\Nodes\SetFieldsNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Locks templated-path rendering, and the two refusals around it.
 */
class SetFieldsNodeTemplatedPathTest extends TestCase
{

    private SetFieldsNode $node;

    protected function setUp(): void
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static function (string $text, array $parameters=[]): string {
                return vsprintf($text, $parameters);
            }
        );

        $this->node = new SetFieldsNode($l10n, $this->createMock(IURLGenerator::class));
    }//end setUp()

    /**
     * Run one record through the node and return the resulting json.
     *
     * @param array<string,mixed> $config Step configuration.
     * @param array<string,mixed> $record The item's json.
     *
     * @return array<string,mixed> The resulting json.
     */
    private function apply(array $config, array $record): array
    {
        $out = $this->node->execute([FlowItems::item(json: $record)], $config, []);

        return (array) $out[0][FlowItems::JSON];
    }//end apply()

    /**
     * A placeholder in the path decides WHERE the value is written.
     *
     * @return void
     */
    public function testAPlaceholderInThePathDecidesThePosition(): void
    {
        $json = $this->apply(
            ['set' => ['stages.{{stage}}.status' => 'done']],
            ['stage' => 'review']
        );

        $this->assertSame(['review' => ['status' => 'done']], $json['stages']);

        // The literal key is what used to be created instead.
        $this->assertArrayNotHasKey('stages.{{stage}}.status', $json);
    }//end testAPlaceholderInThePathDecidesThePosition()

    /**
     * A whole path that is one placeholder still names one field.
     *
     * @return void
     */
    public function testAWholePathMayBeASinglePlaceholder(): void
    {
        $json = $this->apply(
            ['set' => ['{{key}}' => 'v']],
            ['key' => 'chosen']
        );

        $this->assertSame('v', $json['chosen']);
        $this->assertArrayNotHasKey('{{key}}', $json);
    }//end testAWholePathMayBeASinglePlaceholder()

    /**
     * Two items writing the same configured path land in different positions.
     *
     * This is the whole point: one configuration, a position the run decides.
     *
     * @return void
     */
    public function testTwoItemsWriteToDifferentPositionsFromOneConfiguration(): void
    {
        $out = $this->node->execute(
            [
                FlowItems::item(json: ['stage' => 'build']),
                FlowItems::item(json: ['stage' => 'ship']),
            ],
            ['set' => ['stages.{{stage}}.ok' => true]],
            []
        );

        $this->assertTrue($out[0][FlowItems::JSON]['stages']['build']['ok']);
        $this->assertTrue($out[1][FlowItems::JSON]['stages']['ship']['ok']);
    }//end testTwoItemsWriteToDifferentPositionsFromOneConfiguration()

    /**
     * A numeric placeholder is usable as a segment.
     *
     * @return void
     */
    public function testANumericPlaceholderIsUsableAsASegment(): void
    {
        $json = $this->apply(
            ['set' => ['slots.{{n}}' => 'taken']],
            ['n' => 2]
        );

        $this->assertSame('taken', $json['slots'][2]);
    }//end testANumericPlaceholderIsUsableAsASegment()

    /**
     * A path with no placeholder is completely unaffected.
     *
     * @return void
     */
    public function testAPathWithoutAPlaceholderIsUnchanged(): void
    {
        $json = $this->apply(['set' => ['a.b' => 1, 'flat' => 2]], []);

        $this->assertSame(1, $json['a']['b']);
        $this->assertSame(2, $json['flat']);
    }//end testAPathWithoutAPlaceholderIsUnchanged()

    /**
     * A rendered segment may NOT introduce a dot.
     *
     * Otherwise item data would decide the SHAPE of the path, not just the
     * name of one segment — a data-controlled write position.
     *
     * @return void
     */
    public function testARenderedSegmentMayNotIntroduceNesting(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('contains a dot');

        $this->apply(
            ['set' => ['stages.{{stage}}.status' => 'done']],
            ['stage' => 'a.b']
        );
    }//end testARenderedSegmentMayNotIntroduceNesting()

    /**
     * A literal dot in the configured path still nests, as it always has.
     *
     * The refusal above is about RENDERED dots only.
     *
     * @return void
     */
    public function testALiteralDotInTheConfiguredPathStillNests(): void
    {
        $json = $this->apply(
            ['set' => ['a.{{k}}.c' => 'v']],
            ['k' => 'b']
        );

        $this->assertSame('v', $json['a']['b']['c']);
    }//end testALiteralDotInTheConfiguredPathStillNests()

    /**
     * A segment that resolves to nothing is refused, not written to "".
     *
     * @return void
     */
    public function testASegmentThatResolvesToNothingIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('resolved to nothing');

        $this->apply(
            ['set' => ['stages.{{missing}}.status' => 'done']],
            ['stage' => 'review']
        );
    }//end testASegmentThatResolvesToNothingIsRefused()

    /**
     * A segment resolving to a container cannot name a field.
     *
     * @return void
     */
    public function testASegmentResolvingToAContainerIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('cannot name a field');

        $this->apply(
            ['set' => ['{{bag}}' => 'v']],
            ['bag' => ['a' => 1]]
        );
    }//end testASegmentResolvingToAContainerIsRefused()

    /**
     * `compute` gets the same path treatment as `set`.
     *
     * @return void
     */
    public function testComputeAlsoTemplatesItsPath(): void
    {
        $json = $this->apply(
            [
                'compute' => [
                    'totals.{{bucket}}' => ['+' => [['var' => 'json.n'], 1]],
                ],
            ],
            ['bucket' => 'nl', 'n' => 4]
        );

        $this->assertSame(5, $json['totals']['nl']);
        $this->assertArrayNotHasKey('totals.{{bucket}}', $json);
    }//end testComputeAlsoTemplatesItsPath()

    /**
     * `compute` also writes nested rather than flat.
     *
     * @return void
     */
    public function testComputeWritesNestedRatherThanFlat(): void
    {
        $json = $this->apply(
            ['compute' => ['a.b' => ['+' => [1, 1]]]],
            []
        );

        $this->assertSame(2, $json['a']['b']);
        $this->assertArrayNotHasKey('a.b', $json);
    }//end testComputeWritesNestedRatherThanFlat()

    /**
     * A computed value can build on one `set` just wrote at a templated path.
     *
     * @return void
     */
    public function testComputeCanBuildOnATemplatedSet(): void
    {
        $json = $this->apply(
            [
                'set'     => ['counts.{{k}}' => 1],
                'compute' => ['doubled' => ['*' => [['var' => 'json.counts.nl'], 2]]],
            ],
            ['k' => 'nl']
        );

        $this->assertSame(1, $json['counts']['nl']);
        $this->assertSame(2, $json['doubled']);
    }//end testComputeCanBuildOnATemplatedSet()

    /**
     * An empty configured path is refused when the flow is SAVED.
     *
     * @return void
     */
    public function testAnEmptySetPathIsRefusedAtSaveTime(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('empty field path');

        $this->node->validateConfig(['set' => ['' => 'v']]);
    }//end testAnEmptySetPathIsRefusedAtSaveTime()

    /**
     * A blank `compute` path is refused when the flow is SAVED.
     *
     * @return void
     */
    public function testABlankComputePathIsRefusedAtSaveTime(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('empty field path');

        $this->node->validateConfig(['compute' => ['   ' => ['+' => [1, 1]]]]);
    }//end testABlankComputePathIsRefusedAtSaveTime()

    /**
     * An ordinary configuration still validates.
     *
     * @return void
     */
    public function testAnOrdinaryConfigurationStillValidates(): void
    {
        $this->node->validateConfig(['set' => ['stages.{{stage}}.status' => 'done']]);

        $this->addToAssertionCount(1);
    }//end testAnOrdinaryConfigurationStillValidates()
}//end class
