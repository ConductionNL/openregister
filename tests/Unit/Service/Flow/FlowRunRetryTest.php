<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

class FlowRunRetryTest extends TestCase {
	private FlowRunMapper $mapper;
	private FlowRunService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(FlowRunMapper::class);
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->mapper->method('update')->willReturnArgument(0);

		$this->service = new FlowRunService(
			$this->mapper,
			$this->createMock(\OCA\OpenRegister\Db\FlowStateMapper::class),
			$this->createMock(FlowEngine::class),
			$this->createMock(FlowNodeRegistry::class),
			new NullLogger(),
			$this->noOrganisationContainer()
		);
	}

	/**
	 * A container that has no OrganisationService — the cron/unit case, where a
	 * queued run is recorded with no organisation rather than a guessed one.
	 */
	private function noOrganisationContainer(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not available'));

		return $container;
	}

	private function terminalRun(): FlowRun {
		$run = new FlowRun();
		$run->setUuid('orig-uuid');
		$run->setFlowId('f1');
		$run->setStatus(FlowRun::STATUS_FAILED);
		$run->setSubjectUuid('subj-1');
		$run->setSubjectRegister('reg');
		$run->setSubjectSchema('sch');
		$run->setTriggeredBy('alice');
		$run->setContext(['runUuid' => 'orig-uuid', 'k' => 'v']);
		return $run;
	}

	/**
	 * Retry queues a NEW run — same flow, subject and user — and leaves the
	 * original exactly as it ended.
	 */
	public function testRetryQueuesAFreshRunAgainstTheSameFlow(): void {
		$source = $this->terminalRun();

		$new = $this->service->retry($source);

		$this->assertNotNull($new);
		$this->assertNotSame('orig-uuid', $new->getUuid());
		$this->assertSame('f1', $new->getFlowId());
		$this->assertSame('subj-1', $new->getSubjectUuid());
		$this->assertSame('alice', $new->getTriggeredBy());
		$this->assertSame(FlowRun::STATUS_QUEUED, $new->getStatus());
		$this->assertSame('retry', $new->getTrigger());

		// The original is untouched — still failed.
		$this->assertSame(FlowRun::STATUS_FAILED, $source->getStatus());
	}

	/** @dataProvider terminalStatuses */
	public function testEveryTerminalStatusCanBeRetried(string $status): void {
		$run = $this->terminalRun();
		$run->setStatus($status);

		$this->assertNotNull($this->service->retry($run));
	}

	public static function terminalStatuses(): array {
		return [
			'completed' => [FlowRun::STATUS_COMPLETED],
			'stopped' => [FlowRun::STATUS_STOPPED],
			'dead_letter' => [FlowRun::STATUS_DEAD_LETTER],
			'failed' => [FlowRun::STATUS_FAILED],
		];
	}

	/** @dataProvider nonTerminalStatuses */
	public function testANonTerminalRunCannotBeRetried(string $status): void {
		$run = $this->terminalRun();
		$run->setStatus($status);

		// Queued/running are already progressing; suspended resumes, not restarts.
		$this->assertNull($this->service->retry($run));
	}

	public static function nonTerminalStatuses(): array {
		return [
			'queued' => [FlowRun::STATUS_QUEUED],
			'running' => [FlowRun::STATUS_RUNNING],
			'suspended' => [FlowRun::STATUS_SUSPENDED],
		];
	}
}
