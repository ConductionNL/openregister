<?php

/**
 * Golden test for FileAuditHandler::logFileAction().
 *
 * Pins the one audit row a file action writes: the object it is tied to, the
 * action, the fileId/data payload, the attribution of a signed-in caller and
 * the System fallback, the retention hook and the size; and that a failing
 * write answers null with a warning naming the action instead of breaking the
 * file operation.
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
use Psr\Log\AbstractLogger;
use RuntimeException;

class FileAuditHandlerFileActionGoldenTest extends TestCase {

	/**
	 * The rows the mapper was asked to insert.
	 *
	 * @var list<AuditTrail>
	 */
	private array $inserted = [];

	/**
	 * The objects the retention hook ran for.
	 *
	 * @var list<ObjectEntity>
	 */
	private array $retainedFor = [];

	/**
	 * The warnings logged, as [message, context].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * A handler whose mapper records every insert, or throws when $failing.
	 *
	 * @param IUser|null $user    The signed-in caller, or null for none.
	 * @param bool       $failing Whether the insert throws.
	 */
	private function handler(?IUser $user, bool $failing = false): FileAuditHandler {
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('applyRetentionExpiry')->willReturnCallback(
			function (AuditTrail $auditTrail, ObjectEntity $objectEntity): AuditTrail {
				$this->retainedFor[] = $objectEntity;
				return $auditTrail;
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

		$warnings = &$this->warnings;
		$logger = new class($warnings) extends AbstractLogger {
			/**
			 * @param list<array{0: string, 1: array<string, mixed>}> $warnings
			 */
			public function __construct(private array &$warnings) {
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				unset($context['line']);
				$this->warnings[] = [(string)$message, $context];
			}
		};

		return new FileAuditHandler($mapper, $session, $request, $logger);
	}//end handler()

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId(123);
		$object->setUuid('abc-123');
		$object->setRegister('5');
		$object->setSchema('6');

		return $object;
	}//end object()

	/**
	 * @return array<string, mixed>
	 */
	private static function snapshot(AuditTrail $row): array {
		return [
			'object' => $row->getObject(),
			'objectUuid' => $row->getObjectUuid(),
			'register' => (string)$row->getRegister(),
			'schema' => (string)$row->getSchema(),
			'action' => $row->getAction(),
			'changed' => $row->getChanged(),
			'user' => $row->getUser(),
			'userName' => $row->getUserName(),
			'size' => $row->getSize(),
			'uuidIsV4' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string)$row->getUuid()) === 1,
			'hasCreated' => $row->getCreated() !== null,
		];
	}//end snapshot()

	public function testASignedInActionWritesOneRow(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$user->method('getDisplayName')->willReturn('Behandelaar');
		$object = $this->object();

		$result = $this->handler(user: $user)->logFileAction(
			object: $object,
			fileId: 42,
			action: 'file.renamed',
			data: ['oldName' => 'a.pdf', 'newName' => 'b.pdf']
		);

		$this->assertCount(1, $this->inserted);
		$this->assertSame($this->inserted[0], $result);
		$this->assertSame(
			[
				'object' => 123,
				'objectUuid' => 'abc-123',
				'register' => '5',
				'schema' => '6',
				'action' => 'file.renamed',
				'changed' => ['fileId' => 42, 'data' => ['oldName' => 'a.pdf', 'newName' => 'b.pdf']],
				'user' => 'behandelaar',
				'userName' => 'Behandelaar',
				'size' => 14,
				'uuidIsV4' => true,
				'hasCreated' => true,
			],
			self::snapshot($this->inserted[0])
		);
		$this->assertSame([$object], $this->retainedFor);
		$this->assertSame([], $this->warnings);
	}//end testASignedInActionWritesOneRow()

	public function testAnActionWithoutUserIsAttributedToSystemWithEmptyData(): void {
		$this->handler(user: null)->logFileAction(object: $this->object(), fileId: 7, action: 'file.locked');

		$this->assertCount(1, $this->inserted);
		$snapshot = self::snapshot($this->inserted[0]);
		$this->assertSame('System', $snapshot['user']);
		$this->assertSame('System', $snapshot['userName']);
		$this->assertSame(['fileId' => 7, 'data' => []], $snapshot['changed']);
		$this->assertSame('file.locked', $snapshot['action']);
	}//end testAnActionWithoutUserIsAttributedToSystemWithEmptyData()

	public function testAFailingWriteAnswersNullAndWarnsWithTheAction(): void {
		$this->assertNull($this->handler(user: null, failing: true)->logFileAction(object: $this->object(), fileId: 7, action: 'file.unlocked'));
		$this->assertSame([], $this->inserted);
		$this->assertCount(1, $this->warnings);
		$this->assertSame('[FileAuditHandler] Failed to log file action file.unlocked: database down', $this->warnings[0][0]);
		$this->assertStringEndsWith('FileAuditHandler.php', $this->warnings[0][1]['file']);
	}//end testAFailingWriteAnswersNullAndWarnsWithTheAction()

	public function testTheGoldenSeesADifferentAction(): void {
		$this->handler(user: null)->logFileAction(object: $this->object(), fileId: 7, action: 'file.copied');

		$this->assertNotSame('file.locked', self::snapshot($this->inserted[0])['action']);
	}//end testTheGoldenSeesADifferentAction()
}//end class
