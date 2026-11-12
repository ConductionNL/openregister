<?php

/**
 * Golden: what the configuration upload handler answers for every kind of
 * upload, pinned on exact arrays, statuses and messages.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Response;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\UploadHandler
 */
class UploadHandlerGoldenTest extends TestCase {
	/**
	 * Temporary files written by a test.
	 *
	 * @var array<int, string>
	 */
	private array $files = [];

	/**
	 * Remove the temporary files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * A handler whose client answers with the given response, or throws.
	 *
	 * @param Response|null $response The response; null makes the call fail.
	 *
	 * @return UploadHandler
	 */
	private function handler(?Response $response=null): UploadHandler {
		$client = $this->createMock(Client::class);
		if ($response === null) {
			$client->method('request')->willThrowException(new TransferException('connection refused'));
		} else {
			$client->method('request')->with('GET', 'https://example.test/c')->willReturn($response);
		}

		return new UploadHandler($client, $this->createMock(LoggerInterface::class));
	}//end handler()

	/**
	 * An uploaded file entry with the given name and content.
	 *
	 * @param string $name    The client file name.
	 * @param string $content The content.
	 * @param int    $error   The upload error code.
	 *
	 * @return array<string, mixed>
	 */
	private function upload(string $name, string $content, int $error=UPLOAD_ERR_OK): array {
		$path          = tempnam(sys_get_temp_dir(), 'orup');
		$this->files[] = $path;
		file_put_contents($path, $content);

		return ['name' => $name, 'tmp_name' => $path, 'error' => $error];
	}//end upload()

	/**
	 * An answer as status and data.
	 *
	 * @param array|JSONResponse $answer The handler's answer.
	 *
	 * @return array
	 */
	private function shape(array|JSONResponse $answer): array {
		if ($answer instanceof JSONResponse) {
			return ['status' => $answer->getStatus(), 'data' => $answer->getData()];
		}

		return ['array' => $answer];
	}//end shape()

	/**
	 * Missing input, too many files and upload errors answer 400 with exact messages.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$handler = $this->handler();
		$this->assertSame(
			['status' => 400, 'data' => ['error' => 'Missing required keys in POST body: url, json, or file in form-data.']],
			$this->shape($handler->getUploadedJson(['other' => 1], null))
		);
		$this->assertSame(
			['status' => 400, 'data' => ['error' => 'Missing required keys in POST body: url, json, or file in form-data.']],
			$this->shape($handler->getUploadedJson([], []))
		);
		$this->assertSame(
			['status' => 400, 'data' => ['message' => 'Expected only 1 file.']],
			$this->shape($handler->getUploadedJson(['url' => 'https://example.test/c'], ['a' => $this->upload('a.json', '{}'), 'b' => $this->upload('b.json', '{}')]))
		);
		$this->assertSame(
			['status' => 400, 'data' => ['error' => 'File upload error: 3']],
			$this->shape($handler->getUploadedJson([], ['f' => $this->upload('a.json', '{}', UPLOAD_ERR_PARTIAL)]))
		);
	}//end testRefusals()

	/**
	 * A single file wins over url and json, and decodes as JSON, then YAML.
	 *
	 * @return void
	 */
	public function testFiles(): void {
		$handler = $this->handler();
		$this->assertSame(
			['array' => ['a' => ['b' => 1], 'list' => [1, 2]]],
			$this->shape($handler->getUploadedJson(['url' => 'https://example.test/c', 'json' => '{"x":1}'], ['only' => $this->upload('c.json', '{"a":{"b":1},"list":[1,2]}')]))
		);
		$this->assertSame(
			['array' => ['a' => ['b' => 'c'], 'n' => 5]],
			$this->shape($handler->getUploadedJson([], [$this->upload('c.yaml', "a:\n  b: c\nn: 5\n")]))
		);
		$this->assertSame(
			['status' => 400, 'data' => ['error' => 'Failed to decode file content as JSON or YAML', 'MIME-type' => 'txt']],
			$this->shape($handler->getUploadedJson([], [$this->upload('c.txt', "a: [unclosed\n")]))
		);
		$this->assertSame(
			['status' => 400, 'data' => ['error' => 'Failed to decode file content as JSON or YAML', 'MIME-type' => 'json']],
			$this->shape($handler->getUploadedJson([], [$this->upload('c.json', 'false')]))
		);
		$this->assertSame(
			['status' => 400, 'data' => ['error' => 'Failed to decode file content as JSON or YAML', 'MIME-type' => '']],
			$this->shape($handler->getUploadedJson([], [$this->upload('noext', 'just words')]))
		);
	}//end testFiles()

	/**
	 * A url is fetched and decoded by its Content-Type.
	 *
	 * @return void
	 */
	public function testUrl(): void {
		$cases = [
			['application/json', '{"a":{"b":[1]}}', ['array' => ['a' => ['b' => [1]]]]],
			['application/json', 'a: b', ['status' => 400, 'data' => ['error' => 'Failed to parse response body as JSON or YAML', 'Content-Type' => 'application/json']]],
			['application/yaml', "a:\n  - 1\n", ['array' => ['a' => [1]]]],
			['application/yaml', '"just text"', ['status' => 400, 'data' => ['error' => 'Failed to parse response body as JSON or YAML', 'Content-Type' => 'application/yaml']]],
			['text/plain', "k: v\n", ['array' => ['k' => 'v']]],
			['', '{"k":"v"}', ['array' => ['k' => 'v']]],
			['application/json; charset=utf-8', "k: v\n", ['array' => ['k' => 'v']]],
			['text/plain', 'null', ['status' => 400, 'data' => ['error' => 'Failed to parse response body as JSON or YAML', 'Content-Type' => 'text/plain']]],
		];
		foreach ($cases as [$type, $body, $expected]) {
			$headers = [];
			if ($type !== '') {
				$headers['Content-Type'] = $type;
			}

			$handler = $this->handler(new Response(200, $headers, $body));
			$this->assertSame($expected, $this->shape($handler->getUploadedJson(['url' => 'https://example.test/c', 'json' => '{"x":1}'], null)), $type.' '.$body);
		}
	}//end testUrl()

	/**
	 * YAML declared by Content-Type that does not parse throws.
	 *
	 * @return void
	 */
	public function testUrlBadYamlThrows(): void {
		$handler = $this->handler(new Response(200, ['Content-Type' => 'application/yaml'], "a: [unclosed\n"));
		$this->expectException(ParseException::class);
		$handler->getUploadedJson(['url' => 'https://example.test/c'], null);
	}//end testUrlBadYamlThrows()

	/**
	 * A url that cannot be fetched answers 400 with the client's message.
	 *
	 * @return void
	 */
	public function testUrlFails(): void {
		$this->assertSame(
			['status' => 400, 'data' => ['error' => 'Failed to do a GET api-call on url: https://example.test/c connection refused']],
			$this->shape($this->handler()->getUploadedJson(['url' => 'https://example.test/c'], []))
		);
	}//end testUrlFails()

	/**
	 * A json blob is used as given or decoded; an empty url falls through to it.
	 *
	 * @return void
	 */
	public function testJsonBody(): void {
		$handler = $this->handler();
		$this->assertSame(['array' => ['a' => ['b' => 1]]], $this->shape($handler->getUploadedJson(['url' => '', 'json' => '{"a":{"b":1}}'], null)));
		$this->assertSame(['array' => ['a' => 1]], $this->shape($handler->getUploadedJson(['json' => ['a' => 1]], [])));
		$this->assertSame(
			['status' => 400, 'data' => ['error' => 'Failed to decode JSON input']],
			$this->shape($handler->getUploadedJson(['json' => 'not json'], null))
		);
	}//end testJsonBody()
}//end class
