<?php

/**
 * Entity detection against a real HTTP analyze endpoint, and the BSN/phone
 * overlap rule of the regex detector.
 *
 * The analyze backends are reached through Nextcloud's HTTP client, here backed
 * by Guzzle, so a PHP built-in server with a stand-in router plays the backend.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\TextExtraction;

use OCA\OpenRegister\Service\Anonymisation\TextDisclosureRecorder;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Exception\AnalyzeRequestRejectedException;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityDetectionQuery;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionBackends;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionStores;
use OCA\OpenRegister\Service\Anonymisation\AnonymiserRequestInterface;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use OCA\OpenRegister\Service\Anonymisation\AnonymiserCall;

class EntityRecognitionHandlerBehaviourTest extends TestCase {
	use GuzzleClientServiceTrait;

	/** @var array<int, array{0: string, 1: array}> */
	private array $calls = [];

	/** @var resource|null */
	private static $server = null;

	private static string $base = '';

	private SettingsServiceInterface&MockObject $settings;

	private AnonymiserRequestInterface&MockObject $anonymiser;

	private LoggerInterface&MockObject $logger;

	private EntityRecognitionHandler $handler;

	public static function setUpBeforeClass(): void {
		$probe = stream_socket_server('tcp://127.0.0.1:0');
		$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
		fclose($probe);

		$router = __DIR__ . '/fixtures/analyze-router.php';
		self::$server = proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
			[0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
			$pipes
		);
		self::$base = 'http://127.0.0.1:' . $port;

		for ($i = 0; $i < 100; $i++) {
			$socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
			if ($socket !== false) {
				fclose($socket);
				return;
			}

			usleep(50000);
		}
	}//end setUpBeforeClass()

	public static function tearDownAfterClass(): void {
		if (self::$server !== null) {
			proc_terminate(self::$server);
			proc_close(self::$server);
			self::$server = null;
		}
	}//end tearDownAfterClass()

	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsServiceInterface::class);
		$this->anonymiser = $this->createMock(AnonymiserRequestInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->handler = new EntityRecognitionHandler(
			stores: new EntityRecognitionStores(
				chunkMapper: $this->createMock(ChunkMapper::class),
				entityMapper: $this->createMock(GdprEntityMapper::class),
				entityRelationMapper: $this->createMock(EntityRelationMapper::class),
				db: $this->createMock(IDBConnection::class)
			),
			backends: new EntityRecognitionBackends(
				settingsService: $this->settings,
				anonymisationBackendService: $this->anonymiser,
				clientService: $this->guzzleClientService($this->calls),
				disclosureRecorder: $this->createMock(TextDisclosureRecorder::class)
			),
			logger: $this->logger
		);
	}//end setUp()

	/**
	 * Run the handler's dispatcher for one method.
	 */
	private function detect(string $method, string $text, ?array $types = null): array {
		$dispatch = new ReflectionMethod(EntityRecognitionHandler::class, 'detectEntities');

		return array_values(
			$dispatch->invoke(
				$this->handler,
				new EntityDetectionQuery(text: $text, entityTypes: $types, confidenceThreshold: 0.5),
				$method
			)
		);
	}//end detect()

	private function types(array $entities): array {
		return array_column($entities, 'type');
	}//end types()

	private function presidioAt(string $endpoint): void {
		$this->settings->method('getFileSettingsOnly')->willReturn(['presidioApiEndpoint' => $endpoint]);
	}//end presidioAt()

	private function expectErrorLogged(string $fragment): void {
		$this->logger->expects($this->atLeastOnce())
			->method('error')
			->with($this->stringContains($fragment), $this->anything());
	}//end expectErrorLogged()

	public function testPresidioResultsAreConvertedAndLowScoresDropped(): void {
		$this->presidioAt(self::$base . '/ok/');

		$entities = $this->detect(EntityRecognitionHandler::METHOD_PRESIDIO, 'Jan Jansen', ['PERSON', 'EMAIL']);

		$this->assertSame(['PERSON', 'X_ECHO'], $this->types($entities));
		$this->assertSame('Jan ', $entities[0]['value']);
		$this->assertSame(0.9, $entities[0]['confidence']);
		$this->assertSame(EntityRecognitionHandler::METHOD_PRESIDIO, $entities[0]['method']);
		$this->assertSame(
			['text' => 'Jan Jansen', 'language' => 'en', 'entities' => ['PERSON', 'EMAIL_ADDRESS']],
			json_decode($entities[1]['value'], true)
		);
	}//end testPresidioResultsAreConvertedAndLowScoresDropped()

	public function testTheAnalyzeCallAsksForNoLocalAddressExemption(): void {
		$this->presidioAt(self::$base . '/ok/');

		$this->detect(EntityRecognitionHandler::METHOD_PRESIDIO, 'Jan Jansen', ['PERSON']);

		$this->assertCount(1, $this->calls);
		[$uri, $options] = $this->calls[0];
		$this->assertSame(self::$base . '/ok/analyze', $uri);
		$this->assertArrayNotHasKey('nextcloud', $options);
		$this->assertSame('application/json', $options['headers']['Content-Type']);
		$this->assertFalse($options['http_errors']);
	}//end testTheAnalyzeCallAsksForNoLocalAddressExemption()

	public function testWithoutAnHttpClientNoRequestIsMadeAndRegexIsUsed(): void {
		$this->presidioAt(self::$base . '/ok/');
		$this->expectErrorLogged('Presidio connection error: no HTTP client available');
		$handler = new EntityRecognitionHandler(
			stores: new EntityRecognitionStores(
				chunkMapper: $this->createMock(ChunkMapper::class),
				entityMapper: $this->createMock(GdprEntityMapper::class),
				entityRelationMapper: $this->createMock(EntityRelationMapper::class),
				db: $this->createMock(IDBConnection::class)
			),
			backends: new EntityRecognitionBackends(settingsService: $this->settings, anonymisationBackendService: $this->anonymiser),
			logger: $this->logger
		);
		$dispatch = new ReflectionMethod(EntityRecognitionHandler::class, 'detectEntities');

		$entities = $dispatch->invoke($handler, new EntityDetectionQuery(text: 'a@example.com', entityTypes: ['EMAIL'], confidenceThreshold: 0.5), EntityRecognitionHandler::METHOD_PRESIDIO);

		$this->assertSame(['EMAIL'], $this->types(array_values($entities)));
	}//end testWithoutAnHttpClientNoRequestIsMadeAndRegexIsUsed()

	public function testPresidioRequestErrorFallsBackToRegex(): void {
		$this->presidioAt(self::$base . '/reject');
		$this->expectErrorLogged('Presidio detection failed');

		$entities = $this->detect(EntityRecognitionHandler::METHOD_PRESIDIO, 'mail a@example.com', ['EMAIL']);

		$this->assertSame(['EMAIL'], $this->types($entities));
		$this->assertSame('a@example.com', $entities[0]['value']);
	}//end testPresidioRequestErrorFallsBackToRegex()

	public function testPresidioServerErrorFallsBackToRegex(): void {
		$this->presidioAt(self::$base . '/down');
		$this->expectErrorLogged('Presidio returned HTTP 503');

		$this->assertSame(['EMAIL'], $this->types($this->detect(EntityRecognitionHandler::METHOD_PRESIDIO, 'a@example.com', ['EMAIL'])));
	}//end testPresidioServerErrorFallsBackToRegex()

	public function testPresidioUnparseableBodyFallsBackToRegex(): void {
		$this->presidioAt(self::$base . '/junk');
		$this->expectErrorLogged('Failed to parse Presidio response');

		$this->assertSame(['EMAIL'], $this->types($this->detect(EntityRecognitionHandler::METHOD_PRESIDIO, 'a@example.com', ['EMAIL'])));
	}//end testPresidioUnparseableBodyFallsBackToRegex()

	public function testPresidioUnreachableFallsBackToRegex(): void {
		$this->presidioAt('http://127.0.0.1:1');
		$this->expectErrorLogged('Presidio connection error');

		$this->assertSame(['EMAIL'], $this->types($this->detect(EntityRecognitionHandler::METHOD_PRESIDIO, 'a@example.com', ['EMAIL'])));
	}//end testPresidioUnreachableFallsBackToRegex()

	public function testExternalOpenAnonymiserSendsBasicAuthAndReadsPiiEntities(): void {
		$this->settings->method('getFileSettingsOnly')->willReturn(
			[
				'openAnonymiserSource' => 'external',
				'openAnonymiserApiEndpoint' => self::$base . '/auth/',
				'openAnonymiserUsername' => ' u ',
			]
		);
		$this->settings->method('getOpenAnonymiserPassword')->willReturn('p');
		$this->anonymiser->expects($this->never())->method('requestOpenAnonymiser');

		$entities = $this->detect(EntityRecognitionHandler::METHOD_OPENANONYMISER, 'Jan');

		$this->assertSame('Basic ' . base64_encode('u:p'), $entities[0]['value']);
		$this->assertSame(0.85, $entities[0]['confidence']);
		$this->assertSame(EntityRecognitionHandler::METHOD_OPENANONYMISER, $entities[0]['method']);
	}//end testExternalOpenAnonymiserSendsBasicAuthAndReadsPiiEntities()

	public function testInternalOpenAnonymiserWithoutAppFallsBackToEndpointWithMappedNames(): void {
		$this->settings->method('getFileSettingsOnly')->willReturn(['openAnonymiserApiEndpoint' => self::$base . '/ok']);
		$this->anonymiser->expects($this->once())->method('requestOpenAnonymiser')->willReturn(null);

		$entities = $this->detect(EntityRecognitionHandler::METHOD_OPENANONYMISER, 'Jan Jansen', ['PERSON', 'SSN', 'PHONE']);

		$echo = $entities[array_search('X_ECHO', $this->types($entities), true)];
		$this->assertSame(
			['text' => 'Jan Jansen', 'language' => 'nl', 'entities' => ['PERSON', 'PHONE_NUMBER']],
			json_decode($echo['value'], true)
		);
	}//end testInternalOpenAnonymiserWithoutAppFallsBackToEndpointWithMappedNames()

	public function testInternalOpenAnonymiserAnswerIsUsedWithoutTheEndpoint(): void {
		$this->settings->method('getFileSettingsOnly')->willReturn(['openAnonymiserApiEndpoint' => 'http://127.0.0.1:1']);
		$this->anonymiser->expects($this->once())
			->method('requestOpenAnonymiser')
			->with(new AnonymiserCall('/api/v1/analyze', ['text' => 'Jan', 'language' => 'nl'], 'POST'))
			->willReturn([['entity_type' => 'PERSON', 'start' => 0, 'end' => 3, 'score' => 0.7]]);
		$this->logger->expects($this->never())->method('error');

		$entities = $this->detect(EntityRecognitionHandler::METHOD_OPENANONYMISER, 'Jan');

		$this->assertSame([['type' => 'PERSON', 'value' => 'Jan']], array_map(static fn (array $e): array => ['type' => $e['type'], 'value' => $e['value']], $entities));
	}//end testInternalOpenAnonymiserAnswerIsUsedWithoutTheEndpoint()

	public function testOpenAnonymiserFilterOfUnknownNamesAsksForNothing(): void {
		$this->settings->method('getFileSettingsOnly')->willReturn(['openAnonymiserApiEndpoint' => self::$base . '/ok']);
		$this->anonymiser->expects($this->never())->method('requestOpenAnonymiser');

		$this->assertSame([], $this->detect(EntityRecognitionHandler::METHOD_OPENANONYMISER, 'BSN 111222333', ['SSN']));
	}//end testOpenAnonymiserFilterOfUnknownNamesAsksForNothing()

	public function testOpenAnonymiserRequestErrorIsRethrownNotHidden(): void {
		$this->settings->method('getFileSettingsOnly')->willReturn(
			['openAnonymiserSource' => 'external', 'openAnonymiserApiEndpoint' => self::$base . '/reject']
		);
		$this->expectErrorLogged('a request error; the regex detector is not used for it');

		$this->expectException(AnalyzeRequestRejectedException::class);
		$this->detect(EntityRecognitionHandler::METHOD_OPENANONYMISER, 'a@example.com');
	}//end testOpenAnonymiserRequestErrorIsRethrownNotHidden()

	public function testExternalOpenAnonymiserWithoutEndpointWarnsAndUsesRegex(): void {
		$this->settings->method('getFileSettingsOnly')->willReturn(['openAnonymiserSource' => 'external']);
		$this->logger->expects($this->exactly(2))
			->method('warning')
			->with(
				$this->logicalOr(
					$this->stringContains('external endpoint not configured'),
					$this->stringContains('OpenAnonymiser unreachable')
				),
				$this->anything()
			);

		$entities = $this->detect(EntityRecognitionHandler::METHOD_OPENANONYMISER, 'a@example.com', ['EMAIL']);

		$this->assertSame(['EMAIL'], $this->types($entities));
		$this->assertSame('a@example.com', $entities[0]['value']);
	}//end testExternalOpenAnonymiserWithoutEndpointWarnsAndUsesRegex()

	public function testInternalOpenAnonymiserWithoutEndpointUsesRegexWithoutTheExternalWarning(): void {
		$this->settings->method('getFileSettingsOnly')->willReturn([]);
		$this->anonymiser->method('requestOpenAnonymiser')->willReturn(null);
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('OpenAnonymiser unreachable'), $this->anything());

		$this->assertSame(['EMAIL'], $this->types($this->detect(EntityRecognitionHandler::METHOD_OPENANONYMISER, 'a@example.com', ['EMAIL'])));
	}//end testInternalOpenAnonymiserWithoutEndpointUsesRegexWithoutTheExternalWarning()

	public function testOpenAnonymiserFailureOtherThanARequestErrorFallsBackToRegex(): void {
		$this->settings->method('getFileSettingsOnly')->willThrowException(new RuntimeException('settings unreadable'));
		$this->expectErrorLogged('OpenAnonymiser detection failed: settings unreadable');

		$this->assertSame(['EMAIL'], $this->types($this->detect(EntityRecognitionHandler::METHOD_OPENANONYMISER, 'a@example.com', ['EMAIL'])));
	}//end testOpenAnonymiserFailureOtherThanARequestErrorFallsBackToRegex()

	public function testAPhoneMatchInsideAValidBsnIsDroppedButOtherPhonesStay(): void {
		$this->settings->method('getFileSettingsOnly')->willReturn(['entityPatternSets' => ['nl']]);
		$entities = $this->detect(EntityRecognitionHandler::METHOD_REGEX, 'BSN 111222333 of bel 0612345678', ['SSN', 'PHONE']);

		$byType = [];
		foreach ($entities as $entity) {
			$byType[$entity['type']][] = $entity['value'];
		}

		$this->assertSame(['111222333'], $byType['SSN']);
		$this->assertSame(['0612345678'], $byType['PHONE']);
	}//end testAPhoneMatchInsideAValidBsnIsDroppedButOtherPhonesStay()

	public function testWithoutAValidBsnThePhoneMatchIsKept(): void {
		$entities = $this->detect(EntityRecognitionHandler::METHOD_REGEX, 'nummer 111222334', ['SSN', 'PHONE']);

		$this->assertSame(['PHONE'], $this->types($entities));
	}//end testWithoutAValidBsnThePhoneMatchIsKept()
	/**
	 * Store one regex e-mail hit from an object chunk, with the given container.
	 *
	 * @return EntityRelation|null The relation handed to the mapper.
	 */
	private function storeFromObjectChunk(?ContainerInterface $container): ?EntityRelation {
		$entityMapper = $this->createMock(GdprEntityMapper::class);
		$existing = new GdprEntity();
		$existing->setId(77);
		$entityMapper->method('findEntitiesPublic')->willReturn([$existing]);
		$relationMapper = $this->createMock(EntityRelationMapper::class);
		$captured = null;
		$relationMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(
				static function (EntityRelation $relation) use (&$captured): EntityRelation {
					$captured = $relation;
					return $relation;
				}
			);

		$db = $this->createMock(IDBConnection::class);
		$query = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'setMaxResults'] as $fluent) {
			$query->method($fluent)->willReturnSelf();
		}

		$query->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$db->method('getQueryBuilder')->willReturn($query);

		$handler = new EntityRecognitionHandler(
			stores: new EntityRecognitionStores(
				chunkMapper: $this->createMock(ChunkMapper::class),
				entityMapper: $entityMapper,
				entityRelationMapper: $relationMapper,
				db: $db
			),
			backends: new EntityRecognitionBackends(
				settingsService: $this->settings,
				anonymisationBackendService: $this->anonymiser,
				container: $container
			),
			logger: $this->logger
		);

		$chunk = new Chunk();
		$chunk->setId(5);
		$chunk->setTextContent('mail a@example.com');
		$chunk->setSourceType('object');
		$chunk->setSourceId(42);

		$result = $handler->extractFromChunk($chunk, ['method' => 'regex', 'entity_types' => ['EMAIL']]);
		$this->assertSame(1, $result['entities_found']);

		return $captured;
	}//end storeFromObjectChunk()

	public function testObjectRelationCarriesTheOwningRegisterSchemaAndUuid(): void {
		$object = new ObjectEntity();
		$object->setRegister('3');
		$object->setSchema('8');
		$object->setUuid('0b6f3c1e-1111-4222-8333-444455556666');
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->expects($this->once())->method('find')->with(42, null, null, false, false, false)->willReturn($object);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(MagicMapperInterface::class)->willReturn($mapper);

		$relation = $this->storeFromObjectChunk($container);

		$this->assertSame(42, $relation->getObjectId());
		$this->assertSame(77, $relation->getEntityId());
		$this->assertSame('3', $relation->getRegisterId());
		$this->assertSame('8', $relation->getSchemaId());
		$this->assertSame('0b6f3c1e-1111-4222-8333-444455556666', $relation->getObjectUuid());
	}//end testObjectRelationCarriesTheOwningRegisterSchemaAndUuid()

	public function testObjectThatCannotBeResolvedLeavesTheContextColumnsEmpty(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no mapper'));

		$relation = $this->storeFromObjectChunk($container);

		$this->assertSame(42, $relation->getObjectId());
		$this->assertNull($relation->getRegisterId());
		$this->assertNull($relation->getObjectUuid());
	}//end testObjectThatCannotBeResolvedLeavesTheContextColumnsEmpty()
}//end class
