<?php

declare(strict_types=1);

/**
 * Golden test for the widget bodies HtmlReportWriter renders.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Reporting
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Reporting;

use OCA\OpenRegister\Search\Service\Reporting\HtmlReportWriter;
use PHPUnit\Framework\TestCase;

/**
 * Pins which body each widget type and data shape renders.
 */
class HtmlReportWriterBodyGoldenTest extends TestCase {

	/**
	 * Render one widget and return the document.
	 *
	 * @param array<string, mixed>      $widget The widget descriptor.
	 * @param array<string, mixed>|null $data   The resolved data.
	 *
	 * @return string The HTML of the report grid, without the stylesheet.
	 */
	private function render(array $widget, ?array $data): string {
		$html = (new HtmlReportWriter())->write(['titel' => 'T'], [['widget' => $widget, 'data' => $data]]);

		return (string)strstr($html, '<main class="report-grid">');
	}//end render()

	/**
	 * A stats widget renders its metric list.
	 *
	 * @return void
	 */
	public function testAStatsWidgetRendersItsMetrics(): void {
		$html = $this->render(['type' => 'stats'], ['count' => 3, 'sum' => 9]);

		$this->assertStringContainsString('<dl class="widget-stats"><dt>count</dt><dd>3</dd><dt>sum</dt><dd>9</dd></dl>', $html);
		$this->assertStringNotContainsString('widget-headline', $html);
	}//end testAStatsWidgetRendersItsMetrics()

	/**
	 * A widget without a type is a kpi and renders its headline from the value field.
	 *
	 * @return void
	 */
	public function testAnUntypedWidgetRendersTheHeadline(): void {
		$html = $this->render(['options' => ['valueField' => 'total']], ['total' => 12, 'count' => 3]);

		$this->assertStringContainsString('<div class="widget-headline">12</div>', $html);
		$this->assertStringNotContainsString('<dl class="widget-stats">', $html);
	}//end testAnUntypedWidgetRendersTheHeadline()

	/**
	 * Grouped data renders a table whatever the type.
	 *
	 * @return void
	 */
	public function testGroupedDataRendersATable(): void {
		$html = $this->render(['type' => 'stats'], ['groups' => [['key' => 'a', 'value' => 1]]]);

		$this->assertStringContainsString('<tbody><tr><td>a</td><td class="num">1</td></tr></tbody>', $html);
		$this->assertStringNotContainsString('<dl class="widget-stats">', $html);
		$this->assertStringNotContainsString('widget-headline', $html);
	}//end testGroupedDataRendersATable()

	/**
	 * A widget whose data did not resolve says so.
	 *
	 * @return void
	 */
	public function testUnresolvedDataRendersTheError(): void {
		$html = $this->render(['type' => 'stats'], null);

		$this->assertStringContainsString('widget-error', $html);
	}//end testUnresolvedDataRendersTheError()
}//end class
