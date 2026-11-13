<?php

/**
 * Every external call that carries a document's text is recorded per document,
 * before it is sent, and a call whose record cannot be written is not made.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-external-call-that-carries-a-documents-text-is-recorded-per-document-req-adi-005
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Anonymisation;

use DateTime;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Exception\TextDisclosureNotRecordedException;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Files\Db\TextDisclosure;
use OCA\OpenRegister\Files\Db\TextDisclosureMapper;
use OCA\OpenRegister\Files\Service\TextExtraction\AnalyzeCall;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityDetectionQuery;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionStores;
use OCA\OpenRegister\Service\Anonymisation\AnonymiserRequestInterface;
use OCA\OpenRegister\Service\Anonymisation\TextDisclosureRecorder;
use OCA\OpenRegister\Service\Anonymisation\TextDisclosureSource;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionBackends;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * The disclosure recorder and the outbound sites that call it.
 */
#[CoversClass(TextDisclosureRecorder::class)]
#[CoversClass(TextDisclosureSource::class)]
#[UsesClass(TextDisclosure::class)]
#[UsesClass(TextDisclosureNotRecordedException::class)]
#[UsesClass(EntityRecognitionHandler::class)]
#[UsesClass(EntityRecognitionStores::class)]
#[UsesClass(EntityRecognitionBackends::class)]
#[UsesClass(EntityDetectionQuery::class)]
#[UsesClass(AnalyzeCall::class)]
final class TextDisclosureRecorderTest extends TestCase {
	/**
	 * Methods that open an HTTP client but send no document text, with the reason.
	 *
	 * @var array<string, string>
	 */
	private const NO_TEXT_SENT = [
		'OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService::requestPresidioHealth' => 'a GET of /health, no body',
	];

	/**
	 * The directories whose classes talk to detection and extraction services.
	 *
	 * @var list<string>
	 */
	private const OUTBOUND_DIRS = [
		'lib/Files/Service/TextExtraction',
		'lib/Service/TextExtraction',
		'lib/Service/Anonymisation',
	];

	/**
	 * A handler whose Presidio endpoint is configured.
	 *
	 * @param IClientService              $clientService The HTTP client.
	 * @param TextDisclosureRecorder|null $recorder      The disclosure recorder.
	 *
	 * @return EntityRecognitionHandler
	 */
	private function presidioHandler(IClientService $clientService, ?TextDisclosureRecorder $recorder): EntityRecognitionHandler {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getFileSettingsOnly')->willReturn(['presidioApiEndpoint' => 'https://presidio.example:5002/']);

		return new EntityRecognitionHandler(
			stores: new EntityRecognitionStores(
				chunkMapper: $this->createMock(ChunkMapper::class),
				entityMapper: $this->createMock(GdprEntityMapper::class),
				entityRelationMapper: $this->createMock(EntityRelationMapper::class),
				db: $this->createMock(IDBConnection::class)
			),
			backends: new EntityRecognitionBackends(
				settingsService: $settings,
				anonymisationBackendService: $this->createMock(AnonymiserRequestInterface::class),
				clientService: $clientService,
				disclosureRecorder: $recorder
			),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end presidioHandler()

	/**
	 * Run Presidio detection on a file's text.
	 *
	 * @param EntityRecognitionHandler $handler The handler.
	 * @param string                   $text    The text.
	 *
	 * @return array The detected entities.
	 */
	private function detectInFile(EntityRecognitionHandler $handler, string $text): array {
		$dispatch = new ReflectionMethod(EntityRecognitionHandler::class, 'detectEntities');

		return $dispatch->invoke(
			$handler,
			new EntityDetectionQuery(text: $text, entityTypes: null, confidenceThreshold: 0.5, source: new TextDisclosureSource(sourceType: 'file', sourceId: 31)),
			EntityRecognitionHandler::METHOD_PRESIDIO
		);
	}//end detectInFile()

	/**
	 * Every method that opens an HTTP client records a disclosure, or is listed as sending no text.
	 *
	 * @return void
	 */
	public function testEveryOutboundSiteRecords(): void {
		$root = dirname(__DIR__, 4);
		$sites = [];
		foreach (self::OUTBOUND_DIRS as $dir) {
			foreach (glob($root . '/' . $dir . '/*.php') as $file) {
				$class = $this->classIn(file: $file);
				if ($class === null || class_exists($class) === false) {
					continue;
				}

				$sites = array_merge($sites, $this->outboundMethods(class: $class));
			}
		}

		// The one known site must be found, or the scan proves nothing.
		$this->assertContains(EntityRecognitionHandler::class . '::postAnalyzeRequest', array_keys($sites));

		foreach ($sites as $site => $body) {
			if (isset(self::NO_TEXT_SENT[$site]) === true) {
				continue;
			}

			$this->assertStringContainsString(
				'recordDisclosure(',
				$body,
				$site . ' opens an HTTP client without recording a text disclosure'
			);
		}
	}//end testEveryOutboundSiteRecords()

	/**
	 * The record names the file, the service, the host and the byte count, and is written before the call.
	 *
	 * @return void
	 */
	public function testAPresidioCallIsRecordedBeforeItIsSent(): void {
		$order = [];

		$mapper = $this->createMock(TextDisclosureMapper::class);
		$mapper->expects($this->once())->method('insert')->willReturnCallback(
			function (TextDisclosure $disclosure) use (&$order): TextDisclosure {
				$order[] = 'record';
				return $disclosure;
			}
		);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn('[]');
		$client = $this->createMock(IClient::class);
		$sentBody = null;
		$client->expects($this->once())->method('post')->willReturnCallback(
			function (string $url, array $options) use (&$order, &$sentBody, $response): IResponse {
				$order[] = 'send ' . $url;
				$sentBody = $options['body'];
				return $response;
			}
		);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$this->detectInFile(handler: $this->presidioHandler(clientService: $clientService, recorder: new TextDisclosureRecorder(mapper: $mapper)), text: 'Jan de Vries');

		$this->assertSame(['record', 'send https://presidio.example:5002/analyze'], $order);
	}//end testAPresidioCallIsRecordedBeforeItIsSent()

	/**
	 * The stored record carries the file id, service, host with port, and the bytes sent.
	 *
	 * @return void
	 */
	public function testTheRecordNamesFileServiceHostAndBytes(): void {
		$mapper = $this->createMock(TextDisclosureMapper::class);
		$mapper->method('insert')->willReturnArgument(0);

		$before = new DateTime();
		$record = (new TextDisclosureRecorder(mapper: $mapper))->record(
			source: new TextDisclosureSource(sourceType: 'file', sourceId: 31),
			service: 'Presidio',
			url: 'https://presidio.example:5002/analyze',
			bytes: 42
		);

		$this->assertSame(
			[31, 'file', 31, 'Presidio', 'presidio.example:5002', 42],
			[$record->getFileId(), $record->getSourceType(), $record->getSourceId(), $record->getService(), $record->getHost(), $record->getBytes()]
		);
		$this->assertGreaterThanOrEqual($before->getTimestamp(), $record->getDisclosedAt()->getTimestamp());
	}//end testTheRecordNamesFileServiceHostAndBytes()

	/**
	 * An object's text is recorded under the object, with no file id; a URL without a host keeps the URL.
	 *
	 * @return void
	 */
	public function testAnObjectSourceHasNoFileId(): void {
		$mapper = $this->createMock(TextDisclosureMapper::class);
		$mapper->method('insert')->willReturnArgument(0);

		$record = (new TextDisclosureRecorder(mapper: $mapper))->record(
			source: new TextDisclosureSource(sourceType: 'object', sourceId: 8),
			service: 'OpenAnonymiser',
			url: 'not a url',
			bytes: 3
		);

		$this->assertSame([null, 'object', 8, 'not a url'], [$record->getFileId(), $record->getSourceType(), $record->getSourceId(), $record->getHost()]);
	}//end testAnObjectSourceHasNoFileId()

	/**
	 * A disclosure store that refuses the write stops the call, and detection fails naming the record.
	 *
	 * @return void
	 */
	public function testAFailingRecordStopsTheExternalCall(): void {
		$mapper = $this->createMock(TextDisclosureMapper::class);
		$mapper->method('insert')->willThrowException(new RuntimeException('disk full'));

		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('post');
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$this->expectException(TextDisclosureNotRecordedException::class);
		$this->expectExceptionMessage('The text disclosure record could not be written, so the call to Presidio was not made');

		$this->detectInFile(handler: $this->presidioHandler(clientService: $clientService, recorder: new TextDisclosureRecorder(mapper: $mapper)), text: 'Jan de Vries');
	}//end testAFailingRecordStopsTheExternalCall()

	/**
	 * Without a recorder no external call is made either.
	 *
	 * @return void
	 */
	public function testWithoutARecorderNoExternalCallIsMade(): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('post');
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$this->expectException(TextDisclosureNotRecordedException::class);

		$this->detectInFile(handler: $this->presidioHandler(clientService: $clientService, recorder: null), text: 'Jan de Vries');
	}//end testWithoutARecorderNoExternalCallIsMade()

	/**
	 * The fully qualified class declared in a file, or null.
	 *
	 * @param string $file The PHP file.
	 *
	 * @return string|null
	 */
	private function classIn(string $file): ?string {
		$source = (string)file_get_contents($file);
		if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1
			|| preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $cls) !== 1
		) {
			return null;
		}

		return $ns[1] . '\\' . $cls[1];
	}//end classIn()

	/**
	 * The methods of a class that open an HTTP client, with their source.
	 *
	 * @param string $class The class.
	 *
	 * @return array<string, string> `Class::method` to the method's source.
	 */
	private function outboundMethods(string $class): array {
		$reflection = new ReflectionClass($class);
		$fileName = $reflection->getFileName();
		if ($fileName === false) {
			return [];
		}

		$lines = file($fileName);
		$sites = [];
		foreach ($reflection->getMethods() as $method) {
			if ($method->getDeclaringClass()->getName() !== $class || $method->getStartLine() === false) {
				continue;
			}

			$body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
			if (str_contains($body, 'newClient(') === true) {
				$sites[$class . '::' . $method->getName()] = $body;
			}
		}

		return $sites;
	}//end outboundMethods()
}//end class
