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
final class FlowValueTemplateTest extends TestCase {

	/**
	 * A value that is EXACTLY one placeholder keeps the resolved type.
	 *
	 * This is the property that matters wherever the value is compared rather
	 * than printed — a filter, a counter, a capacity. Stringifying a number
	 * here turns `retries < 3` into a string comparison.
	 *
	 * @return void
	 */
	public function testAWholePlaceholderKeepsItsType(): void {
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
	public function testAnInlinePlaceholderIsStringified(): void {
		$out = FlowValueTemplate::render('retry {{count}} of {{max}}', ['count' => 2, 'max' => 3]);

		$this->assertSame('retry 2 of 3', $out);

	}//end testAnInlinePlaceholderIsStringified()

	/**
	 * Dotted paths walk nested records, including numeric list indices.
	 *
	 * @return void
	 */
	public function testDottedPathsWalkNestedRecords(): void {
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
	public function testAnAbsentPathResolvesToNull(): void {
		$this->assertNull(FlowValueTemplate::render('{{missing}}', ['a' => 1]));
		$this->assertSame('x=', FlowValueTemplate::render('x={{missing}}', ['a' => 1]));

	}//end testAnAbsentPathResolvesToNull()

	/**
	 * Maps and lists are rendered recursively; non-strings pass through.
	 *
	 * @return void
	 */
	public function testStructuresAreRenderedRecursively(): void {
		$out = FlowValueTemplate::render(
			['holder' => '{{ref}}', 'limit' => 50, 'nested' => ['deep' => '{{ref}}']],
			['ref' => 'hydra-410']
		);

		$this->assertSame('hydra-410', $out['holder']);
		$this->assertSame(50, $out['limit']);
		$this->assertSame('hydra-410', $out['nested']['deep']);

	}//end testStructuresAreRenderedRecursively()

	/**
	 * `renderTracked()` renders identically AND names what came out empty.
	 *
	 * `render()` cannot fail, which is right for a written field and wrong for
	 * a question: an empty filter value matches nothing and the step reports
	 * success. So the tracked variant reports the paths, and leaves the
	 * decision to the caller.
	 *
	 * @return void
	 */
	public function testRenderTrackedNamesTheEmptyPlaceholders(): void {
		$out = FlowValueTemplate::renderTracked(
			['name' => 'ZZ issue-lock {{repo}}', 'cutoff' => '{{cutoff}}'],
			['flowId' => 'f-1', 'scheduledAt' => '2026-08-02T15:00:00+00:00']
		);

		$this->assertSame('ZZ issue-lock ', $out['value']['name']);
		$this->assertNull($out['value']['cutoff']);
		$this->assertSame(['repo', 'cutoff'], $out['unresolved']);

	}//end testRenderTrackedNamesTheEmptyPlaceholders()

	/**
	 * Null and the empty string count as empty; `false`, `0` and `[]` do not.
	 *
	 * A computed field whose expression failed is PRESENT and null, and renders
	 * exactly as an absent path does — so treating them differently would only
	 * let one of them through. `false` and `0` are answers, and a whole-value
	 * placeholder keeps their type, so they are real values.
	 *
	 * @return void
	 */
	public function testOnlyValuesThatRenderAsNothingCountAsEmpty(): void {
		$out = FlowValueTemplate::renderTracked(
			[
				'computedNull' => '{{cutoff}}',
				'blank' => '{{empty}}',
				'flag' => '{{off}}',
				'count' => '{{zero}}',
				'list' => '{{none}}',
			],
			['cutoff' => null, 'empty' => '', 'off' => false, 'zero' => 0, 'none' => []]
		);

		$this->assertSame(['cutoff', 'empty'], $out['unresolved']);
		$this->assertFalse($out['value']['flag']);
		$this->assertSame(0, $out['value']['count']);
		$this->assertSame([], $out['value']['list']);

	}//end testOnlyValuesThatRenderAsNothingCountAsEmpty()

	/**
	 * A fully resolvable structure reports nothing unresolved.
	 *
	 * The positive control for the two above: a tracker that flagged everything
	 * would satisfy them both and refuse every real read.
	 *
	 * @return void
	 */
	public function testRenderTrackedReportsNothingWhenEverythingResolves(): void {
		$out = FlowValueTemplate::renderTracked(
			['name' => 'ZZ issue-lock', '@self' => ['created' => ['lt' => '{{cutoff}}']]],
			['cutoff' => '2026-08-02 14:30:00']
		);

		$this->assertSame([], $out['unresolved']);
		$this->assertSame('2026-08-02 14:30:00', $out['value']['@self']['created']['lt']);

	}//end testRenderTrackedReportsNothingWhenEverythingResolves()
}//end class
