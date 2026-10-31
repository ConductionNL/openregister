<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\WebhooksController;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\WebhookService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for WebhooksController::events(): the full event catalogue,
 * its order and the response status, compared with
 * fixtures/webhooks-events-golden.json. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\WebhooksController
 */
class WebhooksControllerEventsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/webhooks-events-golden.json';

	public function testEventsMatchTheRecordedCatalogue(): void {
		$controller = new WebhooksController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(WebhookMapper::class),
			$this->createMock(WebhookLogMapper::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class)
		);

		$response = $controller->events();
		$actual = [
			'status' => $response->getStatus(),
			'data' => $response->getData(),
		];

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(
				self::FIXTURE,
				json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
			);
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, $actual);
	}
}
