<?php

/**
 * Unit tests for FlowValueTemplate.
 *
 * The rule every node relies on to turn authored config into values from the
 * item. Its sharp edge is TYPE: a filter or a counter is compared, not printed,
 * and `"7"` is not `7`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowValueTemplate
 */
final class FlowValueTemplateTest extends TestCase
{

    /**
     * A value that is EXACTLY one placeholder keeps the resolved type.
     *
     * This is the property that matters wherever the value is compared rather
     * than printed — a filter, a counter, a capacity. Stringifying a number
     * here turns `retries < 3` into a string comparison.
     *
     * @return void
     */
    public function testAWholePlaceholderKeepsItsType(): void
    {
        $json = ['count' => 7, 'tags' => ['a', 'b'], 'ok' => true];

        $this->assertSame(7, FlowValueTemplate::render('{{count}}', $json));
        $this->assertSame(['a', 'b'], FlowValueTemplate::render('{{tags}}', $json));
        $this->assertTrue(FlowValueTemplate::render('{{ok}}', $json));

    }//end testAWholePlaceholderKeepsItsType()


    /**
     * An inline placeholder is substituted and stringified.
     *
     * @return void
     */
    public function testAnInlinePlaceholderIsStringified(): void
    {
        $out = FlowValueTemplate::render('retry {{count}} of {{max}}', ['count' => 2, 'max' => 3]);

        $this->assertSame('retry 2 of 3', $out);

    }//end testAnInlinePlaceholderIsStringified()


    /**
     * Dotted paths walk nested records, including numeric list indices.
     *
     * @return void
     */
    public function testDottedPathsWalkNestedRecords(): void
    {
        $json = ['queue' => ['body' => [['number' => 410]]]];

        $this->assertSame(410, FlowValueTemplate::render('{{queue.body.0.number}}', $json));

    }//end testDottedPathsWalkNestedRecords()


    /**
     * An absent path resolves to null, not to the literal placeholder.
     *
     * Returning the placeholder text would put `{{missing}}` into a filter or a
     * URL, where it reads as a value rather than as an absence — which is how
     * `/repos//issues` happens.
     *
     * @return void
     */
    public function testAnAbsentPathResolvesToNull(): void
    {
        $this->assertNull(FlowValueTemplate::render('{{missing}}', ['a' => 1]));
        $this->assertSame('x=', FlowValueTemplate::render('x={{missing}}', ['a' => 1]));

    }//end testAnAbsentPathResolvesToNull()


    /**
     * Maps and lists are rendered recursively; non-strings pass through.
     *
     * @return void
     */
    public function testStructuresAreRenderedRecursively(): void
    {
        $out = FlowValueTemplate::render(
            ['holder' => '{{ref}}', 'limit' => 50, 'nested' => ['deep' => '{{ref}}']],
            ['ref' => 'hydra-410']
        );

        $this->assertSame('hydra-410', $out['holder']);
        $this->assertSame(50, $out['limit']);
        $this->assertSame('hydra-410', $out['nested']['deep']);

    }//end testStructuresAreRenderedRecursively()
}//end class
