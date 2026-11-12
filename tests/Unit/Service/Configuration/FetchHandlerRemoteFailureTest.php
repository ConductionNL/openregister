<?php

/**
 * Behaviour tests for FetchHandler::fetchRemoteConfiguration() when the fetch itself throws.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Service\Configuration\FetchHandler;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A transport error during the GET is answered by get() itself (400); these tests
 * cover what escapes it: an error while reading the response (Guzzle or not) is a 500.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\FetchHandler
 * @uses   \OCA\OpenRegister\Db\Configuration
 */
class FetchHandlerRemoteFailureTest extends TestCase
{
    /** @var Client&MockObject */
    private Client $client;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var list<string> */
    private array $errors = [];

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->method('error')->willReturnCallback(
            function (string $message): void {
                $this->errors[] = $message;
            }
        );
    }//end setUp()

    private function remote(): Configuration
    {
        $configuration = new Configuration();
        $configuration->setSourceType('url');
        $configuration->setSourceUrl('https://config.example.test/register.json');
        return $configuration;
    }//end remote()

    private function responseThatThrows(\Throwable $error): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getHeaderLine')->willThrowException($error);
        return $response;
    }//end responseThatThrows()

    public function testAGuzzleErrorWhileReadingTheResponseIsA500NamingTheFetch(): void
    {
        $this->client->method('request')->with('GET', 'https://config.example.test/register.json')
            ->willReturn($this->responseThatThrows(new TransferException('stream reset')));

        $response = (new FetchHandler($this->client, $this->logger))->fetchRemoteConfiguration($this->remote());

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(500, $response->getStatus());
        $this->assertSame(['error' => 'Failed to fetch remote configuration: stream reset'], $response->getData());
        $this->assertSame(['[FetchHandler] Failed to fetch remote configuration: stream reset'], $this->errors);
    }//end testAGuzzleErrorWhileReadingTheResponseIsA500NamingTheFetch()

    public function testAnyOtherErrorIsA500AnsweredAsUnexpected(): void
    {
        $this->client->method('request')->willThrowException(new RuntimeException('out of memory'));

        $response = (new FetchHandler($this->client, $this->logger))->fetchRemoteConfiguration($this->remote());

        $this->assertSame(500, $response->getStatus());
        $this->assertSame(['error' => 'Unexpected error: out of memory'], $response->getData());
        $this->assertSame(['[FetchHandler] Unexpected error fetching remote configuration: out of memory'], $this->errors);
    }//end testAnyOtherErrorIsA500AnsweredAsUnexpected()

    public function testALocalConfigurationIsRefusedWithoutAnyRequest(): void
    {
        $this->client->expects($this->never())->method('request');
        $configuration = new Configuration();
        $configuration->setSourceType('local');

        $response = (new FetchHandler($this->client, $this->logger))->fetchRemoteConfiguration($configuration);

        $this->assertSame(400, $response->getStatus());
        $this->assertSame(['error' => 'Configuration is not from a remote source'], $response->getData());
    }//end testALocalConfigurationIsRefusedWithoutAnyRequest()
}//end class
