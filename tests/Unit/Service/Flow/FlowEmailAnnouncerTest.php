<?php

/**
 * Unit tests for FlowEmailAnnouncer: the event it dispatches and a failing listener.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Event\FlowEmailSentEvent;
use OCA\OpenRegister\Service\Flow\FlowEmailAnnouncer;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowSend;
use OCA\OpenRegister\Service\Flow\FlowSendItem;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every field of the dispatched FlowEmailSentEvent, over items with and
 * without `@self`, run ids under both context keys, and a run-context frame.
 *
 * @covers \OCA\OpenRegister\Service\Flow\FlowEmailAnnouncer
 * @uses \OCA\OpenRegister\Service\Flow\FlowSend
 * @uses \OCA\OpenRegister\Service\Flow\FlowSendItem
 * @uses \OCA\OpenRegister\Event\FlowEmailSentEvent
 * @uses \OCA\OpenRegister\Event\FlowEmailItem
 * @uses \OCA\OpenRegister\Event\FlowEmailMessage
 * @uses \OCA\OpenRegister\Event\FlowEmailOrigin
 * @uses \OCA\OpenRegister\Service\Flow\FlowScalarText
 */
class FlowEmailAnnouncerTest extends TestCase {

	/**
	 * The cases: item json, send context, run-context frame, expected event fields.
	 *
	 * @return array<string, array{0: array, 1: array, 2: array|null|false, 3: array}>
	 */
	public static function cases(): array {
		$base = ['recipient' => 'a@example.org', 'kind' => 'external', 'subject' => 'Hi', 'body' => 'Body', 'actingUser' => 'alice'];
		return [
			'full self' => [
				['@self' => ['register' => ' 5 ', 'schema' => 7, 'id' => 'u-1'], 'uuid' => 'u-2'],
				['flowId' => 'f-1', FlowRunContext::CONTEXT_RUN => 'r-1', 'runUuid' => 'r-2'],
				false,
				$base + ['register' => '5', 'schema' => '7', 'objectUuid' => 'u-1', 'flowId' => 'f-1', 'runId' => 'r-1', 'stepName' => 'step'],
			],
			'uuid fallback, runUuid key' => [
				['uuid' => 'u-2'],
				['runUuid' => 'r-2'],
				null,
				$base + ['register' => null, 'schema' => null, 'objectUuid' => 'u-2', 'flowId' => null, 'runId' => 'r-2', 'stepName' => 'step'],
			],
			'blank and non-scalar values' => [
				['@self' => ['register' => '  ', 'schema' => ['x'], 'id' => '']],
				['flowId' => ''],
				['node' => '   '],
				$base + ['register' => null, 'schema' => null, 'objectUuid' => null, 'flowId' => null, 'runId' => null, 'stepName' => 'step'],
			],
			'frame names the node' => [
				[],
				[],
				['node' => 'mail-node'],
				$base + ['register' => null, 'schema' => null, 'objectUuid' => null, 'flowId' => null, 'runId' => null, 'stepName' => 'mail-node'],
			],
		];
	}//end cases()

	/**
	 * The dispatched event carries every field as listed.
	 *
	 * @param array            $json     The item json.
	 * @param array            $context  The send context.
	 * @param array|null|false $frame    The run-context frame; false for no run context.
	 * @param array            $expected The expected event fields.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testTheEventCarriesEveryField(array $json, array $context, array|null|false $frame, array $expected): void {
		$captured = null;
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())->method('dispatchTyped')->willReturnCallback(
			function ($event) use (&$captured): void {
				$captured = $event;
			}
		);

		$this->announce(announcer: $this->announcer(dispatcher: $dispatcher, frame: $frame), json: $json, context: $context);

		$this->assertInstanceOf(FlowEmailSentEvent::class, $captured);
		$this->assertSame($expected, self::fields(event: $captured));
	}//end testTheEventCarriesEveryField()

	/**
	 * A listener that throws is logged and does not escape.
	 *
	 * @return void
	 */
	public function testAFailingListenerIsLoggedNotThrown(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('listener down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'[FlowEmailAnnouncer] a FlowEmailSentEvent listener failed after the email was sent: listener down',
			$this->callback(fn (array $ctx): bool => $ctx['exception'] instanceof RuntimeException)
		);

		$this->announce(announcer: new FlowEmailAnnouncer(eventDispatcher: $dispatcher, logger: $logger), json: [], context: []);
	}//end testAFailingListenerIsLoggedNotThrown()

	/**
	 * The announcer under test.
	 *
	 * @param IEventDispatcher $dispatcher The dispatcher.
	 * @param array|null|false $frame      The run-context frame; false for no run context.
	 *
	 * @return FlowEmailAnnouncer The announcer.
	 */
	private function announcer(IEventDispatcher $dispatcher, array|null|false $frame): FlowEmailAnnouncer {
		$runContext = null;
		if ($frame !== false) {
			$runContext = $this->createMock(FlowRunContext::class);
			$runContext->method('current')->willReturn($frame);
		}

		return new FlowEmailAnnouncer(eventDispatcher: $dispatcher, logger: $this->createMock(LoggerInterface::class), runContext: $runContext);
	}//end announcer()

	/**
	 * Announce one sent email for the given item json and send context.
	 *
	 * @param FlowEmailAnnouncer $announcer The announcer.
	 * @param array              $json      The item json.
	 * @param array              $context   The send context.
	 *
	 * @return void
	 */
	private function announce(FlowEmailAnnouncer $announcer, array $json, array $context): void {
		$send = new FlowSend(channel: 'email', config: [], items: [], context: $context);
		$send->stepName = 'step';
		$send->actor = 'alice';
		$item = new FlowSendItem(json: $json, uids: [], addresses: []);
		$item->recipient = 'a@example.org';
		$item->kind = 'external';
		$item->title = 'Hi';
		$item->body = 'Body';

		$announcer->announce(send: $send, item: $item);
	}//end announce()

	/**
	 * The event's fields, in a fixed order.
	 *
	 * @param FlowEmailSentEvent $event The event.
	 *
	 * @return array The fields.
	 */
	private static function fields(FlowEmailSentEvent $event): array {
		return [
			'recipient' => $event->getRecipient(),
			'kind' => $event->getChannelKind(),
			'subject' => $event->getSubject(),
			'body' => $event->getBody(),
			'actingUser' => $event->getActingUser(),
			'register' => $event->getRegister(),
			'schema' => $event->getSchema(),
			'objectUuid' => $event->getObjectUuid(),
			'flowId' => $event->getFlowId(),
			'runId' => $event->getRunId(),
			'stepName' => $event->getStepName(),
		];
	}//end fields()
}//end class
