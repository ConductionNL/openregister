<?php

/**
 * A published file's digest is recorded and sealed when it becomes public.
 *
 * Drives FilePublishingHandler::publishFile() with the real digest service,
 * the real digest mapper and the real audit trail mapper. Only the boundaries
 * are doubles: the two database connections (one recording the digest
 * queries, one capturing the audit INSERT), the share mapper, the files
 * metadata store, the Nextcloud file node and the guards.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-files-digest-is-recorded-and-sealed-when-it-becomes-public-req-pfd-001
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use Exception;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\FileDigestMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuardInterface;
use OCA\OpenRegister\Service\File\FilePublishingHandler;
use OCA\OpenRegister\Service\File\PublishedDigestService;
use OCA\OpenRegister\Service\FileHandlerSupportInterface;
use OCA\OpenRegister\Service\Party\PartyIndicatorGuard;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IServerContainer;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\File\PublishedDigestService
 * @covers \OCA\OpenRegister\Service\File\FilePublishingHandler
 * @covers \OCA\OpenRegister\Db\FileDigestMapper
 * @covers \OCA\OpenRegister\Db\FileDigest
 * @uses   \OCA\OpenRegister\Db\AuditFlowAttribution
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Db\AuditTrailMapper
 * @uses   \OCA\OpenRegister\Db\AuditTrailPayloadHelper
 * @uses   \OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices
 * @uses   \OCA\OpenRegister\Db\AuditTrail\AuditedChange
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\Audit\AuditRowAttribution
 * @uses   \OCA\OpenRegister\Service\Audit\AuditTrailCollaborators
 * @uses   \OCA\OpenRegister\Service\Audit\OrganisationContextAttribution
 * @uses   \OCA\OpenRegister\Service\Audit\PurposeAttribution
 * @uses   \OCA\OpenRegister\Service\Audit\TokenAttribution
 * @uses   \OCA\OpenRegister\Service\File\ObjectFileFinder
 * @uses   \OCA\OpenRegister\Service\File\ObjectFileLookup
 * @uses   \OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 * @uses   \OCA\OpenRegister\Service\WriteCause
 */
class PublishedDigestTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FILE_ID = 42;

	private const OBJECT_UUID = 'a1b2c3d4-0000-4000-8000-000000000042';

	private const BYTES = "%PDF-1.7\nA decision, published.\n%%EOF\n";

	/**
	 * Transaction calls on the connection the publication runs on.
	 *
	 * @var list<string>
	 */
	private array $transactions = [];

	/**
	 * The audit INSERT statements, as [sql, params].
	 *
	 * @var list<array{0: string, 1: array}>
	 */
	private array $auditStatements = [];

	/**
	 * When set, the audit INSERT throws it.
	 */
	private ?\Throwable $auditFailure = null;

	/**
	 * Values written to the files metadata, as key => value.
	 *
	 * @var array<string, string>
	 */
	private array $metadata = [];

	private FileMapperInterface&MockObject $shares;

	private FilePublishingHandler $handler;

	/**
	 * The bytes the file node streams on its next fopen().
	 */
	private string $bytes = self::BYTES;

	protected function setUp(): void {
		parent::setUp();

		$this->queryLog = [];
		$this->transactions = [];
		$this->auditStatements = [];
		$this->auditFailure = null;
		$this->metadata = [];
		$this->bytes = self::BYTES;

		$logger = $this->createMock(LoggerInterface::class);
		$this->shares = $this->createMock(FileMapperInterface::class);

		$db = $this->digestConnection();
		$service = new PublishedDigestService(
			new FileDigestMapper($db),
			$this->auditTrailMapper(logger: $logger),
			$this->metadataManager(),
			$db,
			$logger
		);

		$restrictions = $this->createMock(FileRestrictionGuardInterface::class);
		$restrictions->method('isRestricted')->willReturn(false);
		$partyGuard = $this->createMock(PartyIndicatorGuard::class);
		$container = $this->createMock(IServerContainer::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => ($id === FileRestrictionGuardInterface::class ? $restrictions : $partyGuard)
		);

		$this->handler = new FilePublishingHandler(
			$this->createMock(MagicObjectFinderInterface::class),
			$this->shares,
			$logger,
			$container,
			$service
		);
		$this->handler->setFileService($this->fileService());
	}//end setUp()

	/**
	 * Publishing a file through publishFile() records the SHA-256 of its bytes,
	 * the bytes it covers, the etag and the moment, writes the digest into the
	 * file's metadata, and only then creates the share, all in one transaction.
	 */
	public function testPublishingRecordsTheDigest(): void {
		$this->shares->expects($this->once())->method('publishFile')->willReturnCallback(
			function (int $fileId): array {
				$this->transactions[] = 'share ' . $fileId;
				return ['id' => 7, 'token' => 'tok', 'accessUrl' => 'https://x/s/tok'];
			}
		);

		$node = $this->handler->publishFile($this->object(), self::FILE_ID);

		$this->assertSame(self::FILE_ID, $node->getId());

		$rows = $this->digestInserts();
		$this->assertCount(1, $rows, 'one digest record per publication');
		$row = $rows[0];
		$this->assertSame(hash('sha256', self::BYTES), $row['sha256']);
		$this->assertSame(strlen(self::BYTES), $row['size']);
		$this->assertSame(self::FILE_ID, $row['file_id']);
		$this->assertSame(self::OBJECT_UUID, $row['object_uuid']);
		$this->assertSame('etag-1', $row['etag']);
		$this->assertSame('DateTime', $row['published_at']);
		$this->assertArrayNotHasKey('unpublished_at', $row, 'a new record is open');

		$this->assertSame([PublishedDigestService::METADATA_KEY => hash('sha256', self::BYTES)], $this->metadata);

		// The share is the last write of the unit, and the unit commits.
		$this->assertSame(['begin', 'share ' . self::FILE_ID, 'commit'], $this->transactions);
	}//end testPublishingRecordsTheDigest()

	/**
	 * The `file.published` audit row goes through insertAuditTrails() and
	 * carries the file id, the object uuid, the digest and the size, and the
	 * digest record names that row.
	 */
	public function testTheAuditRowCarriesTheDigest(): void {
		$this->shares->method('publishFile')->willReturn(['id' => 7, 'token' => 'tok', 'accessUrl' => 'https://x/s/tok']);

		$this->handler->publishFile($this->object(), self::FILE_ID);

		$this->assertCount(1, $this->auditStatements, 'one audit INSERT through the batched sealing path');
		$audit = $this->auditColumns(statement: $this->auditStatements[0]);

		$this->assertSame(PublishedDigestService::ACTION, $audit['action']);
		$this->assertSame(self::OBJECT_UUID, $audit['object_uuid']);
		$this->assertSame(17, $audit['object']);
		$this->assertSame('officer', $audit['user']);
		$this->assertNull($audit['hash'] ?? null, 'the seal sweep, not the writer, sets the hash');

		$changed = json_decode((string)$audit['changed'], true);
		$this->assertSame(
			[
				'fileId' => self::FILE_ID,
				'objectUuid' => self::OBJECT_UUID,
				'sha256' => hash('sha256', self::BYTES),
				'size' => strlen(self::BYTES),
			],
			$changed
		);

		$this->assertSame($audit['uuid'], $this->digestInserts()[0]['audit_trail_uuid']);
	}//end testTheAuditRowCarriesTheDigest()

	/**
	 * When the audit row cannot be written the publication is rolled back:
	 * no share is created, the digest record and metadata are undone, and the
	 * call fails naming the reason.
	 */
	public function testAFailedAuditWriteKeepsTheFilePrivate(): void {
		$this->auditFailure = new RuntimeException('audit table is read-only');
		$this->shares->expects($this->never())->method('publishFile');

		$thrown = null;
		try {
			$this->handler->publishFile($this->object(), self::FILE_ID);
		} catch (Exception $e) {
			$thrown = $e;
		}

		$this->assertNotNull($thrown, 'the publish call fails');
		$this->assertStringContainsString('not published', $thrown->getMessage());
		$this->assertStringContainsString('sealed in the audit trail', $thrown->getMessage());
		$this->assertStringContainsString('audit table is read-only', $thrown->getMessage());
		$this->assertSame(['begin', 'rollBack'], $this->transactions, 'the digest record and metadata are rolled back');
	}//end testAFailedAuditWriteKeepsTheFilePrivate()

	/**
	 * Unpublishing closes the open record; publishing again writes a new record
	 * with a new audit row, and closes any record left open before it.
	 */
	public function testRepublishingCreatesANewRecord(): void {
		$this->shares->method('publishFile')->willReturn(['id' => 7, 'token' => 'tok', 'accessUrl' => 'https://x/s/tok']);
		$this->shares->expects($this->once())->method('depublishFile')->with(self::FILE_ID)
			->willReturn(['deleted_shares' => 1, 'file_id' => self::FILE_ID]);

		$this->handler->publishFile($this->object(), self::FILE_ID);
		$this->handler->unpublishFile($this->object(), self::FILE_ID);
		$this->bytes = self::BYTES . "Corrected.\n";
		$this->handler->publishFile($this->object(), self::FILE_ID);

		$rows = $this->digestInserts();
		$this->assertCount(2, $rows, 'publishing again creates a new record');
		$this->assertNotSame($rows[0]['uuid'], $rows[1]['uuid']);
		$this->assertSame(hash('sha256', self::BYTES), $rows[0]['sha256']);
		$this->assertSame(hash('sha256', self::BYTES . "Corrected.\n"), $rows[1]['sha256']);

		$this->assertCount(2, $this->auditStatements, 'each publication is sealed by its own audit row');
		$this->assertNotSame(
			$this->auditColumns(statement: $this->auditStatements[0])['uuid'],
			$this->auditColumns(statement: $this->auditStatements[1])['uuid']
		);

		// Three closes of the open record of file 42: after each insert (keeping
		// the new record) and on unpublish (keeping none).
		$closes = $this->closes();
		$this->assertSame([true, false, true], $closes);
		$this->assertSame(
			[PublishedDigestService::METADATA_KEY => hash('sha256', self::BYTES . "Corrected.\n")],
			$this->metadata,
			'the file metadata carries the digest of the open publication'
		);
	}//end testRepublishingCreatesANewRecord()

	/**
	 * The object the file belongs to.
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId(17);
		$object->setUuid(self::OBJECT_UUID);
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setObject(['title' => 'Besluit']);
		return $object;
	}//end object()

	/**
	 * The file service that finds the node and names the share owner.
	 */
	private function fileService(): FileHandlerSupportInterface&MockObject {
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('openregister');

		$fileService = $this->createMock(FileHandlerSupportInterface::class);
		$fileService->method('getFile')->willReturnCallback(fn (): File => $this->node());
		$fileService->method('getUser')->willReturn($owner);
		return $fileService;
	}//end fileService()

	/**
	 * A file node streaming the current bytes; reading it whole is refused.
	 */
	private function node(): File&MockObject {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(self::FILE_ID);
		$node->method('getName')->willReturn('besluit.pdf');
		$node->method('getPath')->willReturn('/openregister/Besluit/besluit.pdf');
		$node->method('getEtag')->willReturn('etag-1');
		$node->expects($this->never())->method('getContent');
		$node->method('fopen')->willReturnCallback(
			function (string $mode) {
				$stream = fopen('php://memory', 'w+');
				fwrite($stream, $this->bytes);
				rewind($stream);
				return $stream;
			}
		);
		return $node;
	}//end node()

	/**
	 * The connection the digest mapper and the publication's transaction run on.
	 */
	private function digestConnection(): IDBConnection&MockObject {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		$db->method('beginTransaction')->willReturnCallback(function (): void {
			$this->transactions[] = 'begin';
		});
		$db->method('commit')->willReturnCallback(function (): void {
			$this->transactions[] = 'commit';
		});
		$db->method('rollBack')->willReturnCallback(function (): void {
			$this->transactions[] = 'rollBack';
		});
		return $db;
	}//end digestConnection()

	/**
	 * The real audit trail mapper over a connection capturing its INSERT.
	 */
	private function auditTrailMapper(LoggerInterface $logger): AuditTrailMapper {
		$db = $this->createMock(IDBConnection::class);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('getTableName')->willReturn('"oc_openregister_audit_trails"');
		$db->method('getQueryBuilder')->willReturn($qb);

		$platform = $this->createMock(\Doctrine\DBAL\Platforms\AbstractPlatform::class);
		$platform->method('quoteIdentifier')->willReturnCallback(static fn (string $identifier): string => '"' . $identifier . '"');
		$db->method('getDatabasePlatform')->willReturn($platform);

		$db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = []): int {
				if ($this->auditFailure !== null) {
					throw $this->auditFailure;
				}

				$this->auditStatements[] = [$sql, $params];
				return 1;
			}
		);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = []): IResult {
				$rows = [];
				foreach ($params as $index => $uuid) {
					$rows[] = ['id' => 900 + $index, 'uuid' => $uuid];
				}

				$rows[] = false;
				$cursor = $this->createMock(IResult::class);
				$cursor->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
				return $cursor;
			}
		);

		$officer = $this->createMock(IUser::class);
		$officer->method('getUID')->willReturn('officer');
		$officer->method('getDisplayName')->willReturn('An officer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($officer);
		$request = $this->createMock(IRequest::class);
		$request->method('getId')->willReturn('req-1');
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not registered'));

		return new AuditTrailMapper($db, new AuditTrailMapperServices($container, $logger, $session, $request));
	}//end auditTrailMapper()

	/**
	 * A files metadata store kept in $this->metadata.
	 */
	private function metadataManager(): IFilesMetadataManager&MockObject {
		$manager = $this->createMock(IFilesMetadataManager::class);
		$manager->method('getMetadata')->willReturnCallback(
			function (): IFilesMetadata {
				$metadata = $this->createMock(IFilesMetadata::class);
				$metadata->method('setString')->willReturnCallback(
					function (string $key, string $value) use ($metadata): IFilesMetadata {
						$this->metadata[$key] = $value;
						return $metadata;
					}
				);
				$metadata->method('hasKey')->willReturnCallback(fn (string $key): bool => isset($this->metadata[$key]));
				$metadata->method('unset')->willReturnCallback(
					function (string $key) use ($metadata): IFilesMetadata {
						unset($this->metadata[$key]);
						return $metadata;
					}
				);
				return $metadata;
			}
		);
		return $manager;
	}//end metadataManager()

	/**
	 * The column => value of every INSERT into the digest table, in order.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function digestInserts(): array {
		$params = $this->loggedParameters();
		$rows = [];
		$current = null;
		foreach ($this->queryLog as $line) {
			if (preg_match('/^(#\d+) insert\(openregister_file_digests\)$/', $line, $match) === 1) {
				$rows[] = [];
				$current = $match[1];
				continue;
			}

			if ($current !== null && preg_match('/^' . $current . ' setValue\((\w+), (:p\d+)\)$/', $line, $match) === 1) {
				$rows[count($rows) - 1][$match[1]] = $params[$match[2]];
			}
		}

		return $rows;
	}//end digestInserts()

	/**
	 * For each close of file 42's open records, in order: whether it keeps one record open.
	 *
	 * @return list<bool>
	 */
	private function closes(): array {
		$params = $this->loggedParameters();
		$closes = [];
		foreach ($this->queryLog as $line) {
			if (preg_match('/^(#\d+) update\(openregister_file_digests, NULL\)$/', $line, $match) === 1) {
				$tag = $match[1];
				$lines = array_values(array_filter($this->queryLog, static fn (string $l): bool => str_starts_with($l, $tag . ' ')));
				$this->assertContains($tag . ' expr.isNull(unpublished_at)', $lines);
				$fileParam = null;
				foreach ($lines as $own) {
					if (preg_match('/expr\.eq\(file_id, (:p\d+), NULL\)$/', $own, $eq) === 1) {
						$fileParam = $eq[1];
					}
				}

				$this->assertSame(self::FILE_ID, $params[$fileParam]);
				$closes[] = ($this->keepPlaceholder(lines: $lines) !== '');
			}
		}

		return $closes;
	}//end closes()

	/**
	 * The placeholder of the kept id in one update's lines, or '' when it keeps none.
	 *
	 * @param list<string> $lines The update's lines.
	 */
	private function keepPlaceholder(array $lines): string {
		foreach ($lines as $line) {
			if (preg_match('/expr\.neq\(id, (:p\d+), NULL\)$/', $line, $match) === 1) {
				return $match[1];
			}
		}

		return '';
	}//end keepPlaceholder()

	/**
	 * Every named parameter's value: strings and ints as given, objects by class.
	 *
	 * @return array<string, mixed>
	 */
	private function loggedParameters(): array {
		$params = [];
		foreach ($this->queryLog as $line) {
			if (preg_match('/^#\d+ param (:p\d+) = (.*), ([^,]+)$/', $line, $match) === 1) {
				$value = $match[2];
				if (preg_match('/^-?\d+$/', $value) === 1) {
					$value = (int)$value;
				}

				$params[$match[1]] = $value;
			}
		}

		return $params;
	}//end loggedParameters()

	/**
	 * The column => value of a single-row audit INSERT.
	 *
	 * @param array{0: string, 1: array} $statement The SQL and its parameters.
	 *
	 * @return array<string, mixed>
	 */
	private function auditColumns(array $statement): array {
		[$sql, $params] = $statement;
		$this->assertSame(1, preg_match('/^INSERT INTO "oc_openregister_audit_trails" \(([^)]*)\) VALUES \([?,]+\)$/', $sql, $match));
		$columns = array_map(static fn (string $column): string => trim($column, ' "'), explode(',', $match[1]));
		$this->assertCount(count($columns), $params);
		return array_combine($columns, $params);
	}//end auditColumns()
}//end class
