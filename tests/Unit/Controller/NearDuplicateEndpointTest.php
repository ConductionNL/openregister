<?php

/**
 * Unit tests for GET /api/files/near-duplicates.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-near-identical-documents-are-grouped-by-their-text-req-ndt-002
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\TextSignature as TextSignatureRow;
use OCA\OpenRegister\Db\TextSignatureMapper;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Files\Service\File\FileReadScope;
use OCA\OpenRegister\Files\Service\File\FileReadScopeRules;
use OCA\OpenRegister\Files\Service\File\FileReadScopeTree;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Search\Controller\DuplicateController;
use OCA\OpenRegister\Search\Controller\Support\DuplicateControllerServices;
use OCA\OpenRegister\Search\Service\Quality\DedupScoringSources;
use OCA\OpenRegister\Search\Service\Quality\DismissedPairStore;
use OCA\OpenRegister\Search\Service\Quality\DuplicateDetectionService;
use OCA\OpenRegister\Search\Service\Quality\NearDuplicateScope;
use OCA\OpenRegister\Search\Service\Quality\SimilarityCalculator;
use OCA\OpenRegister\Search\Service\Quality\TextGroupFinder;
use OCA\OpenRegister\Service\Quality\TextSignature;
use OCA\OpenRegister\Tests\Unit\Service\Quality\NearDuplicateTexts;
use OCP\AppFramework\Http;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The near-duplicate endpoint resolves the scope, drops what the caller may not read, then groups (REQ-NDT-002).
 *
 * @covers \OCA\OpenRegister\Search\Controller\DuplicateController
 * @covers \OCA\OpenRegister\Search\Service\Quality\NearDuplicateScope
 * @uses   \OCA\OpenRegister\Search\Controller\Support\DuplicateControllerServices
 * @uses   \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses   \OCA\OpenRegister\Files\Service\File\FileReadScope
 * @uses   \OCA\OpenRegister\Files\Service\File\FileReadScopeTree
 * @uses   \OCA\OpenRegister\Files\Service\File\FileReadScopeRules
 * @uses   \OCA\OpenRegister\Search\Service\Quality\TextGroups
 * @uses   \OCA\OpenRegister\Search\Service\Quality\TextGroupFinder
 * @uses   \OCA\OpenRegister\Search\Service\Quality\DuplicateDetectionService
 * @uses   \OCA\OpenRegister\Search\Service\Quality\DedupScoringSources
 * @uses   \OCA\OpenRegister\Search\Service\Quality\DedupRuleResolver
 * @uses   \OCA\OpenRegister\Search\Service\Quality\PayloadComparator
 * @uses   \OCA\OpenRegister\Search\Service\Quality\SimilarityCalculator
 * @uses   \OCA\OpenRegister\Service\RegisterScopedSchemaResolvers
 * @uses   \OCA\OpenRegister\Db\SchemaCatalog
 * @uses   \OCA\OpenRegister\Service\Quality\TextSignature
 * @uses   \OCA\OpenRegister\Db\TextSignature
 */
final class NearDuplicateEndpointTest extends TestCase {

	/**
	 * The stored signature rows, keyed by file id.
	 *
	 * @var array<int, TextSignatureRow>
	 */
	private array $rows = [];

	/**
	 * The file ids the signature mapper was asked for.
	 *
	 * @var list<list<int>>
	 */
	private array $asked = [];

	/**
	 * The file ids the caller may read.
	 *
	 * @var list<int>
	 */
	private array $readable = [];

	/**
	 * The files attached to each object.
	 *
	 * @var array<string, list<int>>
	 */
	private array $objectFiles = [];

	/**
	 * Store the signature row of a text for a file.
	 *
	 * @param int    $fileId The file id.
	 * @param string $text   The text.
	 *
	 * @return void
	 */
	private function store(int $fileId, string $text): void {
		$this->rows[$fileId] = TextSignatureRow::fromRecord($fileId, (new TextSignature())->sign($text), new DateTime('2026-11-13 10:00:00'));
	}//end store()

	/**
	 * The controller over these request parameters, with a real scope, read check and grouping.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param bool                 $signed Whether a user is logged in.
	 *
	 * @return DuplicateController The controller.
	 */
	private function controller(array $params, bool $signed=true): DuplicateController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('reviewer');
		$userSession = $this->createMock(IUserSession::class);
		$caller = null;
		if ($signed === true) {
			$caller = $user;
		}

		$userSession->method('getUser')->willReturn($caller);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturnCallback(
			function (int $fileId): ?Node {
				if (in_array($fileId, $this->readable, true) === false) {
					return null;
				}

				return $this->createMock(Node::class);
			}
		);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('reviewer')->willReturn($userFolder);

		$files = $this->createMock(FileMapperInterface::class);
		$files->method('getFileIdsForObjects')->willReturnCallback(
			fn (array $uuids): array => array_intersect_key($this->objectFiles, array_flip($uuids))
		);
		$files->method('getFiles')->willReturnCallback(
			static fn (?int $node=null): array => [
				['fileid' => 30, 'mimetype' => 'text/plain'],
				['fileid' => 31, 'mimetype' => 'httpd/unix-directory'],
			]
		);

		$scope = new NearDuplicateScope(
			$files,
			new FileReadScope(new FileReadScopeTree($rootFolder, $userSession), new FileReadScopeRules(), new NullLogger())
		);

		$mapper = $this->createMock(TextSignatureMapper::class);
		$mapper->method('findByFileIds')->willReturnCallback(
			function (array $fileIds): array {
				$this->asked[] = $fileIds;
				return array_intersect_key($this->rows, array_flip($fileIds));
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnArgument(2);

		$duplicates = new DuplicateDetectionService(
			$this->createMock(ObjectOperationsInterface::class),
			new DedupScoringSources(
				new SchemaCatalog(
					registers: $this->createMock(RegisterMapper::class),
					schemas: $this->createMock(SchemaMapper::class),
					tables: $this->createMock(MagicMapperInterface::class)
				),
				new SimilarityCalculator()
			),
			$this->createMock(DismissedPairStore::class),
			new NullLogger(),
			new TextGroupFinder($mapper, $config)
		);

		return new DuplicateController(
			$request,
			new DuplicateControllerServices(
				$duplicates,
				$this->createMock(DismissedPairStore::class),
				$this->createMock(ObjectOperationsInterface::class),
				new ControllerSession($userSession, $this->createMock(IGroupManager::class), new NullLogger()),
				$scope
			)
		);
	}//end controller()

	/**
	 * Two near-identical files, one in an object the caller may not read: no group, and the file is nowhere.
	 *
	 * @return void
	 */
	public function testAnUnreadableFileIsLeftOutBeforeComparing(): void {
		$letter = NearDuplicateTexts::letter(seed: 1);
		$this->store(fileId: 10, text: $letter);
		$this->store(fileId: 11, text: NearDuplicateTexts::edited(text: $letter, positions: [5]));
		$this->readable = [10];

		$response = $this->controller(params: ['fileIds' => ['10', '11']])->nearDuplicates();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['groups' => [], 'notCompared' => []], $response->getData());
		$this->assertSame([[10]], $this->asked);
		$this->assertStringNotContainsString('11', (string) json_encode($response->getData()));
	}//end testAnUnreadableFileIsLeftOutBeforeComparing()

	/**
	 * Both readable: the same two files form a group, so the drop above is the read check's doing.
	 *
	 * @return void
	 */
	public function testReadableNearIdenticalFilesOfAnObjectAreGrouped(): void {
		$letter = NearDuplicateTexts::letter(seed: 2);
		$this->store(fileId: 10, text: $letter);
		$this->store(fileId: 11, text: NearDuplicateTexts::edited(text: $letter, positions: [5]));
		$this->store(fileId: 12, text: NearDuplicateTexts::letter(seed: 3));
		$this->readable    = [10, 11, 12];
		$this->objectFiles = ['obj-a' => [11, 10], 'obj-b' => [12]];

		$data = $this->controller(params: ['objectIds' => ['obj-a', 'obj-b']])->nearDuplicates()->getData();

		$this->assertCount(1, $data['groups']);
		$this->assertSame(10, $data['groups'][0]['representative']);
		$this->assertSame([10, 11], array_column($data['groups'][0]['members'], 'fileId'));
		$this->assertSame([[10, 11, 12]], $this->asked);
	}//end testReadableNearIdenticalFilesOfAnObjectAreGrouped()

	/**
	 * A scan without text, a cover note and a file never extracted are each named with their reason.
	 *
	 * @return void
	 */
	public function testAFileWithoutTextIsNamedNotCompared(): void {
		$this->rows[20] = TextSignatureRow::fromRecord(20, (new TextSignature())->sign(''), new DateTime());
		$this->rows[21] = TextSignatureRow::fromRecord(21, (new TextSignature())->sign('Zie bijlage, met vriendelijke groet.'), new DateTime());
		$this->readable = [20, 21, 22];

		$data = $this->controller(params: ['fileIds' => '20,21,22'])->nearDuplicates()->getData();

		$this->assertSame([], $data['groups']);
		$this->assertSame(
			[['fileId' => 20, 'reason' => 'noText'], ['fileId' => 21, 'reason' => 'tooShort'], ['fileId' => 22, 'reason' => 'notExtracted']],
			$data['notCompared']
		);
	}//end testAFileWithoutTextIsNamedNotCompared()

	/**
	 * More than 5000 files is refused with 400 before anything is read or compared.
	 *
	 * @return void
	 */
	public function testMoreThanFiveThousandIsRefused(): void {
		$this->readable = range(1, 5001);

		$response = $this->controller(params: ['fileIds' => array_map('strval', range(1, 5001))])->nearDuplicates();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('5000', $response->getData()['error']);
		$this->assertSame([], $this->asked);

		$this->asked = [];
		$atCap       = $this->controller(params: ['fileIds' => array_map('strval', range(1, 5000))])->nearDuplicates();
		$this->assertSame(Http::STATUS_OK, $atCap->getStatus());
	}//end testMoreThanFiveThousandIsRefused()

	/**
	 * No scope, two scopes, a bad file id or a threshold out of range: 400.
	 *
	 * @return void
	 */
	public function testABadScopeOrThresholdIsRefused(): void {
		$cases = [
			[],
			['fileIds' => ['10'], 'folderId' => '4'],
			['fileIds' => ['ten']],
			['folderId' => 'x'],
			['fileIds' => ['10'], 'threshold' => '0.2'],
		];
		$this->readable = [10];
		foreach ($cases as $params) {
			$response = $this->controller(params: $params)->nearDuplicates();
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), (string) json_encode($params));
		}

		$this->assertSame([], $this->asked);
	}//end testABadScopeOrThresholdIsRefused()

	/**
	 * A folder scope takes its files, not its subfolders; without a user the endpoint answers 401.
	 *
	 * @return void
	 */
	public function testAFolderScopeTakesItsFilesAndAGuestIsRefused(): void {
		$this->readable = [30, 31];
		$this->controller(params: ['folderId' => '4'])->nearDuplicates();
		$this->assertSame([[30]], $this->asked);

		$this->asked = [];
		$guest       = $this->controller(params: ['fileIds' => ['30']], signed: false)->nearDuplicates();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $guest->getStatus());
		$this->assertSame([], $this->asked);
	}//end testAFolderScopeTakesItsFilesAndAGuestIsRefused()
}//end class
