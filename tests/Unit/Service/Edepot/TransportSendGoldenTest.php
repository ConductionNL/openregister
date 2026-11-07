<?php

/**
 * Golden record of what each e-Depot transport sends, logs and returns.
 *
 * Every scenario records the HTTP client calls (method and arguments, with
 * streams shown as `resource`), every log call (level, message, context) and
 * the TransportResult or boolean the transport returned. The recording lives
 * in fixtures/transport-send-golden.json; set TRANSPORT_GOLDEN_RECORD=1 to
 * write it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransport;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransport;
use OCA\OpenRegister\Service\Edepot\Transport\SftpTransport;
use OCA\OpenRegister\Service\Edepot\Transport\TransportResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\Transport\RestApiTransport
 * @covers \OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransport
 * @covers \OCA\OpenRegister\Service\Edepot\Transport\SftpTransport
 * @uses   \OCA\OpenRegister\Service\Edepot\Transport\TransportResult
 * @uses   \OCA\OpenRegister\Service\Edepot\Transport\HttpSipTransport
 * @uses   \OCA\OpenRegister\AppInfo\ClassAliasMap
 */
class TransportSendGoldenTest extends TestCase {

	private const FIXTURE = __DIR__.'/fixtures/transport-send-golden.json';

	private const REST = [
		'endpointUrl' => 'https://edepot.example.com/api/ingest',
		'authenticationType' => 'api_key',
		'apiKey' => 'a-real-key',
	];

	private const SFTP = ['host' => 'sftp.example.com', 'username' => 'u', 'password' => 'p', 'remotePath' => '/in/'];

	/**
	 * What the scenario saw.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * A temporary SIP file.
	 *
	 * @var string
	 */
	private string $sip = '';

	/**
	 * Create the SIP file.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->sip = sys_get_temp_dir().'/transport-golden-sip.zip';
		file_put_contents($this->sip, str_repeat('x', 1234));
	}//end setUp()

	/**
	 * Remove the SIP file.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		@unlink($this->sip);
		parent::tearDown();
	}//end tearDown()

	/**
	 * Replace streams, the temp path and exceptions by stable stand-ins.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The stable value.
	 */
	private function stable(mixed $value): mixed {
		if (is_resource($value) === true) {
			return 'resource';
		}

		if (is_string($value) === true) {
			return str_replace([$this->sip, basename($this->sip)], ['<sip>', '<sip-name>'], $value);
		}

		if (is_array($value) === true) {
			return array_map(fn ($v) => $this->stable($v), $value);
		}

		if (is_object($value) === true) {
			return get_class($value);
		}

		return $value;
	}//end stable()

	/**
	 * A logger that records every call.
	 *
	 * @return LoggerInterface The logger.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context=[]) use ($level): void {
					$this->calls[] = ['log' => $level, 'message' => $this->stable((string)$message), 'context' => $this->stable($context)];
				}
			);
		}

		return $logger;
	}//end logger()

	/**
	 * A client whose get/post record their arguments and answer from a queue.
	 *
	 * @param array<int, Response|\Throwable> $answers The answers, in order.
	 *
	 * @return Client The client.
	 */
	private function client(array $answers): Client {
		$client = $this->createMock(Client::class);
		foreach (['get', 'post'] as $verb) {
			$client->method($verb)->willReturnCallback(
				function (...$args) use ($verb, &$answers): Response {
					$this->calls[] = ['http' => $verb, 'args' => $this->stable($args)];
					$answer = array_shift($answers);
					if ($answer instanceof \Throwable) {
						throw $answer;
					}

					return $answer;
				}
			);
		}

		return $client;
	}//end client()

	/**
	 * Every scenario, keyed by name, as callables over a client and logger.
	 *
	 * @return array<string, array{array<int, Response|\Throwable>, callable}>
	 */
	private function scenarios(): array {
		$json = static fn (int $status, array $body): Response => new Response($status, [], (string)json_encode($body));
		$down = new ConnectException('connection refused', new Request('POST', 'https://edepot.example.com/api/ingest'));
		$rest = fn ($c, $l) => new RestApiTransport($c, $l);
		$oc   = fn ($c, $l) => new OpenConnectorTransport($c, $l);
		$sftp = fn ($c, $l) => new SftpTransport($l);
		return [
			'rest: accepted with a rejected object' => [
				[$json(200, ['reference' => 'R-1', 'objects' => [['uuid' => 'a'], ['id' => 'b', 'accepted' => false, 'error' => 'bad'], ['uuid' => 'c', 'reference' => 'X']]])],
				fn ($c, $l) => $rest($c, $l)->send($this->sip, self::REST),
			],
			'rest: accepted, id as reference, no objects' => [
				[$json(201, ['id' => 'I-9'])],
				fn ($c, $l) => $rest($c, $l)->send($this->sip, self::REST),
			],
			'rest: accepted, nothing in the body' => [
				[new Response(202, [], 'not json')],
				fn ($c, $l) => $rest($c, $l)->send($this->sip, self::REST),
			],
			'rest: http error with an error field' => [
				[$json(500, ['error' => 'disk full'])],
				fn ($c, $l) => $rest($c, $l)->send($this->sip, self::REST),
			],
			'rest: http error with a message field' => [
				[$json(422, ['message' => 'bad package'])],
				fn ($c, $l) => $rest($c, $l)->send($this->sip, self::REST),
			],
			'rest: http error without a body' => [
				[new Response(503, [], '')],
				fn ($c, $l) => $rest($c, $l)->send($this->sip, self::REST),
			],
			'rest: transport exception' => [
				[$down],
				fn ($c, $l) => $rest($c, $l)->send($this->sip, self::REST),
			],
			'rest: missing file' => [
				[],
				fn ($c, $l) => $rest($c, $l)->send('/nonexistent/sip.zip', self::REST),
			],
			'rest: oauth2 bearer' => [
				[$json(200, [])],
				fn ($c, $l) => $rest($c, $l)->send($this->sip, ['endpointUrl' => 'https://e/x', 'authenticationType' => 'oauth2', 'bearerToken' => 'tok']),
			],
			'rest: connection test ok' => [
				[new Response(204)],
				fn ($c, $l) => $rest($c, $l)->testConnection(self::REST),
			],
			'rest: connection test 404' => [
				[new Response(404)],
				fn ($c, $l) => $rest($c, $l)->testConnection(self::REST),
			],
			'rest: connection test down' => [
				[$down],
				fn ($c, $l) => $rest($c, $l)->testConnection(self::REST),
			],
			'oc: job created' => [
				[$json(200, ['callLogId' => 77])],
				fn ($c, $l) => $oc($c, $l)->send($this->sip, ['sourceId' => 's-1', 'baseUrl' => 'https://oc.example.com/']),
			],
			'oc: job created, id, configured app' => [
				[$json(200, ['id' => 'j'])],
				fn ($c, $l) => $oc($c, $l)->send($this->sip, ['sourceId' => 's-1', 'appId' => 'openconnector']),
			],
			'oc: no job id' => [
				[$json(200, [])],
				fn ($c, $l) => $oc($c, $l)->send($this->sip, ['sourceId' => 's-1']),
			],
			'oc: transport exception' => [
				[$down],
				fn ($c, $l) => $oc($c, $l)->send($this->sip, ['sourceId' => 's-1']),
			],
			'oc: missing file' => [
				[],
				fn ($c, $l) => $oc($c, $l)->send('/nonexistent/sip.zip', ['sourceId' => 's-1']),
			],
			'oc: connection test falls through a 404' => [
				[new Response(404), new Response(200)],
				fn ($c, $l) => $oc($c, $l)->testConnection(['sourceId' => 's-1']),
			],
			'oc: connection test both 404' => [
				[new Response(404), new Response(404)],
				fn ($c, $l) => $oc($c, $l)->testConnection(['sourceId' => 's-1']),
			],
			'oc: connection test down' => [
				[$down],
				fn ($c, $l) => $oc($c, $l)->testConnection(['sourceId' => 's-1']),
			],
			'sftp: missing file' => [
				[],
				fn ($c, $l) => $sftp($c, $l)->send('/nonexistent/sip.zip', self::SFTP),
			],
			'sftp: no credentials' => [
				[],
				fn ($c, $l) => $sftp($c, $l)->send($this->sip, ['host' => 'h', 'username' => 'u']),
			],
		];
	}//end scenarios()

	/**
	 * Each transport sends, logs and returns what the recording says.
	 *
	 * @return void
	 */
	public function testTransportsMatchTheRecording(): void {
		$seen = [];
		foreach ($this->scenarios() as $name => [$answers, $run]) {
			$this->calls = [];
			$result      = $run($this->client($answers), $this->logger());
			if ($result instanceof TransportResult) {
				$result = $result->toArray() + ['partial' => $result->isPartialSuccess()];
			}

			$seen[$name] = ['calls' => $this->calls, 'result' => $this->stable($result)];
		}

		if (getenv('TRANSPORT_GOLDEN_RECORD') === '1') {
			@mkdir(dirname(self::FIXTURE));
			file_put_contents(self::FIXTURE, json_encode($seen, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
			$this->markTestIncomplete('recorded');
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), json_decode((string)json_encode($seen), true));
	}//end testTransportsMatchTheRecording()
}//end class
