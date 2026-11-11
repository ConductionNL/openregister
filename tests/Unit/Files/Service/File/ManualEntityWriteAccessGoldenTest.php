<?php

/**
 * ManualEntityWriteAccessGoldenTest
 *
 * Pins the write-access gate of ManualEntityService::addManualEntity(): the
 * allowed outcome (the gate lets the call through to the chunk lookup) and
 * every refusal with its reason, message, previous exception and log line.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\File;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Exception\ManualEntityException;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Service\File\ChunkTextMatcher;
use OCA\OpenRegister\Files\Service\File\ManualEntityCatalogue;
use OCA\OpenRegister\Files\Service\File\ManualEntityRecording;
use OCA\OpenRegister\Files\Service\File\ManualEntityRequest;
use OCA\OpenRegister\Files\Service\File\ManualEntityService;
use OCP\Files\File as NcFile;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Allowed and refused outcomes of the manual-entity write-access gate.
 */
final class ManualEntityWriteAccessGoldenTest extends TestCase {

	/**
	 * Log lines as [level, message, context without `line`].
	 *
	 * @var list<array>
	 */
	private array $logs = [];

	/**
	 * Whether the chunk lookup (the first step after the gate) ran.
	 *
	 * @var bool
	 */
	private bool $reachedChunks = false;

	/**
	 * The service over a root folder answering with the given user-folder behaviour.
	 *
	 * @param callable $getById What the actor's user folder returns (or throws) for getById().
	 *
	 * @return ManualEntityService
	 */
	private function service(callable $getById): ManualEntityService {
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnCallback($getById);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('op1')->willReturn($folder);

		$chunks = $this->createMock(ChunkMapper::class);
		$chunks->method('findBySource')->willReturnCallback(
			function (): array {
				$this->reachedChunks = true;
				return [];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['line']);
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new ManualEntityService(
			catalogue: new ManualEntityCatalogue(
				gdprEntityMapper: $this->createMock(GdprEntityMapper::class),
				entityRelationMapper: $this->createMock(EntityRelationMapper::class),
				chunkMapper: $chunks,
				matcher: new ChunkTextMatcher()
			),
			recording: new ManualEntityRecording(
				auditTrailMapper: $this->createMock(AuditTrailMapper::class),
				rootFolder: $root,
				db: $this->createMock(IDBConnection::class)
			),
			logger: $logger
		);
	}//end service()

	/**
	 * The request for file 42 by op1.
	 *
	 * @return ManualEntityRequest
	 */
	private function request(): ManualEntityRequest {
		$actor = $this->createMock(IUser::class);
		$actor->method('getUID')->willReturn('op1');
		return new ManualEntityRequest(fileId: 42, value: 'Jan', type: 'PERSON', actor: $actor);
	}//end request()

	/**
	 * Run the call and return the exception it ends with.
	 *
	 * @param ManualEntityService $service The service.
	 *
	 * @return ManualEntityException
	 */
	private function refusal(ManualEntityService $service): ManualEntityException {
		try {
			$service->addManualEntity($this->request());
		} catch (ManualEntityException $e) {
			return $e;
		}

		self::fail('no ManualEntityException');
	}//end refusal()

	/**
	 * The source file of the service, as its log context names it.
	 *
	 * @return string
	 */
	private function file(): string {
		return (string)(new \ReflectionClass(ManualEntityService::class))->getFileName();
	}//end file()

	/**
	 * A writable file passes the gate: the call goes on to the chunk lookup, nothing is logged.
	 *
	 * @return void
	 */
	public function testAWritableFilePasses(): void {
		$file = $this->createMock(NcFile::class);
		$file->method('isUpdateable')->willReturn(true);

		$e = $this->refusal($this->service(static fn (): array => [$file]));

		self::assertTrue($this->reachedChunks);
		self::assertSame('File 42 has no extracted chunks; run text extraction first.', $e->getMessage());
		self::assertSame([], $this->logs);
	}//end testAWritableFilePasses()

	/**
	 * A failing lookup is an internal error carrying the cause.
	 *
	 * @return void
	 */
	public function testALookupFailureIsAnInternalError(): void {
		$cause = new RuntimeException('storage offline');

		$e = $this->refusal($this->service(static function () use ($cause): array {
			throw $cause;
		}));

		self::assertFalse($this->reachedChunks);
		self::assertSame(ManualEntityException::REASON_INTERNAL_ERROR, $e->getReason());
		self::assertSame('File lookup failed: storage offline', $e->getMessage());
		self::assertSame($cause, $e->getPrevious());
		self::assertSame(
			[['warning', '[ManualEntityService] File lookup raised exception', ['file' => $this->file(), 'fileId' => 42, 'actor' => 'op1', 'error' => 'storage offline']]],
			$this->logs
		);
	}//end testALookupFailureIsAnInternalError()

	/**
	 * A file the actor cannot see is refused as not extracted (no oracle).
	 *
	 * @return void
	 */
	public function testAnInvisibleFileIsRefused(): void {
		$e = $this->refusal($this->service(static fn (): array => []));

		self::assertFalse($this->reachedChunks);
		self::assertSame(ManualEntityException::REASON_FILE_NOT_EXTRACTED, $e->getReason());
		self::assertSame('File 42 is not accessible to the acting user.', $e->getMessage());
		self::assertNull($e->getPrevious());
		self::assertSame(
			[['info', '[ManualEntityService] File not visible to actor', ['file' => $this->file(), 'fileId' => 42, 'actor' => 'op1']]],
			$this->logs
		);
	}//end testAnInvisibleFileIsRefused()

	/**
	 * A read-only file and a node that is not a file are refused as forbidden.
	 *
	 * @return void
	 */
	public function testReadOnlyOrNonFileIsForbidden(): void {
		$readOnly = $this->createMock(NcFile::class);
		$readOnly->method('isUpdateable')->willReturn(false);
		$folder = $this->createMock(Folder::class);

		$first = $this->refusal($this->service(static fn (): array => [$readOnly, $folder]));
		$second = $this->refusal($this->service(static fn (): array => [$folder, $readOnly]));

		self::assertFalse($this->reachedChunks);
		foreach ([$first, $second] as $e) {
			self::assertSame(ManualEntityException::REASON_FORBIDDEN, $e->getReason());
			self::assertSame('write access to file required', $e->getMessage());
			self::assertNull($e->getPrevious());
		}

		$base = ['file' => $this->file(), 'fileId' => 42, 'actor' => 'op1'];
		self::assertSame(
			[
				['info', '[ManualEntityService] Write-access denied on target file', $base + ['isFile' => true, 'isUpdateable' => false]],
				['info', '[ManualEntityService] Write-access denied on target file', $base + ['isFile' => false, 'isUpdateable' => false]],
			],
			$this->logs
		);
	}//end testReadOnlyOrNonFileIsForbidden()
}//end class
