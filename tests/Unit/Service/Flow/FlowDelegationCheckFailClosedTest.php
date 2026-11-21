<?php

/**
 * FlowDelegationCheck when its collaborators misbehave: a container entry that
 * is not the delegation service, a store that cannot be reached, and a flow
 * mapper that cannot record the refusal.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Delegation\DelegationRefused;
use OCA\OpenRegister\Service\Delegation\DelegationService;
use OCA\OpenRegister\Service\Delegation\DelegationVerdict;
use OCA\OpenRegister\Service\Flow\DelegatedRun;
use OCA\OpenRegister\Service\Flow\FlowDelegationCheck;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * Every failure to read or record a delegation ends in a refusal, never in a run.
 */
class FlowDelegationCheckFailClosedTest extends TestCase {

	/**
	 * Hands out the delegation service and the flow mapper.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Records what went wrong.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A scheduled run alice declared to run as carol.
	 *
	 * @return DelegatedRun
	 */
	private static function delegatedRun(): DelegatedRun {
		return new DelegatedRun(flowId: 'flow-1', trigger: 'schedule', declaredBy: 'alice', runAs: 'carol');
	}//end delegatedRun()

	/**
	 * Verify the run and return the refusal it must end in.
	 *
	 * @return DelegationRefused
	 */
	private function refusal(): DelegationRefused {
		try {
			(new FlowDelegationCheck($this->container, $this->logger))->verify(run: self::delegatedRun());
		} catch (DelegationRefused $e) {
			return $e;
		}

		$this->fail('the run must be refused');
	}//end refusal()

	/**
	 * A container entry that is not the delegation service refuses the run as unreadable.
	 *
	 * @return void
	 */
	public function testAnEntryThatIsNotTheServiceRefusesAsUnreadable(): void {
		$this->container->expects($this->once())
			->method('get')
			->with(DelegationService::class)
			->willReturn(new stdClass());
		$this->logger->expects($this->never())->method('error');

		$refusal = $this->refusal();

		$this->assertSame(DelegationVerdict::REASON_UNREADABLE, $refusal->getVerdict()->reason);
		$this->assertSame('The delegation store did not answer, so the delegation is refused.', $refusal->getVerdict()->detail);
	}//end testAnEntryThatIsNotTheServiceRefusesAsUnreadable()

	/**
	 * An unreachable store is logged with the flow and the acting user before the refusal.
	 *
	 * @return void
	 */
	public function testAnUnreachableStoreIsLoggedAndRefused(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('no store'));
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'[FlowDelegationCheck] Could not resolve the delegation service; refusing the run: no store',
				$this->callback(static fn (array $context): bool => $context['flow'] === 'flow-1' && $context['runAs'] === 'carol')
			);

		$refusal = $this->refusal();

		$this->assertSame('The delegation store could not be reached, so the delegation is refused.', $refusal->getVerdict()->detail);
	}//end testAnUnreachableStoreIsLoggedAndRefused()

	/**
	 * A flow mapper that throws is logged as a warning and the refusal still stands.
	 *
	 * @return void
	 */
	public function testARefusalTheMapperCannotRecordIsLoggedAndStillThrown(): void {
		$service = $this->createMock(DelegationService::class);
		$service->method('verdictFor')->willReturn(DelegationVerdict::refused(DelegationVerdict::REASON_EXPIRED, 'it ran out.'));

		$this->container->method('get')->willReturnCallback(
			static function (string $id) use ($service): object {
				if ($id === DelegationService::class) {
					return $service;
				}

				throw new RuntimeException('mapper gone');
			}
		);

		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$refusal = $this->refusal();

		$this->assertSame(DelegationVerdict::REASON_EXPIRED, $refusal->getVerdict()->reason);
		$this->assertCount(2, $warnings, 'the refusal itself, then the failure to record it');
		$this->assertSame('[FlowDelegationCheck] Could not record the delegation refusal on the flow: mapper gone', $warnings[1][0]);
		$this->assertSame('flow-1', $warnings[1][1]['flow']);
	}//end testARefusalTheMapperCannotRecordIsLoggedAndStillThrown()
}//end class
