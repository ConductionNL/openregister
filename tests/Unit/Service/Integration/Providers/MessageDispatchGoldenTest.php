<?php

/**
 * Golden record of MessageDispatchProvider::dispatch().
 *
 * Pins, per scenario, the exact router call (method, path, options), the
 * source the router saw, the returned envelope, the logged warnings and the
 * source the provider advertises afterwards: allowed and padded slugs, an
 * unknown slug, a caller Accept header, a leading-slash path, and the router
 * answering, throwing ProviderUnavailableException or any other Throwable.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Pins the dispatch shape of the messaging provider.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\Providers\OutboundMessage;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouterInterface;
use OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * dispatch() of the messaging provider.
 *
 * @covers \OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider
 */
class MessageDispatchGoldenTest extends TestCase {

	/**
	 * Run one dispatch and record everything observable around it.
	 *
	 * @param array<int, mixed> $args   The dispatch() arguments.
	 * @param mixed             $answer What the router returns, or a Throwable it throws.
	 *
	 * @return array<string, mixed> The recording.
	 */
	private function record(array $args, mixed $answer): array {
		$calls    = [];
		$warnings = [];
		$router   = $this->createMock(ExternalIntegrationRouterInterface::class);
		$router->method('call')->willReturnCallback(
			static function (object $provider, string $method, string $path, array $options) use (&$calls, $answer): array {
				$calls[] = [$provider->getOpenConnectorSource(), $method, $path, $options];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}
				return $answer;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$provider = new MessageDispatchProvider(new \OCA\OpenRegister\Service\Integration\Providers\ConnectorRouting($router, $this->createMock(IAppManager::class), $l10n), $logger);
		$result   = $provider->dispatch($args[0], new OutboundMessage(path: $args[2], body: $args[1], headers: ($args[3] ?? [])));

		return [
			'result'   => $result,
			'calls'    => $calls,
			'warnings' => $warnings,
			'after'    => $provider->getOpenConnectorSource(),
		];
	}//end record()

	/**
	 * The scenarios and their recordings.
	 *
	 * @return array<string, array{0: array<int, mixed>, 1: mixed, 2: array<string, mixed>}>
	 */
	public static function scenarios(): array {
		$defaults = ['Accept' => 'application/json'];
		return [
			'sent, padded slug, leading slash' => [
				['  twilio-sms ', ['Body' => 'Hi'], '/Messages.json'],
				['sid' => 'SM1'],
				[
					'result'   => ['status' => 'sent', 'source' => 'twilio-sms', 'response' => ['sid' => 'SM1']],
					'calls'    => [['twilio-sms', 'POST', 'Messages.json', ['body' => ['Body' => 'Hi'], 'headers' => $defaults]]],
					'warnings' => [],
					'after'    => 'whatsapp-cloud-api',
				],
			],
			'caller headers kept, Accept not overridden' => [
				['cmcom-sms', [], 'message', ['Accept' => 'text/plain', 'X-A' => 'b']],
				[],
				[
					'result'   => ['status' => 'sent', 'source' => 'cmcom-sms', 'response' => []],
					'calls'    => [['cmcom-sms', 'POST', 'message', ['body' => [], 'headers' => ['Accept' => 'text/plain', 'X-A' => 'b']]]],
					'warnings' => [],
					'after'    => 'whatsapp-cloud-api',
				],
			],
			'caller headers get Accept appended' => [
				['whatsapp-bsp', ['x' => 1], '//a/b', ['Content-Type' => 'application/json']],
				['ok' => true],
				[
					'result'   => ['status' => 'sent', 'source' => 'whatsapp-bsp', 'response' => ['ok' => true]],
					'calls'    => [['whatsapp-bsp', 'POST', 'a/b', ['body' => ['x' => 1], 'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json']]]],
					'warnings' => [],
					'after'    => 'whatsapp-cloud-api',
				],
			],
			'unknown slug' => [
				['evil-source', ['x' => 1], 'message'],
				['never' => true],
				[
					'result'   => ['unavailable' => true, 'cause' => 'openconnector-source-missing'],
					'calls'    => [],
					'warnings' => ['MessageDispatchProvider::dispatch rejected an unknown source slug.'],
					'after'    => 'whatsapp-cloud-api',
				],
			],
			'router unavailable' => [
				['messagebird-sms', ['x' => 1], 'messages'],
				new ProviderUnavailableException('down', ProviderUnavailableException::CAUSE_PROVIDER_AUTH),
				[
					'result'   => ['unavailable' => true, 'cause' => 'provider-auth'],
					'calls'    => [['messagebird-sms', 'POST', 'messages', ['body' => ['x' => 1], 'headers' => $defaults]]],
					'warnings' => [],
					'after'    => 'whatsapp-cloud-api',
				],
			],
			'router throws' => [
				['whatsapp-cloud-api', ['x' => 1], 'messages'],
				new RuntimeException('boom'),
				[
					'result'   => ['unavailable' => true, 'cause' => 'upstream-service-down'],
					'calls'    => [['whatsapp-cloud-api', 'POST', 'messages', ['body' => ['x' => 1], 'headers' => $defaults]]],
					'warnings' => ['MessageDispatchProvider::dispatch failed (transport).'],
					'after'    => 'whatsapp-cloud-api',
				],
			],
		];
	}//end scenarios()

	/**
	 * Each scenario records exactly as pinned.
	 *
	 * @param array<int, mixed>    $args     The dispatch() arguments.
	 * @param mixed                $answer   The router's answer.
	 * @param array<string, mixed> $expected The pinned recording.
	 *
	 * @dataProvider scenarios
	 *
	 * @return void
	 */
	public function testDispatchMatchesTheGoldenRecord(array $args, mixed $answer, array $expected): void {
		$this->assertSame($expected, $this->record($args, $answer));
	}//end testDispatchMatchesTheGoldenRecord()
}//end class
