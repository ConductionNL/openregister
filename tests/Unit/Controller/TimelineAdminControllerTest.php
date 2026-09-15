<?php

/**
 * Unit tests for TimelineAdminController — the three administered declarations.
 *
 * The split this asserts is the one the controller exists to make: READING the
 * declarations is open, because a handler writing an entry needs the list of
 * kinds and the canned texts they may insert, and DECLARING them is not. The
 * attribute on each method is a declaration of posture; these tests are what
 * proves the body enforces it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- PHPUnit fixture properties are named by their type.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\TimelineAdminController;
use OCA\OpenRegister\Db\ReferencePattern;
use OCA\OpenRegister\Db\TextBlock;
use OCA\OpenRegister\Db\TimelineKind;
use OCA\OpenRegister\Service\Timeline\ReferenceService;
use OCA\OpenRegister\Service\Timeline\TextBlockService;
use OCA\OpenRegister\Service\Timeline\TimelineKindService;
use OCA\OpenRegister\Service\Timeline\TimelineValidationException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * TimelineAdminControllerTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class TimelineAdminControllerTest extends TestCase {
	private TimelineAdminController $controller;
	private IRequest&MockObject $request;
	private TimelineKindService&MockObject $kinds;
	private ReferenceService&MockObject $references;
	private TextBlockService&MockObject $blocks;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->kinds = $this->createMock(TimelineKindService::class);
		$this->references = $this->createMock(ReferenceService::class);
		$this->blocks = $this->createMock(TextBlockService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->controller = new TimelineAdminController(
			'openregister',
			$this->request,
			$this->kinds,
			$this->references,
			$this->blocks,
			$this->userSession,
			$this->groupManager,
			$this->createMock(LoggerInterface::class)
		);
	}

	private function signIn(bool $isAdmin): void {
		$uid = 'handler';
		if ($isAdmin === true) {
			$uid = 'admin';
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn($isAdmin);
	}

	private function kind(string $slug = 'contactmoment'): TimelineKind {
		$kind = new TimelineKind();
		$kind->setSlug($slug);
		$kind->setProperties(['channel' => ['type' => 'string']]);

		return $kind;
	}

	public function testAHandlerMayReadTheKinds(): void {
		$this->request->method('getParams')->willReturn(['register' => 'zaken', 'schema' => 'zaak']);
		$this->kinds->expects($this->once())->method('listKinds')
			->with('zaken', 'zaak')
			->willReturn([$this->kind()]);

		$data = $this->controller->kinds()->getData();

		$this->assertSame('contactmoment', $data['results'][0]['slug']);
	}

	public function testAnEmptyScopeParameterNarrowsNothing(): void {
		$this->request->method('getParams')->willReturn(['register' => '  ']);
		$this->kinds->expects($this->once())->method('listKinds')->with(null, null)->willReturn([]);

		$this->controller->kinds();
	}

	public function testAHandlerMayNotDeclareAKind(): void {
		$this->signIn(false);
		$this->kinds->expects($this->never())->method('declareKind');

		$this->assertSame(403, $this->controller->declareKind()->getStatus());
	}

	public function testAnAnonymousCallerIsNotEvenAsked(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->kinds->expects($this->never())->method('declareKind');

		$this->assertSame(401, $this->controller->declareKind()->getStatus());
	}

	public function testAnAdministratorDeclaresAKind(): void {
		$this->signIn(true);
		$this->request->method('getParams')->willReturn(['slug' => 'contactmoment']);
		$this->kinds->expects($this->once())->method('declareKind')->willReturn($this->kind());

		$this->assertSame('contactmoment', $this->controller->declareKind()->getData()['slug']);
	}

	public function testADeclarationThatDoesNotFitIsABadRequestNamingTheField(): void {
		$this->signIn(true);
		$this->request->method('getParams')->willReturn([]);
		$this->kinds->method('declareKind')
			->willThrowException(new TimelineValidationException(['slug' => 'A kind needs a slug']));

		$response = $this->controller->declareKind();

		$this->assertSame(400, $response->getStatus());
		$this->assertArrayHasKey('slug', $response->getData()['errors']);
	}

	public function testWithdrawingAKindNobodyDeclaredIsNotFound(): void {
		$this->signIn(true);
		$this->kinds->method('withdraw')->willReturn(false);

		$this->assertSame(404, $this->controller->withdrawKind('contactmoment')->getStatus());
	}

	public function testWithdrawingAKindSaysSo(): void {
		$this->signIn(true);
		$this->kinds->method('withdraw')->willReturn(true);

		$this->assertTrue($this->controller->withdrawKind('contactmoment')->getData()['withdrawn']);
	}

	public function testAHandlerMayReadThePatternsIncludingTheDisabledOnes(): void {
		$pattern = new ReferencePattern();
		$pattern->setSlug('zaaknummer');
		$pattern->setPattern('Z-\d{4}');
		// Disabled ones are listed too: an administrator reading the panel has
		// to see what is switched off, not only what is running.
		$this->references->expects($this->once())->method('listPatterns')
			->with(false)
			->willReturn([$pattern]);

		$this->assertSame('zaaknummer', $this->controller->patterns()->getData()['results'][0]['slug']);
	}

	public function testAHandlerMayNotDeclareAPattern(): void {
		$this->signIn(false);
		$this->references->expects($this->never())->method('declarePattern');

		$this->assertSame(403, $this->controller->declarePattern()->getStatus());
	}

	public function testAnExpressionThatDoesNotCompileIsABadRequest(): void {
		$this->signIn(true);
		$this->request->method('getParams')->willReturn(['slug' => 'broken', 'pattern' => 'Z-[0-9']);
		$this->references->method('declarePattern')
			->willThrowException(new TimelineValidationException(['pattern' => 'not usable']));

		$this->assertSame(400, $this->controller->declarePattern()->getStatus());
	}

	public function testWithdrawingAPatternNobodyDeclaredIsNotFound(): void {
		$this->signIn(true);
		$this->references->method('withdrawPattern')->willReturn(false);

		$this->assertSame(404, $this->controller->withdrawPattern('zaaknummer')->getStatus());
	}

	public function testAHandlerMayReadTheTextBlocksInScope(): void {
		$block = new TextBlock();
		$block->setSlug('ontvangstbevestiging');
		$this->request->method('getParams')->willReturn(['register' => 'zaken', 'schema' => 'zaak']);
		$this->blocks->expects($this->once())->method('listBlocks')
			->with('zaken', 'zaak')
			->willReturn([$block]);

		$this->assertSame(
			'ontvangstbevestiging',
			$this->controller->textBlocks()->getData()['results'][0]['slug'],
		);
	}

	public function testAHandlerMayNotAdministerATextBlock(): void {
		$this->signIn(false);
		$this->blocks->expects($this->never())->method('declareBlock');

		$this->assertSame(403, $this->controller->declareTextBlock()->getStatus());
	}

	public function testAnAdministratorAdministersATextBlock(): void {
		$this->signIn(true);
		$this->request->method('getParams')->willReturn(['slug' => 'ontvangst', 'body' => 'tekst']);

		$block = new TextBlock();
		$block->setSlug('ontvangst');
		$this->blocks->expects($this->once())->method('declareBlock')->willReturn($block);

		$this->assertSame('ontvangst', $this->controller->declareTextBlock()->getData()['slug']);
	}

	public function testWithdrawingATextBlockNobodyAdministeredIsNotFound(): void {
		$this->signIn(true);
		$this->blocks->method('withdrawBlock')->willReturn(false);

		$this->assertSame(404, $this->controller->withdrawTextBlock('ontvangst')->getStatus());
	}

	public function testAFailingDeclarationAnswersFiveHundredAndSaysNothingAboutTheInstance(): void {
		$this->signIn(true);
		$this->request->method('getParams')->willReturn(['slug' => 'x']);
		$this->kinds->method('declareKind')->willThrowException(new \RuntimeException('table openregister_timeline_kinds is gone'));

		$response = $this->controller->declareKind();

		$this->assertSame(500, $response->getStatus());
		$this->assertStringNotContainsString('openregister_timeline_kinds', $response->getData()['message']);
	}
}
