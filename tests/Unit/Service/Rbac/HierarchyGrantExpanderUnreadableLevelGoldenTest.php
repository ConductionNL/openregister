<?php

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\HierarchyDescender;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use OCA\OpenRegister\Service\Rbac\HierarchyLevel;
use OCP\Constants;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins what happens when a level of the hierarchy cannot be read: the levels above it are
 * granted, nothing below it is, and one error names the schema, the depth and the cause.
 */
class HierarchyGrantExpanderUnreadableLevelGoldenTest extends TestCase {

	/**
	 * A descender over child => parent whose read of a level containing $failOn throws.
	 *
	 * @param array<string, string> $childToParent Child uuid => parent uuid.
	 * @param string|null           $failOn        A parent whose level throws, or null.
	 *
	 * @return HierarchyDescender
	 */
	private function descender(array $childToParent, ?string $failOn): HierarchyDescender {
		$double = $this->getMockBuilder(HierarchyDescender::class)
			->disableOriginalConstructor()
			->onlyMethods(['hierarchicalTables', 'childrenOf'])
			->getMock();
		$double->method('hierarchicalTables')->willReturn(
			[
				[
					'table' => 'openregister_table_1_7',
					'parentColumn' => 'parent_case',
					'maxDepth' => 5,
					'verbs' => [],
					'schemaId' => 7,
				],
			]
		);
		$double->method('childrenOf')->willReturnCallback(
			static function (HierarchyLevel $level, array $parentUuids) use ($childToParent, $failOn): array {
				if ($failOn !== null && in_array($failOn, $parentUuids, true) === true) {
					throw new RuntimeException('level unreadable');
				}

				return array_filter($childToParent, static fn (string $parent): bool => in_array($parent, $parentUuids, true));
			}
		);

		return $double;
	}//end descender()

	/**
	 * A readable chain is granted to the end and logs nothing.
	 *
	 * @return void
	 */
	public function testAReadableChainIsGrantedAndLogsNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$read = Constants::PERMISSION_READ;

		$expander = new HierarchyGrantExpander(descender: $this->descender(['child' => 'root', 'grand' => 'child'], null), logger: $logger);

		$this->assertSame(['root' => $read, 'child' => $read, 'grand' => $read], $expander->expand(granted: ['root' => $read])['granted']);
	}//end testAReadableChainIsGrantedAndLogsNothing()

	/**
	 * An unreadable level stops the descent and logs one error with the schema, depth and cause.
	 *
	 * @return void
	 */
	public function testAnUnreadableLevelStopsTheDescentAndLogsOnce(): void {
		$calls  = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context) use (&$calls): void {
				unset($context['line']);
				$context['file'] = basename($context['file']);
				$calls[] = [$message, $context];
			}
		);
		$read = Constants::PERMISSION_READ;

		$expander = new HierarchyGrantExpander(
			descender: $this->descender(['child' => 'root', 'grand' => 'child', 'great' => 'grand'], 'child'),
			logger: $logger
		);

		$this->assertSame(
			['granted' => ['root' => $read, 'child' => $read], 'sources' => ['child' => 'root']],
			$expander->expand(granted: ['root' => $read])
		);
		$this->assertSame(
			[
				[
					'[HierarchyGrantExpander] A level of the hierarchy could not be read; the descent stops here',
					['file' => 'HierarchyGrantExpander.php', 'schemaId' => 7, 'depth' => 1, 'exception' => 'level unreadable'],
				],
			],
			$calls
		);
	}//end testAnUnreadableLevelStopsTheDescentAndLogsOnce()
}//end class
