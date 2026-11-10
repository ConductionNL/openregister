<?php

/**
 * Unit tests for logging a refused automatic transition without a code, and for flushing queued moves.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Lifecycle\SchemaReading;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionCandidate;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionDecider;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionDecision;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionQueue;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionRunner;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionSelector;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionSubject;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A guard refusal that carries no usable code is logged with a null code; queued moves flush through the deferral service.
 *
 * @covers \OCA\OpenRegister\Service\Lifecycle\AutoTransitionRunner
 */
class AutoTransitionRunnerRefusalCodeTest extends TestCase {

	/**
	 * The deferral service, mocked.
	 *
	 * @var ListenerDeferralService&MockObject
	 */
	private ListenerDeferralService&MockObject $deferral;

	/**
	 * A runner whose engine throws the given error.
	 *
	 * @param LoggerInterface $logger The logger.
	 * @param \Throwable|null $error  What the engine throws.
	 *
	 * @return AutoTransitionRunner
	 */
	private function runner(LoggerInterface $logger, ?\Throwable $error = null): AutoTransitionRunner {
		$engine = $this->createMock(TransitionEngine::class);
		if ($error !== null) {
			$engine->method('transition')->willThrowException($error);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(TransitionEngine::class)->willReturn($engine);
		$this->deferral = $this->createMock(ListenerDeferralService::class);

		return new AutoTransitionRunner(
			new AutoTransitionDecider($container, new SchemaReading($this->createMock(SchemaMapperInterface::class), $logger), $this->createMock(AutoTransitionSelector::class)),
			$container,
			new AutoTransitionQueue($this->deferral, $logger),
			$logger
		);

	}//end runner()

	/**
	 * A sync decision on object obj-1.
	 *
	 * @return AutoTransitionDecision
	 */
	private static function decision(): AutoTransitionDecision {
		return new AutoTransitionDecision(
			subject: new AutoTransitionSubject(uuid: 'obj-1', register: '1', schema: '2', schemaSlug: 'bezwaar'),
			candidate: new AutoTransitionCandidate('beslissen', 'open', 'besloten', 'sync'),
			version: '3',
			updated: '2026-09-11T09:00:00+00:00'
		);

	}//end decision()

	/**
	 * Refusals whose code is missing or empty log a null refusal code.
	 *
	 * @return void
	 */
	public function testARefusalWithoutACodeLogsNoCode(): void {
		foreach ([[], ['code' => ''], ['code' => 42]] as $errors) {
			$codes  = [];
			$logger = $this->createMock(LoggerInterface::class);
			$logger->method('warning')->willReturnCallback(
				static function (string $message, array $context) use (&$codes): void {
					$codes[] = $context['refusalCode'];
				}
			);

			$result = $this->runner($logger, new HookStoppedException('Denied.', $errors))->fire(self::decision(), []);

			$this->assertNull($result);
			$this->assertSame([null], $codes);
		}

	}//end testARefusalWithoutACodeLogsNoCode()

	/**
	 * Flushing queued moves flushes the deferral service once.
	 *
	 * @return void
	 */
	public function testFlushingQueuedMovesFlushesTheDeferrals(): void {
		$runner = $this->runner($this->createMock(LoggerInterface::class));
		$this->deferral->expects($this->once())->method('flushAll');

		$runner->flushQueued();

	}//end testFlushingQueuedMovesFlushesTheDeferrals()
}//end class
