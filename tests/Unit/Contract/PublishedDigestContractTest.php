<?php

/**
 * The verify response and the published block opencatalogi and portaliq read (published-file-digest 3.1).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Contract
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Contract;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\FileDigest;
use OCA\OpenRegister\Db\FileDigestMapper;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Service\File\PublishedDigestReader;
use OCA\OpenRegister\Service\File\PublishedDigestService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FilesControllerFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The key names and value types a consumer may rely on; renaming one breaks an app this repo cannot see.
 *
 * @covers \OCA\OpenRegister\Service\File\PublishedDigestReader
 * @covers \OCA\OpenRegister\Files\Controller\FilesController
 */
class PublishedDigestContractTest extends TestCase {

	private const BYTES = 'besluit op Woo-verzoek';

	/**
	 * An anonymous verify of a published file answers exactly these keys.
	 *
	 * @return void
	 */
	public function testTheVerifyResponseKeys(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getEtag')->willReturn('etag-1');
		$file->method('getSize')->willReturn(strlen(self::BYTES));

		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFileById')->willReturn($file);
		$fileService->method('isManagedFile')->willReturn(false);

		$fileMapper = $this->createMock(FileMapper::class);
		$fileMapper->method('isFilePublished')->willReturn(true);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$controller = FilesControllerFactory::create(
			request: $this->createMock(IRequest::class),
			fileService: $fileService,
			objectService: $this->createMock(ObjectService::class),
			rootFolder: $this->createMock(IRootFolder::class),
			userManager: $this->createMock(IUserManager::class),
			eventDispatcher: $this->createMock(IEventDispatcher::class),
			fileMapper: $fileMapper,
			userSession: $session,
			publishedDigests: $this->reader()
		);

		$body = $controller->verifyDigest(42)->getData();

		self::assertSame(['recorded', 'current', 'verdict', 'auditRow'], array_keys($body));
		self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $body['recorded']);
		self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $body['current']);
		self::assertContains($body['verdict'], ['identical', 'changed']);
		self::assertSame(['uuid', 'hash', 'previousHash'], array_keys($body['auditRow']));
		self::assertSame(['audit-1', 'h-1', 'h-0'], array_values($body['auditRow']));
	}//end testTheVerifyResponseKeys()

	/**
	 * The published block carries `sha256` and `sealedBy.uuid|hash`.
	 *
	 * @return void
	 */
	public function testThePublishedBlockKeys(): void {
		$block = $this->reader()->publishedOf(fileId: 42);

		self::assertSame(['sha256', 'size', 'publishedAt', 'sealedBy'], array_keys($block));
		self::assertSame(hash('sha256', self::BYTES), $block['sha256']);
		self::assertSame(['uuid', 'hash'], array_keys($block['sealedBy']));
		self::assertSame('2025-11-24T10:00:00+00:00', $block['publishedAt']);
	}//end testThePublishedBlockKeys()

	/**
	 * A real reader over one sealed record.
	 *
	 * @return PublishedDigestReader The reader.
	 */
	private function reader(): PublishedDigestReader {
		$record = new FileDigest();
		$record->setFileId(42);
		$record->setSha256(hash('sha256', self::BYTES));
		$record->setSize(strlen(self::BYTES));
		$record->setEtag('etag-1');
		$record->setPublishedAt(new DateTime('2025-11-24T10:00:00+00:00'));
		$record->setAuditTrailUuid('audit-1');

		$digests = $this->createMock(FileDigestMapper::class);
		$digests->method('findOpenForFile')->willReturn($record);

		$row = new AuditTrail();
		$row->setUuid('audit-1');
		$row->setHash('h-1');
		$row->setPreviousHash('h-0');
		$auditTrails = $this->createMock(AuditTrailMapperInterface::class);
		$auditTrails->method('findTrail')->willReturn([$row]);

		$service = new PublishedDigestService(
			$digests,
			$auditTrails,
			$this->createMock(IFilesMetadataManager::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class)
		);

		return new PublishedDigestReader(digests: $digests, service: $service);
	}//end reader()
}//end class
