<?php

/**
 * Golden outcomes of TalkSender::postAsBot().
 *
 * Pins the bot post's refusals (empty token, kill switch), the URL it posts
 * to (overwrite.cli.url or loopback, trailing slash trimmed, token encoded),
 * the exact request options, and the logged failure.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Notifications\Service\Notification\NotificationChannelPolicy;
use OCA\OpenRegister\Notifications\Service\Notification\TalkSender;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TalkSenderPostAsBotGoldenTest extends TestCase {

	private const OPTIONS = [
		'headers' => [
			'OCS-APIRequest' => 'true',
			'Accept' => 'application/json',
			'Content-Type' => 'application/x-www-form-urlencoded',
		],
		'body' => [
			'message' => 'Hallo',
			'actorType' => 'bots',
			'actorId' => 'openregister',
		],
		'timeout' => 5,
	];

	public function testAnEmptyTokenFailsWithoutAClient(): void {
		$clients = $this->createMock(IClientService::class);
		$clients->expects($this->never())->method('newClient');

		$sender = new TalkSender(httpClient: $clients, logger: $this->createMock(LoggerInterface::class));

		$this->assertSame(TalkSender::OUTCOME_FAILED, $sender->postAsBot('', 'Hallo'));
	}

	public function testTheKillSwitchSkipsThePost(): void {
		$clients = $this->createMock(IClientService::class);
		$clients->expects($this->never())->method('newClient');
		$policy = $this->createMock(NotificationChannelPolicy::class);
		$policy->expects($this->once())->method('isChannelEnabled')->with('talk')->willReturn(false);

		$sender = new TalkSender(httpClient: $clients, logger: $this->createMock(LoggerInterface::class), channelPolicy: $policy);

		$this->assertSame(TalkSender::OUTCOME_KILL_SWITCH, $sender->postAsBot('tok', 'Hallo'));
	}

	public function testWithoutConfigItPostsToTheLoopback(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')
			->with('http://localhost/ocs/v2.php/apps/spreed/api/v1/chat/a%2Fb%20c', self::OPTIONS);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$sender = new TalkSender(httpClient: $clients, logger: $this->createMock(LoggerInterface::class));

		$this->assertSame(TalkSender::OUTCOME_DISPATCHED, $sender->postAsBot('a/b c', 'Hallo'));
	}

	public function testWithConfigItPostsToTheOverwriteHostWithoutTrailingSlash(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')
			->with('https://nc.example/ocs/v2.php/apps/spreed/api/v1/chat/tok', self::OPTIONS);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$config = $this->createMock(IConfig::class);
		$config->expects($this->once())->method('getSystemValue')
			->with('overwrite.cli.url', 'http://localhost')->willReturn('https://nc.example/');
		$policy = $this->createMock(NotificationChannelPolicy::class);
		$policy->method('isChannelEnabled')->willReturn(true);

		$sender = new TalkSender(httpClient: $clients, logger: $this->createMock(LoggerInterface::class), config: $config, channelPolicy: $policy);

		$this->assertSame(TalkSender::OUTCOME_DISPATCHED, $sender->postAsBot('tok', 'Hallo'));
	}

	public function testAFailedPostIsLoggedAndFails(): void {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willThrowException(new \RuntimeException('refused'));
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('[TalkSender] talk to "tok" failed: refused');

		$sender = new TalkSender(httpClient: $clients, logger: $logger);

		$this->assertSame(TalkSender::OUTCOME_FAILED, $sender->postAsBot('tok', 'Hallo'));
	}
}
