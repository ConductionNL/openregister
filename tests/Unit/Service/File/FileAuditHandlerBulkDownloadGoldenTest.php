<?php

/**
 * Golden test for FileAuditHandler::logBulkDownload().
 *
 * Pins the one audit row a ZIP download writes: the object it is tied to, the
 * action, the archive payload, the attribution of a signed-in and of an
 * anonymous caller, the retention hook and the size; and that a failing write
 * answers null instead of breaking the download.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.MissingReturn -- PHPUnit fixtures and tests; the signature IS the contract.

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Service\File\FileAuditHandler;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class FileAuditHandlerBulkDownloadGoldenTest extends TestCase {

	/**
	 * The rows the mapper was asked to insert.
	 *
	 * @var list<AuditTrail>
	 */
	private array $inserted = [];

	/**
	 * How many times the retention hook ran.
	 *
	 * @var integer
	 */
	private int $retentionCalls = 0;

	/**
	 * A handler whose mapper records every insert, or throws when $failing.
	 *
	 * @param IUser|null $user    The signed-in caller, or null for an anonymous one.
	 * @param bool       $failing Whether the insert throws.
	 */
	private function handler(?IUser $user, bool $failing = false): FileAuditHandler {
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('applyRetentionExpiry')->willReturnCallback(
			function (AuditTrail $row): AuditTrail {
				$this->retentionCalls++;
				return $row;
			}
		);
		$mapper->method('insert')->willReturnCallback(
			function (AuditTrail $row) use ($failing): AuditTrail {
				if ($failing === true) {
					throw new RuntimeException('database down');
				}

				$this->inserted[] = $row;
				return $row;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('10.0.0.7');

		return new FileAuditHandler($mapper, $session, $request, new NullLogger());
	}//end handler()

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId(123);
		$object->setUuid('abc-123');
		$object->setRegister('5');
		$object->setSchema('6');

		return $object;
	}//end object()

	public function testASignedInDownloadWritesOneRowWithTheArchivePayload(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$user->method('getDisplayName')->willReturn('Behandelaar');

		$result = $this->handler(user: $user)->logBulkDownload(
			$this->object(),
			[7 => 42, 9 => 43],
			[7 => 'a.pdf', 9 => 'b.pdf'],
			'object_abc-123_files.zip',
			2048
		);

		$this->assertCount(1, $this->inserted);
		$row = $this->inserted[0];
		$this->assertSame($row, $result);
		$this->assertSame(123, $row->getObject());
		$this->assertSame('abc-123', $row->getObjectUuid());
		$this->assertSame('5', (string)$row->getRegister());
		$this->assertSame('6', (string)$row->getSchema());
		$this->assertSame('file.bulk_downloaded', $row->getAction());
		$this->assertSame(
			[
				'fileIds' => [42, 43],
				'fileNames' => ['a.pdf', 'b.pdf'],
				'fileCount' => 2,
				'zipName' => 'object_abc-123_files.zip',
				'totalBytes' => 2048,
			],
			$row->getChanged()
		);
		$this->assertSame('behandelaar', $row->getUser());
		$this->assertSame('Behandelaar', $row->getUserName());
		$this->assertSame(14, $row->getSize());
		$this->assertNotEmpty($row->getUuid());
		$this->assertSame(1, $this->retentionCalls);
	}//end testASignedInDownloadWritesOneRowWithTheArchivePayload()

	public function testAnAnonymousDownloadIsAttributedToTheRemoteAddress(): void {
		$this->handler(user: null)->logBulkDownload($this->object(), [42], ['a.pdf'], 'one.zip');

		$this->assertCount(1, $this->inserted);
		$this->assertSame('Anonymous', $this->inserted[0]->getUser());
		$this->assertSame('Anonymous (10.0.0.7)', $this->inserted[0]->getUserName());
		$this->assertNull($this->inserted[0]->getChanged()['totalBytes']);
	}//end testAnAnonymousDownloadIsAttributedToTheRemoteAddress()

	public function testAFailingWriteAnswersNullInsteadOfThrowing(): void {
		$this->assertNull($this->handler(user: null, failing: true)->logBulkDownload($this->object(), [42], ['a.pdf'], 'one.zip'));
		$this->assertSame([], $this->inserted);
	}//end testAFailingWriteAnswersNullInsteadOfThrowing()
}//end class
