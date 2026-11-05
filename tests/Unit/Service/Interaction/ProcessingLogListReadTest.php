<?php

/**
 * List and search reads on a `logReads` schema write a processing-log entry.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Interaction
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-reads-of-personal-data-are-registered-through-the-one-read-registration
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Interaction;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ProcessingLogMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCA\OpenRegister\Service\Interaction\ReadHistoryService;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ProcessingLogService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\IAppContainer;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Drives ObjectService::searchObjectsPaginated() through the real ReadHistoryService
 * and ProcessingLogService; only mappers, the query handlers and Nextcloud are doubles.
 */
class ProcessingLogListReadTest extends TestCase {

	/** @var array<int, \OCA\OpenRegister\Db\ProcessingLogEntry> The entries written. */
	private array $written = [];

	/**
	 * The schema configuration by schema id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $schemaConfig = [];

	/**
	 * The real processing log over doubled mappers.
	 *
	 * @return ProcessingLogService
	 */
	private function processingLog(): ProcessingLogService {
		$logMapper = $this->createMock(ProcessingLogMapper::class);
		$written = &$this->written;
		$logMapper->method('insertBatch')->willReturnCallback(
			static function (array $entries) use (&$written): int {
				$written = array_merge($written, $entries);
				return count($entries);
			}
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function ($id): Schema {
				$schema = $this->createMock(Schema::class);
				$schema->method('getConfiguration')->willReturn(($this->schemaConfig[(string)$id] ?? []));
				return $schema;
			}
		);
		$register = $this->createMock(Register::class);
		$register->method('getConfiguration')->willReturn([]);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$activity = new Verwerkingsactiviteit();
		$activity->setUuid('act-uuid');
		$activity->setStatus('published');
		$vrwMapper = $this->createMock(VerwerkingsactiviteitMapper::class);
		$vrwMapper->method('resolveReference')->willReturn($activity);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ProcessingLogService(
			logMapper: $logMapper,
			vrwMapper: $vrwMapper,
			schemaMapper: $schemaMapper,
			registerMapper: $registerMapper,
			userSession: $session,
			logger: new NullLogger()
		);
	}

	/**
	 * An ObjectService whose database search answers the given results.
	 *
	 * @param array<int, mixed> $results The search results.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $results): ObjectService {
		$processingLog = $this->processingLog();
		$container = $this->createMock(IAppContainer::class);
		$history = new ReadHistoryService(
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(SettingsService::class),
			$container,
			new NullLogger()
		);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				ReadHistoryService::class => $history,
				ProcessingLogService::class => $processingLog,
			}
		);

		$queryHandler = $this->createMock(QueryHandler::class);
		$queryHandler->method('searchObjectsPaginatedDatabase')->willReturn(
			['results' => $results, 'total' => count($results), '@self' => []]
		);
		$searchQueryHandler = $this->createMock(SearchQueryHandler::class);
		$searchQueryHandler->method('getEffectiveRecordingMode')->willReturn('none');
		$logger = $this->createMock(LoggerInterface::class);

		$service = (new ReflectionClass(ObjectService::class))->newInstanceWithoutConstructor();
		(function () use ($container, $queryHandler, $searchQueryHandler, $logger): void {
			$this->container = $container;
			$this->queryHandler = $queryHandler;
			$this->searchQueryHandler = $searchQueryHandler;
			$this->logger = $logger;
		})->call($service);

		return $service;
	}

	/**
	 * An object of the given schema.
	 *
	 * @param string $schema The schema id.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $schema): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid(uniqid('obj-', true));
		$object->setRegister('3');
		$object->setSchema($schema);
		$object->setOrganisation('org-1');
		$object->setObject(['bsn' => '123']);
		return $object;
	}

	public function testAListOnALogReadsSchemaWritesOneCollapsedEntry(): void {
		$this->schemaConfig = ['5' => ['x-openregister-processing' => ['logReads' => true, 'attribution' => ['default' => 'act']]]];

		$this->objectService([$this->object('5'), $this->object('5'), $this->object('5')])->searchObjectsPaginated(query: []);

		$this->assertCount(1, $this->written, 'one entry for the list, not one per object');
		$this->assertSame('read', $this->written[0]->getAction());
		$this->assertSame(3, $this->written[0]->getObjectCount());
		$this->assertSame('5', $this->written[0]->getSchemaId());
		$this->assertSame('jan', $this->written[0]->getActor());
	}

	public function testAMixedSearchWritesOneEntryPerOptedInSchema(): void {
		$this->schemaConfig = [
			'5' => ['x-openregister-processing' => ['logReads' => true, 'attribution' => ['default' => 'act']]],
			'6' => [],
			'7' => ['x-openregister-processing' => ['logReads' => true, 'attribution' => ['default' => 'act']]],
		];

		$this->objectService(
			[$this->object('6'), $this->object('5'), $this->object('7'), $this->object('5')]
		)->searchObjectsPaginated(query: []);

		$counts = [];
		foreach ($this->written as $entry) {
			$counts[$entry->getSchemaId()] = $entry->getObjectCount();
		}

		$this->assertSame(['5' => 2, '7' => 1], $counts, 'schema 6 does not opt in and is not logged');
	}

	public function testAListOnASchemaThatDoesNotOptInWritesNothing(): void {
		$this->schemaConfig = ['6' => []];

		$this->objectService([$this->object('6')])->searchObjectsPaginated(query: []);

		$this->assertSame([], $this->written);
	}
}
