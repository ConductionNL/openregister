<?php

/**
 * EveryFlow paging tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair\Support
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair\Support;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\Flow\FlowSelection;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Repair\Support\EveryFlow;
use PHPUnit\Framework\TestCase;

final class EveryFlowTest extends TestCase {
	public function testReadsPagesOf500UntilAShortPage(): void {
		$full = array_fill(0, 500, new Flow());
		$short = [new Flow(), new Flow()];
		$asked = [];

		$mapper = $this->createMock(FlowMapperInterface::class);
		$mapper->method('findAllFlows')->willReturnCallback(
			function (FlowSelection $selection, ListQuery $query) use (&$asked, $full, $short): array {
				$asked[] = [$query->limit, $query->offset];
				return $query->offset === 0 ? $full : $short;
			}
		);

		$flows = (new EveryFlow(flows: $mapper))->read();

		self::assertCount(502, $flows);
		self::assertSame([[500, 0], [500, 500]], $asked);
	}//end testReadsPagesOf500UntilAShortPage()

	public function testAnEmptyInstanceIsOneRead(): void {
		$mapper = $this->createMock(FlowMapperInterface::class);
		$mapper->expects(self::once())->method('findAllFlows')->willReturn([]);

		self::assertSame([], (new EveryFlow(flows: $mapper))->read());
	}//end testAnEmptyInstanceIsOneRead()
}//end class
