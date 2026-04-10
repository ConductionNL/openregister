<?php

declare(strict_types=1);

/**
 * Transport Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Service\Edepot;

use OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransport;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransport;
use OCA\OpenRegister\Service\Edepot\Transport\SftpTransport;
use OCA\OpenRegister\Service\Edepot\Transport\TransportResult;
use GuzzleHttp\Client;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for transport implementations.
 */
class TransportTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private Client&MockObject $httpClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->httpClient = $this->createMock(Client::class);
    }

    /**
     * Test TransportResult value object.
     */
    public function testTransportResultSuccess(): void
    {
        $result = new TransportResult(
            success: true,
            objectResults: [
                'uuid-1' => ['accepted' => true, 'reference' => 'ref-1', 'error' => null],
                'uuid-2' => ['accepted' => true, 'reference' => 'ref-2', 'error' => null],
            ],
            transferReference: 'transfer-ref-1'
        );

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isPartialSuccess());
        $this->assertCount(2, $result->getAcceptedUuids());
        $this->assertCount(0, $result->getRejectedUuids());
        $this->assertEquals('transfer-ref-1', $result->getTransferReference());
    }

    /**
     * Test TransportResult partial success.
     */
    public function testTransportResultPartialSuccess(): void
    {
        $result = new TransportResult(
            success: false,
            objectResults: [
                'uuid-1' => ['accepted' => true, 'reference' => 'ref-1', 'error' => null],
                'uuid-2' => ['accepted' => false, 'reference' => null, 'error' => 'Validation failed'],
            ]
        );

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isPartialSuccess());
        $this->assertCount(1, $result->getAcceptedUuids());
        $this->assertCount(1, $result->getRejectedUuids());
    }

    /**
     * Test TransportResult serialization.
     */
    public function testTransportResultToArray(): void
    {
        $result = new TransportResult(success: false, errorMessage: 'Connection refused');
        $array = $result->toArray();

        $this->assertFalse($array['success']);
        $this->assertEquals('Connection refused', $array['errorMessage']);
    }

    /**
     * Test SftpTransport returns name.
     */
    public function testSftpTransportName(): void
    {
        $transport = new SftpTransport($this->logger);
        $this->assertEquals('sftp', $transport->getName());
    }

    /**
     * Test SftpTransport send fails with missing file.
     */
    public function testSftpTransportSendMissingFile(): void
    {
        $transport = new SftpTransport($this->logger);
        $result = $transport->send('/nonexistent/file.zip', [
            'host' => 'localhost',
            'username' => 'test',
            'password' => 'test',
        ]);

        $this->assertFalse($result->isSuccess());
    }

    /**
     * Test SftpTransport send fails with missing config.
     */
    public function testSftpTransportSendMissingConfig(): void
    {
        $transport = new SftpTransport($this->logger);
        $result = $transport->send('/tmp/test.zip', []);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Missing required', $result->getErrorMessage());
    }

    /**
     * Test RestApiTransport returns name.
     */
    public function testRestApiTransportName(): void
    {
        $transport = new RestApiTransport($this->httpClient, $this->logger);
        $this->assertEquals('rest_api', $transport->getName());
    }

    /**
     * Test RestApiTransport send fails with missing file.
     */
    public function testRestApiTransportSendMissingFile(): void
    {
        $transport = new RestApiTransport($this->httpClient, $this->logger);
        $result = $transport->send('/nonexistent/file.zip', [
            'endpointUrl' => 'https://edepot.example.com/api/ingest',
        ]);

        $this->assertFalse($result->isSuccess());
    }

    /**
     * Test RestApiTransport send fails with missing config.
     */
    public function testRestApiTransportSendMissingConfig(): void
    {
        $transport = new RestApiTransport($this->httpClient, $this->logger);
        $result = $transport->send('/tmp/test.zip', []);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('endpointUrl', $result->getErrorMessage());
    }

    /**
     * Test OpenConnectorTransport returns name.
     */
    public function testOpenConnectorTransportName(): void
    {
        $transport = new OpenConnectorTransport($this->httpClient, $this->logger);
        $this->assertEquals('openconnector', $transport->getName());
    }

    /**
     * Test OpenConnectorTransport send fails with missing config.
     */
    public function testOpenConnectorTransportSendMissingConfig(): void
    {
        $transport = new OpenConnectorTransport($this->httpClient, $this->logger);
        $result = $transport->send('/tmp/test.zip', []);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('sourceId', $result->getErrorMessage());
    }
}
