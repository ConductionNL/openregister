<?php

/**
 * Contract test: the near-duplicates endpoint other apps read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Contract
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

namespace OCA\OpenRegister\Tests\Unit\Contract;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
use DateTime;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Db\TextSignature as TextSignatureRow;
use OCA\OpenRegister\Db\TextSignatureMapper;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Files\Service\File\FileReadScope;
use OCA\OpenRegister\Files\Service\File\FileReadScopeRules;
use OCA\OpenRegister\Files\Service\File\FileReadScopeTree;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Search\Controller\DuplicateController;
use OCA\OpenRegister\Search\Controller\Support\DuplicateControllerServices;
use OCA\OpenRegister\Search\Service\Quality\DismissedPairStore;
use OCA\OpenRegister\Search\Service\Quality\DuplicateDetectionServiceInterface;
use OCA\OpenRegister\Search\Service\Quality\NearDuplicateScope;
use OCA\OpenRegister\Search\Service\Quality\TextGroupFinder;
use OCA\OpenRegister\Service\Quality\TextSignature;
use OCA\OpenRegister\Tests\Unit\Service\Quality\NearDuplicateTexts;
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
use ReflectionMethod;

/**
 * Pins what consumers (dossiq's corpus collection and review triage) rely on:
 * request `GET /api/files/near-duplicates?objectIds[]=...`, response
 * `groups[].representative`, `groups[].members[].fileId|similarity|identicalText`
 * and `notCompared[].fileId|reason`. Every consumer requires OpenRegister.
 *
 * @covers \OCA\OpenRegister\Search\Controller\DuplicateController
 * @uses   \OCA\OpenRegister\Search\Controller\Support\DuplicateControllerServices
 * @uses   \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses   \OCA\OpenRegister\Search\Service\Quality\NearDuplicateScope
 * @uses   \OCA\OpenRegister\Files\Service\File\FileReadScope
 * @uses   \OCA\OpenRegister\Files\Service\File\FileReadScopeTree
 * @uses   \OCA\OpenRegister\Files\Service\File\FileReadScopeRules
 * @uses   \OCA\OpenRegister\Search\Service\Quality\TextGroupFinder
 * @uses   \OCA\OpenRegister\Search\Service\Quality\TextGroups
 * @uses   \OCA\OpenRegister\Service\Quality\TextSignature
 * @uses   \OCA\OpenRegister\Db\TextSignature
 */
final class NearDuplicatesContractTest extends TestCase {

	public function testTheRouteIsFixed(): void {
		$routes = (string) file_get_contents(__DIR__.'/../../../appinfo/routes.php');

		$this->assertStringContainsString(
			"['name' => 'duplicate#nearDuplicates', 'url' => '/api/files/near-duplicates', 'verb' => 'GET']",
			$routes
		);
		$this->assertLessThan(
			strpos($routes, "'url' => '/api/files/{id}', 'verb' => 'GET'"),
			strpos($routes, "'url' => '/api/files/near-duplicates'"),
			'the literal route must come before /api/files/{id}, which would take it'
		);
	}//end testTheRouteIsFixed()

	public function testTheEndpointTakesItsScopeFromTheQueryAndAnyUserMayCallIt(): void {
		$method = new ReflectionMethod(DuplicateController::class, 'nearDuplicates');

		$this->assertSame([], $method->getParameters());
		$this->assertStringContainsString('@NoAdminRequired', (string) $method->getDocComment());
	}//end testTheEndpointTakesItsScopeFromTheQueryAndAnyUserMayCallIt()

	public function testTheResponseShapeIsFixed(): void {
		$letter = NearDuplicateTexts::letter(seed: 4);
		$signer = new TextSignature();
		$rows   = [
			40 => TextSignatureRow::fromRecord(40, $signer->sign($letter), new DateTime()),
			41 => TextSignatureRow::fromRecord(41, $signer->sign($letter), new DateTime()),
			42 => TextSignatureRow::fromRecord(42, $signer->sign(''), new DateTime()),
		];

		$data = $this->controller(rows: $rows, objectFiles: ['obj-1' => [40, 41, 42]])->nearDuplicates()->getData();

		$this->assertSame(['groups', 'notCompared'], array_keys($data));
		$this->assertSame(['representative', 'members'], array_keys($data['groups'][0]));
		$this->assertSame(40, $data['groups'][0]['representative']);
		$this->assertSame(['fileId', 'similarity', 'identicalText'], array_keys($data['groups'][0]['members'][1]));
		$this->assertSame(41, $data['groups'][0]['members'][1]['fileId']);
		$this->assertSame(1.0, $data['groups'][0]['members'][1]['similarity']);
		$this->assertTrue($data['groups'][0]['members'][1]['identicalText']);
		$this->assertSame([['fileId' => 42, 'reason' => 'noText']], $data['notCompared']);
	}//end testTheResponseShapeIsFixed()

	/**
	 * The controller over `objectIds[]=obj-1`, a caller who may read every file, and these signatures.
	 *
	 * @param array<int, TextSignatureRow> $rows        The stored signatures.
	 * @param array<string, list<int>>     $objectFiles The files of each object.
	 *
	 * @return DuplicateController The controller.
	 */
	private function controller(array $rows, array $objectFiles): DuplicateController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default=null): mixed {
				if ($key === 'objectIds') {
					return ['obj-1'];
				}

				return $default;
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('consumer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturn($this->createMock(Node::class));
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		$files = $this->createMock(FileMapperInterface::class);
		$files->method('getFileIdsForObjects')->willReturn($objectFiles);

		$mapper = $this->createMock(TextSignatureMapper::class);
		$mapper->method('findByFileIds')->willReturnCallback(static fn (array $ids): array => array_intersect_key($rows, array_flip($ids)));
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnArgument(2);
		$finder     = new TextGroupFinder($mapper, $config);
		$duplicates = $this->createMock(DuplicateDetectionServiceInterface::class);
		$duplicates->method('findTextGroups')->willReturnCallback(
			static fn (array $fileIds, ?float $threshold=null): array => $finder->find(fileIds: $fileIds, threshold: $threshold)
		);

		return new DuplicateController(
			$request,
			new DuplicateControllerServices(
				$duplicates,
				$this->createMock(DismissedPairStore::class),
				$this->createMock(ObjectOperationsInterface::class),
				new ControllerSession($session, $this->createMock(IGroupManager::class), new NullLogger()),
				new NearDuplicateScope($files, new FileReadScope(new FileReadScopeTree($root, $session), new FileReadScopeRules(), new NullLogger()))
			)
		);
	}//end controller()
}//end class
