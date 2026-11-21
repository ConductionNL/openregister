<?php

/**
 * ReviewMarkControllerTest
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
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.2
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\RedactionReviewMarkController;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\RedactionReviewMark;
use OCA\OpenRegister\Db\RedactionReviewMarkMapper;
use OCA\OpenRegister\Files\Service\File\CallerFileAccess;
use OCA\OpenRegister\Service\Anonymisation\RedactionRunFingerprint;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Only a signed-in person who can read the file sets a review mark.
 *
 * @covers \OCA\OpenRegister\Controller\RedactionReviewMarkController
 * @covers \OCA\OpenRegister\Db\RedactionReviewMark
 * @uses   \OCA\OpenRegister\Files\Service\File\CallerFileAccess
 * @uses   \OCA\OpenRegister\Service\Anonymisation\RedactionRunFingerprint
 */
class ReviewMarkControllerTest extends TestCase {

	/**
	 * The marks inserted.
	 *
	 * @var list<RedactionReviewMark>
	 */
	private array $inserted = [];

	private const DETECTIONS = [
		['relation_id' => 1, 'entity_type' => 'PERSON', 'entity_value' => 'Jan Jansen', 'position_start' => 10, 'redaction_decision' => 'redact'],
	];

	/**
	 * The controller, the caller signed in as $uid (null: no session), reading file 42 when $readable.
	 */
	private function controller(?string $uid, ?string $appPassword = null, bool $readable = true): RedactionReviewMarkController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$session = $this->createMock(ISession::class);
		$session->method('get')->with('app_password')->willReturn($appPassword);

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->with(42)->willReturn($readable === true ? [$this->createMock(Node::class)] : []);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);

		$relations = $this->createMock(EntityRelationMapper::class);
		$relations->method('findDetectionsForReview')->with(42)->willReturn(self::DETECTIONS);
		$marks = $this->createMock(RedactionReviewMarkMapper::class);
		$marks->method('insert')->willReturnCallback(function (RedactionReviewMark $mark): RedactionReviewMark {
			$this->inserted[] = $mark;
			return $mark;
		});

		return new RedactionReviewMarkController(
			appName: 'openregister',
			request: $this->createMock(IRequest::class),
			userSession: $userSession,
			session: $session,
			fileAccess: new CallerFileAccess(fileReadScope: null, userSession: $userSession, rootFolder: $root),
			relations: $relations,
			marks: $marks
		);
	}//end controller()

	public function testAPersonSetsAMark(): void {
		$response = $this->controller(uid: 'j.devries')->create(fileId: 42);

		$this->assertSame(201, $response->getStatus());
		$this->assertCount(1, $this->inserted);
		$data = $response->getData();
		$this->assertSame(42, $data['fileId']);
		$this->assertSame('j.devries', $data['checkedBy']);
		$this->assertSame(RedactionRunFingerprint::of(detections: self::DETECTIONS), $data['runFingerprint']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string) $data['checkedAt']);
		$this->assertStringNotContainsString('Jan Jansen', (string) json_encode($data));
	}//end testAPersonSetsAMark()

	public function testASystemOrTokenCallerCannotSetAMark(): void {
		foreach ([$this->controller(uid: null), $this->controller(uid: 'integration', appPassword: 'app-token-secret')] as $controller) {
			$response = $controller->create(fileId: 42);
			$this->assertSame(403, $response->getStatus());
			$this->assertSame('person-required', $response->getData()['error']);
		}

		$this->assertSame([], $this->inserted);
	}//end testASystemOrTokenCallerCannotSetAMark()

	public function testAFileTheCallerCannotReadIsNotFound(): void {
		$response = $this->controller(uid: 'j.devries', readable: false)->create(fileId: 42);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame([], $this->inserted);
	}//end testAFileTheCallerCannotReadIsNotFound()
}//end class
