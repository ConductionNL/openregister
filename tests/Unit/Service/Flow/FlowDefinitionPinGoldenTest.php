<?php

/**
 * Golden pins for FlowDefinitionPin: canonical form, pin outcomes, lookups and their log lines.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowDefinition;
use OCA\OpenRegister\Db\FlowDefinitionMapper;
use OCA\OpenRegister\Service\Flow\FlowDefinitionPin;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every return value and every log line, exactly as recorded on the old code.
 */
class FlowDefinitionPinGoldenTest extends TestCase {

	/**
	 * The mapper double.
	 *
	 * @var FlowDefinitionMapper&MockObject
	 */
	private FlowDefinitionMapper&MockObject $mapper;

	/**
	 * Warnings logged so far.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * The pin under test.
	 *
	 * @var FlowDefinitionPin
	 */
	private FlowDefinitionPin $pin;

	/**
	 * Build the pin with a mapper double and a capturing logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(FlowDefinitionMapper::class);
		$this->warnings = [];

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message): void {
				$this->warnings[] = (string)$message;
			}
		);

		$this->pin = new FlowDefinitionPin(mapper: $this->mapper, logger: $logger);
	}//end setUp()

	/**
	 * A flow document with unsorted keys, lists and a key that is not pinned.
	 *
	 * @return array<string, mixed> The flow.
	 */
	private function flow(): array {
		return [
			'owner' => 'alice',
			'edges' => [['to' => 'b', 'from' => 'a']],
			'nodes' => [
				['type' => 't', 'id' => 'b', 'config' => ['z' => 1, 'a' => ['y' => 2, 'x' => [3, 1]]]],
				['id' => 'a'],
			],
			'executionMode' => 'sync',
		];
	}//end flow()

	/**
	 * The canonical form sorts maps, keeps lists and nulls missing keys.
	 *
	 * @return void
	 */
	public function testCanonicalise(): void {
		$json = '{"edges":[{"from":"a","to":"b"}],"executionMode":"sync","limits":null,'
			. '"nodes":[{"config":{"a":{"x":[3,1],"y":2},"z":1},"id":"b","type":"t"},{"id":"a"}]}';

		$this->assertSame(['hash' => hash('sha256', $json), 'json' => $json], $this->pin->canonicalise(flow: $this->flow()));
		$empty = '{"edges":null,"executionMode":null,"limits":null,"nodes":null}';
		$this->assertSame(['hash' => hash('sha256', $empty), 'json' => $empty], $this->pin->canonicalise(flow: []));
		$this->assertNull($this->pin->canonicalise(flow: ['nodes' => ["\xB1"]]));
	}//end testCanonicalise()

	/**
	 * Pinning: unencodable, storage failure, nothing stored, stored.
	 *
	 * @return void
	 */
	public function testPinOutcomes(): void {
		$this->assertNull($this->pin->pin(flow: ['nodes' => ["\xB1"]], flowId: 'f-1'));

		$canonical = $this->pin->canonicalise(flow: $this->flow());
		$this->mapper->expects($this->exactly(3))->method('store')
			->with($this->callback(static fn (FlowDefinition $d): bool => [$d->getHash(), $d->getDefinition(), $d->getFlowUuid()] === [$canonical['hash'], $canonical['json'], 'f-2']))
			->willReturnOnConsecutiveCalls(
				$this->throwException(new RuntimeException('disk full')),
				null,
				new FlowDefinition()
			);

		$this->assertNull($this->pin->pin(flow: $this->flow(), flowId: 'f-2'));
		$this->assertNull($this->pin->pin(flow: $this->flow(), flowId: 'f-2'));
		$this->assertSame($canonical['hash'], $this->pin->pin(flow: $this->flow(), flowId: 'f-2'));

		$this->assertSame(
			[
				'[FlowDefinitionPin] Could not encode flow "f-1"; the run will be unpinned.',
				'[FlowDefinitionPin] Could not store the definition for flow "f-2"; the run will be unpinned: disk full',
			],
			$this->warnings
		);
	}//end testPinOutcomes()

	/**
	 * Lookups: blank hash, lookup failure, missing row, unreadable row, readable row.
	 *
	 * @return void
	 */
	public function testGraphForOutcomes(): void {
		$unreadable = new FlowDefinition();
		$unreadable->setDefinition('not json');
		$readable = new FlowDefinition();
		$readable->setDefinition('{"nodes":[{"id":"a"}]}');

		$this->mapper->expects($this->exactly(4))->method('findByHash')
			->willReturnOnConsecutiveCalls(
				$this->throwException(new RuntimeException('db down')),
				null,
				$unreadable,
				$readable
			);

		$this->assertNull($this->pin->graphFor(hash: null));
		$this->assertNull($this->pin->graphFor(hash: '  '));
		$this->assertNull($this->pin->graphFor(hash: 'h1'));
		$this->assertNull($this->pin->graphFor(hash: 'h2'));
		$this->assertNull($this->pin->graphFor(hash: 'h3'));
		$this->assertSame(['nodes' => [['id' => 'a']]], $this->pin->graphFor(hash: 'h4'));

		$this->assertSame(
			[
				'[FlowDefinitionPin] Definition "h1" is missing.',
				'[FlowDefinitionPin] Definition "h2" is missing.',
				'[FlowDefinitionPin] Definition "h3" is unreadable.',
			],
			$this->warnings
		);
	}//end testGraphForOutcomes()
}//end class
