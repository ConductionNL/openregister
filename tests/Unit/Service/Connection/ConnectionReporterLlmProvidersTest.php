<?php

/**
 * The LLM connection status {@see \OCA\OpenRegister\Service\Connection\ConnectionReporter::reportLlmProviders()}
 * derives from the chosen chat and embedding providers, and the event that
 * carries it, plus the refresh request a token save sends.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Connection;

use OCA\Integriq\Event\ConnectionRefreshRequestedEvent;
use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCA\OpenRegister\Service\Connection\ConnectionReporter;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * ConnectionReporterLlmProvidersTest.
 */
class ConnectionReporterLlmProvidersTest extends TestCase {
	/** @var list<Event> */
	private array $sent = [];
	private ConnectionReporter $reporter;

	protected function setUp(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (Event $event): void {
			$this->sent[] = $event;
		});
		$this->reporter = new ConnectionReporter($dispatcher, new NullLogger());
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	private function reported(mixed $chat, mixed $embedding): array {
		$this->assertTrue($this->reporter->reportLlmProviders($chat, $embedding));
		$event = $this->sent[0];
		$this->assertInstanceOf(ConnectionStatusReportedEvent::class, $event);
		$this->assertSame(['openregister', 'llm'], [$event->app, $event->key]);
		return [$event->status, $event->message];
	}

	public function testBothProvidersChosenIsConfigured(): void {
		$this->assertSame(
			['configured', 'Chat uses openai and embeddings use ollama. Saved, not tested.'],
			$this->reported(' openai ', 'ollama')
		);
	}

	public function testOnlyEmbeddingsChosenIsLimitedAndSaysChatAnswers503(): void {
		$this->assertSame(
			['limited', 'Embeddings use ollama. No chat provider is chosen, so chat answers 503. Saved, not tested.'],
			$this->reported('None', 'ollama')
		);
	}

	public function testOnlyChatChosenIsLimited(): void {
		$this->assertSame(
			['limited', 'Chat uses openai. No embedding provider is chosen. Saved, not tested.'],
			$this->reported('openai', null)
		);
	}

	public function testNeitherChosenIsUnconfigured(): void {
		$this->assertSame(
			['unconfigured', 'No chat or embedding provider is chosen. Chat answers 503 until one is.'],
			$this->reported(['not', 'a', 'string'], '   ')
		);
	}

	public function testATokenSaveRequestsARefreshOfEachTouchedConnection(): void {
		$refreshed = $this->reporter->refreshFromSave(['gitlab_api_token', 'github_api_token', 'other']);

		$this->assertSame(['github', 'gitlab'], $refreshed);
		$this->assertSame(['github', 'gitlab'], array_map(static fn (ConnectionRefreshRequestedEvent $e): string => $e->key, $this->sent));
	}

	public function testASaveTouchingNoTokenRequestsNothing(): void {
		$this->assertSame([], $this->reporter->refreshFromSave(['llm_provider']));
		$this->assertSame([], $this->sent);
	}
}
