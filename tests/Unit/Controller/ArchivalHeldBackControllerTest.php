<?php

/**
 * The held-back read of the destruction list review: archivists and admins only,
 * and every held-back record names the ancestor that holds it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/appraisal-inherited-from-a-parent/specs/archivering-vernietiging/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ArchivalHeldBackController;
use OCA\OpenRegister\Service\Archival\DestructionDryRun;
use OCA\OpenRegister\Service\Archival\InheritedAppraisal;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * GET /api/archival/held-back.
 */
#[CoversClass(ArchivalHeldBackController::class)]
class ArchivalHeldBackControllerTest extends TestCase {

	/**
	 * The dry run mock.
	 *
	 * @var DestructionDryRun&MockObject
	 */
	private DestructionDryRun&MockObject $dryRun;

	/**
	 * The session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The group manager mock.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * The controller under test.
	 *
	 * @var ArchivalHeldBackController
	 */
	private ArchivalHeldBackController $controller;

	/**
	 * Build the controller over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dryRun  = $this->createMock(DestructionDryRun::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->groups  = $this->createMock(IGroupManager::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		$this->controller = new ArchivalHeldBackController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->dryRun,
			$this->session,
			$this->groups,
			$l10n
		);
	}//end setUp()

	/**
	 * Sign a user in.
	 *
	 * @param bool $archivist Whether they are in the archivist group.
	 * @param bool $admin     Whether they are an admin.
	 *
	 * @return void
	 */
	private function signIn(bool $archivist, bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$this->session->method('getUser')->willReturn($user);
		$this->groups->method('isInGroup')->with('anna', 'archivaris')->willReturn($archivist);
		$this->groups->method('isAdmin')->with('anna')->willReturn($admin);
	}//end signIn()

	public function testWithoutASessionItAnswers401AndRunsNothing(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->dryRun->expects($this->never())->method('run');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->index()->getStatus());
	}//end testWithoutASessionItAnswers401AndRunsNothing()

	public function testSomebodyWhoIsNeitherArchivistNorAdminGets403(): void {
		$this->signIn(archivist: false, admin: false);
		$this->dryRun->expects($this->never())->method('run');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->index()->getStatus());
	}//end testSomebodyWhoIsNeitherArchivistNorAdminGets403()

	public function testThreePublicationsUnderAHotspotAreListedWithTheSubjectNamed(): void {
		$this->signIn(archivist: true, admin: false);
		$subject  = ['uuid' => 'subj-1', 'name' => 'Stikstof', 'appraisal' => 'blijvend_bewaren', 'property' => null];
		$heldBack = [];
		foreach (['Besluit 1', 'Besluit 2', 'Besluit 3'] as $n => $name) {
			$heldBack[] = [
				'uuid'     => 'pub-'.$n,
				'name'     => $name,
				'schema'   => '7',
				'reason'   => InheritedAppraisal::REASON_PERMANENT_ANCESTOR,
				'ancestor' => $subject,
			];
		}

		$this->dryRun->expects($this->once())->method('run')
			->willReturn(['eligible' => [], 'heldBack' => $heldBack, 'scanned' => 3, 'truncated' => false]);

		$response = $this->controller->index();
		$data     = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($data['truncated']);
		$this->assertCount(3, $data['results']);
		$this->assertSame(
			[
				'uuid'       => 'pub-2',
				'name'       => 'Besluit 3',
				'schema'     => '7',
				'heldBy'     => 'Stikstof',
				'heldByUuid' => 'subj-1',
				'appraisal'  => 'blijvend_bewaren',
				'reason'     => 'Its ancestor Stikstof has appraisal blijvend_bewaren',
			],
			$data['results'][2]
		);
	}//end testThreePublicationsUnderAHotspotAreListedWithTheSubjectNamed()

	public function testAnUnreadableAncestorIsNamedByItsReference(): void {
		$this->signIn(archivist: false, admin: true);
		$this->dryRun->method('run')->willReturn(
			[
				'eligible'  => [],
				'heldBack'  => [
					[
						'uuid'     => 'pub-9',
						'name'     => 'Besluit 9',
						'schema'   => '7',
						'reason'   => InheritedAppraisal::REASON_UNRESOLVED_ANCESTOR,
						'ancestor' => ['uuid' => 'gone-1', 'name' => null, 'appraisal' => null, 'property' => 'subjects'],
					],
				],
				'scanned'   => 500,
				'truncated' => true,
			]
		);

		$data = $this->controller->index()->getData();

		$this->assertTrue($data['truncated']);
		$this->assertSame('gone-1', $data['results'][0]['heldBy']);
		$this->assertNull($data['results'][0]['appraisal']);
		$this->assertSame('Its ancestor gone-1 could not be read', $data['results'][0]['reason']);
	}//end testAnUnreadableAncestorIsNamedByItsReference()
}//end class
