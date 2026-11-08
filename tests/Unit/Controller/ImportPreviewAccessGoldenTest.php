<?php

/**
 * ImportPreviewController access golden and characterisation tests.
 *
 * Pins who may preview an import into a register (allowed AND refused, for
 * every shape a register's `manage` rule can take), what a preview and a
 * commit hand the service or the job queue, and how each failure answers,
 * so the create / commit / manage-check bodies can be split without an
 * outcome moving.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\BackgroundJob\ImportPreviewRunner;
use OCA\OpenRegister\Controller\ImportPreviewController;
use OCA\OpenRegister\Db\ImportPreview;
use OCA\OpenRegister\Db\ImportPreviewMapper;
use OCA\OpenRegister\Db\ImportPreviewRowMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\Import\ImportPreviewService;
use OCA\OpenRegister\Service\Import\SourceRowReader;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ImportPreviewControllerFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden outcomes of ImportPreviewController::create() and ::commit().
 */
final class ImportPreviewAccessGoldenTest extends TestCase {

	private ImportPreviewService&MockObject $service;

	private ImportPreviewMapper&MockObject $previewMapper;

	private RegisterMapper&MockObject $registerMapper;

	private SourceRowReader&MockObject $reader;

	private IJobList&MockObject $jobList;

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	private IRequest&MockObject $request;

	private array $params = [];

	private ?array $upload = ['tmp_name' => '/tmp/upload', 'name' => 'rows.csv'];

	protected function setUp(): void {
		$this->service = $this->createMock(ImportPreviewService::class);
		$this->previewMapper = $this->createMock(ImportPreviewMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->reader = $this->createMock(SourceRowReader::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ($this->params[$key] ?? $default)
		);
		$this->request->method('getUploadedFile')->willReturnCallback(fn (): ?array => $this->upload);
	}

	private function signIn(string $uid, bool $admin = false, array|RuntimeException $groups = []): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn($admin);
		if ($groups instanceof RuntimeException) {
			$this->groupManager->method('getUserGroupIds')->willThrowException($groups);
		} else {
			$this->groupManager->method('getUserGroupIds')->willReturn($groups);
		}

		return $user;
	}

	private function controller(): ImportPreviewController {
		return ImportPreviewControllerFactory::create(
			'openregister',
			$this->request,
			$this->service,
			$this->previewMapper,
			$this->createMock(ImportPreviewRowMapper::class),
			$this->reader,
			$this->registerMapper,
			$this->jobList,
			$this->userSession,
			$this->groupManager
		);
	}

	private function register(mixed $authorization): Register {
		$register = new Register();
		$register->setId(1);
		$register->setAuthorization($authorization);
		$this->registerMapper->method('find')->willReturn($register);
		$this->params['register'] = 'reg';

		return $register;
	}

	private function previewEntity(): ImportPreview {
		$preview = new ImportPreview();
		$preview->setId(9);
		$preview->setUuid('p-9');
		$preview->setCreatedBy('alice');

		return $preview;
	}

	/**
	 * Which manage rules admit alice (in group "editors") to preview.
	 *
	 * @return array<string, array{0: mixed, 1: array|RuntimeException, 2: int}>
	 */
	public static function manageRules(): array {
		return [
			'group named as a string' => [['manage' => ['editors']], ['staff', 'editors'], 201],
			'group named as an entry' => [['manage' => [['group' => 'editors']]], ['editors'], 201],
			'another group' => [['manage' => ['admins', ['group' => 'staff']]], ['editors'], 403],
			'no manage key' => [['read' => ['editors']], ['editors'], 403],
			'empty rule' => [[], ['editors'], 403],
			'a non-string non-array entry' => [['manage' => [7, null, true]], ['editors'], 403],
			'a numeric group never equals a string entry' => [['manage' => ['7']], [7], 403],
			'groups that cannot be read' => [['manage' => ['editors']], new RuntimeException('ldap'), 403],
			'no groups' => [['manage' => ['editors']], [], 403],
		];
	}

	/**
	 * @dataProvider manageRules
	 */
	public function testTheManageRuleDecidesWhoMayPreview(mixed $authorization, array|RuntimeException $groups, int $status): void {
		$this->signIn('alice', false, $groups);
		$this->register($authorization);
		$this->service->method('preview')->willReturn($this->previewEntity());
		if ($status !== 201) {
			$this->service->expects($this->never())->method('preview');
		}

		$response = $this->controller()->create();

		$this->assertSame($status, $response->getStatus());
		if ($status === 403) {
			$this->assertSame(['error' => 'User does not have permission to manage this register'], $response->getData());
		}
	}

	public function testAnAdminMayPreviewWithoutAManageRuleAndWithoutAGroupLookup(): void {
		$this->signIn('root', true);
		$this->groupManager->expects($this->never())->method('getUserGroupIds');
		$this->register([]);
		$this->service->method('preview')->willReturn($this->previewEntity());

		$this->assertSame(201, $this->controller()->create()->getStatus());
	}

	public function testAnEmptyRegisterParamIsNotFoundByItsName(): void {
		$this->signIn('alice');
		$this->params['register'] = '';
		$this->registerMapper->expects($this->never())->method('find');

		$response = $this->controller()->create();

		$this->assertSame([404, ['error' => 'Register "" was not found']], [$response->getStatus(), $response->getData()]);
	}

	public function testASyncPreviewHandsTheServiceTheRequestedParams(): void {
		$user = $this->signIn('alice', false, ['editors']);
		$register = $this->register(['manage' => ['editors']]);
		$this->params += ['schema' => 's', 'format' => 'csv', 'policy' => 'skip', 'matchKey' => 'k', 'mapping' => 'pack'];
		$this->service->expects($this->once())->method('preview')
			->with(
				[
					'register' => $register,
					'schema' => 's',
					'filePath' => '/tmp/upload',
					'sourceName' => 'rows.csv',
					'format' => 'csv',
					'policy' => 'skip',
					'matchKey' => 'k',
					'packSlug' => 'pack',
				],
				$user
			)
			->willReturn($this->previewEntity());
		$this->jobList->expects($this->never())->method('add');

		$response = $this->controller()->create();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame('p-9', $response->getData()['uuid']);
	}

	public function testAnAsyncPreviewStagesTheFileAndQueuesThePreviewPhase(): void {
		$this->signIn('alice', false, ['editors']);
		$this->register(['manage' => ['editors']]);
		$this->params['async'] = 'true';
		$preview = $this->previewEntity();
		$this->service->expects($this->never())->method('preview');
		$this->service->method('createPreview')->willReturn($preview);
		$this->reader->expects($this->once())->method('stage')->with('/tmp/upload', 'p-9')->willReturn('/staged/p-9');
		$this->previewMapper->expects($this->once())->method('persist')->with($preview);
		$this->jobList->expects($this->once())->method('add')
			->with(ImportPreviewRunner::class, ['preview_id' => 9, 'phase' => ImportPreviewRunner::PHASE_PREVIEW]);

		$response = $this->controller()->create();

		$this->assertSame(202, $response->getStatus());
		$this->assertSame('/staged/p-9', $preview->getSourcePath());
	}

	public function testAPreviewFailureAnswers400Or500WithItsMessage(): void {
		$this->signIn('alice', false, ['editors']);
		$this->register(['manage' => ['editors']]);
		$this->service->method('preview')->willReturnOnConsecutiveCalls(
			$this->throwException(new InvalidArgumentException('bad format')),
			$this->throwException(new RuntimeException('disk'))
		);

		$first = $this->controller()->create();
		$second = $this->controller()->create();

		$this->assertSame([400, ['error' => 'bad format']], [$first->getStatus(), $first->getData()]);
		$this->assertSame([500, ['error' => 'disk']], [$second->getStatus(), $second->getData()]);
	}

	public function testAnAsyncCommitQueuesTheCommitPhaseWithTheUploadedFilesHash(): void {
		$this->signIn('alice');
		$preview = $this->previewEntity();
		$this->previewMapper->method('find')->willReturn($preview);
		$this->params['async'] = '1';
		$this->reader->method('hash')->with('/tmp/upload')->willReturn('h-upload');
		$this->service->expects($this->never())->method('commit');
		$this->jobList->expects($this->once())->method('add')
			->with(ImportPreviewRunner::class, ['preview_id' => 9, 'phase' => ImportPreviewRunner::PHASE_COMMIT, 'source_hash' => 'h-upload']);

		$this->assertSame(202, $this->controller()->commit(9)->getStatus());
	}

	public function testASyncCommitUsesTheSentHashOrNone(): void {
		$user = $this->signIn('alice');
		$preview = $this->previewEntity();
		$this->previewMapper->method('find')->willReturn($preview);
		$this->upload = null;
		$seen = [];
		$this->service->method('commit')->willReturnCallback(
			function (ImportPreview $given, ?string $hash, $who) use (&$seen, $preview, $user): ImportPreview {
				$this->assertSame($preview, $given);
				$this->assertSame($user, $who);
				$seen[] = $hash;
				return $given;
			}
		);

		$this->params['sourceHash'] = 'h-sent';
		$this->assertSame(200, $this->controller()->commit(9)->getStatus());
		$this->params['sourceHash'] = '';
		$this->assertSame(200, $this->controller()->commit(9)->getStatus());

		$this->assertSame(['h-sent', null], $seen);
	}

	public function testACommitFailureAnswers400Or500WithItsMessage(): void {
		$this->signIn('alice');
		$this->previewMapper->method('find')->willReturn($this->previewEntity());
		$this->upload = null;
		$this->service->method('commit')->willReturnOnConsecutiveCalls(
			$this->throwException(new InvalidArgumentException('stale')),
			$this->throwException(new RuntimeException('db'))
		);

		$first = $this->controller()->commit(9);
		$second = $this->controller()->commit(9);

		$this->assertSame([400, ['error' => 'stale']], [$first->getStatus(), $first->getData()]);
		$this->assertSame([500, ['error' => 'db']], [$second->getStatus(), $second->getData()]);
	}
}
