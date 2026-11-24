<?php

/**
 * SaveObjectRuntime carries the Core port for the writing flow run.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Kernel\Contracts\Core\CurrentFlowRunInterface;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowRunFrame;
use OCA\OpenRegister\Service\Object\CacheHandlerInterface;
use OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRuntime;
use PHPUnit\Framework\TestCase;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRuntime
 */
class SaveObjectRuntimeTest extends TestCase {

	public function testTheRunContextIsTypedToTheCorePort(): void {
		$type = (new ReflectionProperty(SaveObjectRuntime::class, 'runContext'))->getType();

		$this->assertInstanceOf(ReflectionNamedType::class, $type);
		$this->assertSame(CurrentFlowRunInterface::class, $type->getName());
		$this->assertTrue($type->allowsNull());
	}//end testTheRunContextIsTypedToTheCorePort()

	public function testAnyImplementationOfThePortIsCarried(): void {
		$port = new class implements CurrentFlowRunInterface {
			public function currentRunUuid(): ?string {
				return 'run-1';
			}
		};
		$runtime = new SaveObjectRuntime(cacheHandler: $this->createMock(CacheHandlerInterface::class), runContext: $port);

		$this->assertSame($port, $runtime->runContext);
		$this->assertSame('run-1', $runtime->runContext?->currentRunUuid());
	}//end testAnyImplementationOfThePortIsCarried()

	public function testTheProcessesRunStackAnswersThePortWithTheWritingRun(): void {
		$stack   = new FlowRunContext();
		$runtime = new SaveObjectRuntime(cacheHandler: $this->createMock(CacheHandlerInterface::class), runContext: $stack);
		$this->assertNull($runtime->runContext?->currentRunUuid());

		$stack->push(frame: new FlowRunFrame(runUuid: 'run-2', nodeId: 'object-write', sequence: 1));
		try {
			$this->assertSame('run-2', $runtime->runContext?->currentRunUuid());
		} finally {
			$stack->pop();
		}
	}//end testTheProcessesRunStackAnswersThePortWithTheWritingRun()

	public function testWithoutARunContextNoRunIsWriting(): void {
		$runtime = new SaveObjectRuntime(cacheHandler: $this->createMock(CacheHandlerInterface::class));

		$this->assertNull($runtime->runContext);
	}//end testWithoutARunContextNoRunIsWriting()
}//end class
