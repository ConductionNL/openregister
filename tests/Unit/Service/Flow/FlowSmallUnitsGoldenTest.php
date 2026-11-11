<?php

/**
 * Characterisation tests for two small Flow readers no other test pins.
 *
 * Pins `ServiceHoursDeclaration::isoWeekday()` (ints and digit strings read
 * as ISO numbers 1–7, names in any case and padding, everything else no
 * weekday) and `FlowVersionService::publishedGraphOf()` (no version, a
 * readable graph, an unresolvable one, and a failing read that is logged).
 * Both are private, so they are reached by reflection.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapper;
use OCA\OpenRegister\Service\Flow\FlowDefinitionPin;
use OCA\OpenRegister\Service\Flow\FlowLifecycleGuard;
use OCA\OpenRegister\Service\Flow\FlowSemanticVersion;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndex;
use OCA\OpenRegister\Service\Flow\FlowVersionGraphs;
use OCA\OpenRegister\Service\Flow\FlowVersionRows;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\Flow\Timer\ServiceHoursDeclaration;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden tests for isoWeekday() and publishedGraphOf().
 */
class FlowSmallUnitsGoldenTest extends TestCase {

	/**
	 * Every weekday spelling reads as before.
	 *
	 * @return void
	 */
	public function testIsoWeekdayForEverySpelling(): void {
		$method = new ReflectionMethod(ServiceHoursDeclaration::class, 'isoWeekday');
		$cases  = [
			'int monday'      => [1, 1],
			'int sunday'      => [7, 7],
			'int zero'        => [0, null],
			'int eight'       => [8, null],
			'digit string'    => ['3', 3],
			'padded digit'    => [' 3', null],
			'zero string'     => ['0', null],
			'long digits'     => ['0005', 5],
			'negative string' => ['-1', null],
			'float'           => [3.0, null],
			'bool'            => [true, null],
			'null'            => [null, null],
			'array'           => [[1], null],
		];

		foreach ($cases as $label => [$weekday, $expected]) {
			$this->assertSame($expected, $method->invoke(null, $weekday), $label);
		}

		$this->assertIsInt($method->invoke(null, 'Monday'), 'a name in any case');
		$this->assertSame($method->invoke(null, 'monday'), $method->invoke(null, ' MONDAY '), 'case and padding ignored');
		$this->assertNull($method->invoke(null, 'funday'), 'not a weekday');
	}//end testIsoWeekdayForEverySpelling()

	/**
	 * A service whose pin answers the graph read like this.
	 *
	 * @param array<string, mixed>|null $graph  What the pin returns.
	 * @param bool                      $throws Whether the read fails.
	 * @param LoggerInterface           $logger The logger.
	 *
	 * @return FlowVersionService
	 */
	private function service(?array $graph, bool $throws, LoggerInterface $logger): FlowVersionService {
		$pin = $this->createMock(FlowDefinitionPin::class);
		if ($throws === true) {
			$pin->method('graphFor')->willThrowException(new RuntimeException('pruned'));
		} else {
			$pin->method('graphFor')->willReturn($graph);
		}

		return new FlowVersionService(
			new FlowVersionRows($this->createMock(FlowVersionMapper::class), $this->createMock(FlowMapper::class), $this->createMock(IDBConnection::class), $logger),
			new FlowVersionGraphs($pin, $this->createMock(FlowLifecycleGuard::class), $this->createMock(FlowTriggerIndex::class), new FlowSemanticVersion())
		);
	}//end service()

	/**
	 * The previously published graph, as read through the pin.
	 *
	 * @return void
	 */
	public function testPublishedGraphOf(): void {
		$method  = new ReflectionMethod(FlowVersionService::class, 'publishedGraphOf');
		$version = new FlowVersion();
		$version->setDefinitionHash('h-1');
		$quiet = $this->createMock(LoggerInterface::class);
		$quiet->expects($this->never())->method('warning');

		$this->assertNull($method->invoke($this->service(['nodes' => []], false, $quiet), null));
		$this->assertSame(['nodes' => []], $method->invoke($this->service(['nodes' => []], false, $quiet), $version));
		$this->assertNull($method->invoke($this->service(null, false, $quiet), $version));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[FlowVersionService] Could not read the previously published graph; the semantic version starts again: pruned',
			$this->callback(static fn (array $context): bool => array_keys($context) === ['file', 'line'])
		);
		$this->assertNull($method->invoke($this->service(null, true, $logger), $version));
	}//end testPublishedGraphOf()
}//end class
