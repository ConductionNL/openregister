<?php

/**
 * Contract tests for AccessLinkController.
 *
 * Every endpoint this change adds is network-facing and three of them carry no
 * session at all, so the contract under test is the status code. A 404 that
 * became a 403 would tell a holder that a revoked record exists; a 403 that
 * became a 200 would hand out a capability nobody declared. Those are the two
 * failures these tests exist to catch, and neither is visible from the happy
 * path alone.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
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

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Controller\AccessLinkController;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Sharing\AccessLinkActs;
use OCA\OpenRegister\Service\Sharing\AccessLinkMintGuard;
use OCA\OpenRegister\Service\Sharing\AccessLinkReader;
use OCA\OpenRegister\Service\Sharing\AccessLinkService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class AccessLinkControllerTest extends TestCase {

	private IRequest&MockObject $request;
	private AccessLinkService&MockObject $links;
	private AccessLinkReader&MockObject $reader;
	private AccessLinkMintGuard&MockObject $mintGuard;
	private AccessLinkActs&MockObject $acts;
	private IUserSession&MockObject $userSession;
	private IThrottler&MockObject $throttler;
	private LoggerInterface&MockObject $logger;
	private AccessLinkController $controller;

	/** @var array<string, mixed> */
	private array $params = [];

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->links = $this->createMock(AccessLinkService::class);
		$this->reader = $this->createMock(AccessLinkReader::class);
		$this->mintGuard = $this->createMock(AccessLinkMintGuard::class);
		$this->acts = $this->createMock(AccessLinkActs::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->throttler = $this->createMock(IThrottler::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->request->method('getRemoteAddress')->willReturn('198.51.100.7');
		$this->request->method('getHeader')->willReturn('');
		$this->request->method('getParam')->willReturnCallback(
			fn (string $name, mixed $default = null): mixed => ($this->params[$name] ?? $default)
		);

		$this->controller = new AccessLinkController(
			appName: 'openregister',
			request: $this->request,
			links: $this->links,
			reader: $this->reader,
			mintGuard: $this->mintGuard,
			acts: $this->acts,
			userSession: $this->userSession,
			throttler: $this->throttler,
			logger: $this->logger
		);
	}

	private function link(string $capabilities = 'read', bool $withPassword = false): AccessLink {
		$link = new AccessLink();
		$link->setUuid('7a1f0f2e-0000-4000-8000-000000000001');
		$link->setAnchor('AnchorValueThatIsOpaque');
		$link->setSubjectType(AccessLink::SUBJECT_OBJECT);
		$link->setSubjectId('object-uuid');
		$link->setCapabilities($capabilities);
		$link->setCreatedBy('owner');
		$link->setExpiresAt(new DateTime('+1 day'));
		if ($withPassword === true) {
			$link->setPasswordHash('1|hashed');
		}

		return $link;
	}

	private function signIn(string $uid = 'owner'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('object-uuid');

		return $object;
	}

	// ---- open(): 404, 401 and 200. -----------------------------------------

	public function testAnUnknownRevokedOrExpiredAnchorAnswersTheSame404(): void {
		$this->links->method('resolve')->willReturn(null);
		$this->throttler->expects($this->once())->method('registerAttempt');

		$response = $this->controller->open(anchor: 'whatever');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Not Found'], $response->getData());
	}

	public function testThe404RevealsNothingAboutTheRecord(): void {
		$this->links->method('resolve')->willReturn(null);

		$body = json_encode($this->controller->open(anchor: 'whatever')->getData());

		$this->assertIsString($body);
		$this->assertStringNotContainsString('object-uuid', $body);
		$this->assertStringNotContainsString('Bezwaar', $body);
	}

	public function testALiveLinkServesItsSubject(): void {
		$this->links->method('resolve')->willReturn($this->link());
		$this->links->method('passwordAccepted')->willReturn(true);
		$this->reader->method('read')->willReturn(['subject' => ['onderwerp' => 'Bezwaar'], 'timeline' => []]);
		$this->reader->method('subjectObject')->willReturn($this->object());
		$this->links->expects($this->once())
			->method('recordUse')
			->with(
				$this->anything(),
				AccessLinkService::ACT_READ,
				$this->anything(),
				'198.51.100.7'
			);

		$response = $this->controller->open(anchor: 'AnchorValueThatIsOpaque');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['read'], $data['link']['capabilities']);
		$this->assertSame('Bezwaar', $data['subject']['onderwerp']);
		$this->assertArrayNotHasKey('anchor', $data['link']);
	}

	public function testALinkWhoseSubjectIsGoneAnswers404(): void {
		$this->links->method('resolve')->willReturn($this->link());
		$this->links->method('passwordAccepted')->willReturn(true);
		$this->reader->method('subjectObject')->willReturn(null);
		$this->reader->method('read')->willReturn(null);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->open(anchor: 'a')->getStatus());
	}

	/**
	 * The subject is fetched once and threaded through, so the public read path
	 * is one database fetch and the audit entry names the object the holder
	 * actually got rather than a second, later read of it.
	 */
	public function testTheSubjectIsResolvedOnceAndHandedToBothTheReaderAndTheAudit(): void {
		$object = $this->object();
		$this->links->method('resolve')->willReturn($this->link());
		$this->links->method('passwordAccepted')->willReturn(true);
		$this->reader->expects($this->once())->method('subjectObject')->willReturn($object);
		$this->reader->expects($this->once())
			->method('read')
			->with($this->anything(), $object)
			->willReturn(['subject' => [], 'timeline' => []]);
		$this->links->expects($this->once())
			->method('recordUse')
			->with($this->anything(), AccessLinkService::ACT_READ, $object, '198.51.100.7');

		$this->assertSame(Http::STATUS_OK, $this->controller->open(anchor: 'a')->getStatus());
	}

	public function testAForwardedLinkStillAsksForItsPassword(): void {
		$this->links->method('resolve')->willReturn($this->link(withPassword: true));
		$this->links->method('passwordAccepted')->willReturn(false);
		$this->reader->expects($this->never())->method('read');

		$response = $this->controller->open(anchor: 'AnchorValueThatIsOpaque');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertTrue($response->getData()['passwordRequired']);
	}

	public function testAWrongPasswordIsCountedAgainstTheThrottler(): void {
		$this->links->method('resolve')->willReturn($this->link(withPassword: true));
		$this->links->method('passwordAccepted')->willReturn(false);
		$this->throttler->expects($this->once())->method('registerAttempt');

		$this->controller->open(anchor: 'AnchorValueThatIsOpaque');
	}

	// ---- comment(): 403 on an undeclared capability. -----------------------

	public function testAnAdviserMayReadAndCommentWhenTheLinkSaysSo(): void {
		$this->links->method('resolve')->willReturn($this->link(capabilities: 'read,comment'));
		$this->links->method('passwordAccepted')->willReturn(true);
		$this->reader->method('subjectObject')->willReturn($this->object());
		$this->params['message'] = 'Advies: akkoord.';
		$this->acts->expects($this->once())
			->method('comment')
			->with($this->anything(), $this->anything(), 'Advies: akkoord.')
			->willReturn(['id' => 4, 'message' => 'Advies: akkoord.']);

		$response = $this->controller->comment(anchor: 'AnchorValueThatIsOpaque');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testACommentThroughAReadOnlyLinkIsRefused(): void {
		$this->links->method('resolve')->willReturn($this->link(capabilities: 'read'));
		$this->links->method('passwordAccepted')->willReturn(true);
		$this->acts->expects($this->never())->method('comment');

		$response = $this->controller->comment(anchor: 'AnchorValueThatIsOpaque');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['read'], $response->getData()['capabilities']);
	}

	public function testAnEmptyCommentIsRefusedAsABadRequest(): void {
		$this->links->method('resolve')->willReturn($this->link(capabilities: 'read,comment'));
		$this->links->method('passwordAccepted')->willReturn(true);
		$this->reader->method('subjectObject')->willReturn($this->object());
		$this->params['message'] = '   ';

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->comment(anchor: 'a')->getStatus());
	}

	// ---- upload(): the refusal the spec names. -----------------------------

	public function testAnAdviserWhoMayReadAndCommentMayNotUpload(): void {
		$this->links->method('resolve')->willReturn($this->link(capabilities: 'read,comment'));
		$this->links->method('passwordAccepted')->willReturn(true);
		$this->acts->expects($this->never())->method('upload');

		$response = $this->controller->upload(anchor: 'AnchorValueThatIsOpaque');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['read', 'comment'], $response->getData()['capabilities']);
	}

	public function testAnUploadThroughALinkThatDeclaresItIsRefusedWithoutAName(): void {
		$this->links->method('resolve')->willReturn($this->link(capabilities: 'read,upload'));
		$this->links->method('passwordAccepted')->willReturn(true);
		$this->reader->method('subjectObject')->willReturn($this->object());
		$this->params['content'] = 'bytes';

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->upload(anchor: 'a')->getStatus());
	}

	public function testAnUploadThroughADeadLinkAnswers404(): void {
		$this->links->method('resolve')->willReturn(null);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->upload(anchor: 'a')->getStatus());
	}

	// ---- mint(). -----------------------------------------------------------

	public function testMintingRefusesAndNamesTheRequirementItRefusedOn(): void {
		$this->signIn();
		$this->mintGuard->method('mayMint')->willReturn(true);
		$this->links->method('mint')->willThrowException(
			new InvalidArgumentException('An access link must carry an expiry: pass expiresAt as a date this instance can read.')
		);

		$response = $this->controller->mint();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('expiry', (string)$response->getData()['message']);
	}

	public function testMintingReturnsTheLinkAndItsUrl(): void {
		$this->signIn();
		$this->mintGuard->method('mayMint')->willReturn(true);
		$this->params['subjectType'] = 'object';
		$this->params['subjectId'] = 'object-uuid';
		$this->params['capabilities'] = ['read', 'comment'];
		$this->params['expiresAt'] = '2026-12-31T00:00:00+00:00';
		$this->links->expects($this->once())
			->method('mint')
			->with('owner', 'object', 'object-uuid', ['read', 'comment'], '2026-12-31T00:00:00+00:00', null, null)
			->willReturn(['anchor' => 'AnchorValueThatIsOpaque', 'url' => 'https://nc.example.org/x']);

		$response = $this->controller->mint();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testMintingAcceptsACommaSeparatedCapabilityList(): void {
		$this->signIn();
		$this->mintGuard->method('mayMint')->willReturn(true);
		$this->params['capabilities'] = 'read, upload';
		$this->params['expiresAt'] = '2026-12-31T00:00:00+00:00';
		$this->links->expects($this->once())
			->method('mint')
			->with('owner', '', '', ['read', ' upload'], '2026-12-31T00:00:00+00:00', null, null)
			->willReturn([]);

		$this->controller->mint();
	}

	public function testAnAnonymousMintAnswers404(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->mint()->getStatus());
	}

	/**
	 * The guard that carries the whole capability. Every read through a link
	 * runs with the group rules off, so if a mint could name any uuid, any
	 * signed-in user could publish any record and the link would keep serving
	 * it correctly for as long as it lived.
	 */
	public function testAUserCannotPublishASubjectTheyMayNotReadThemselves(): void {
		$this->signIn(uid: 'nieuwsgierige-collega');
		$this->params['subjectType'] = 'object';
		$this->params['subjectId'] = 'somebody-elses-object';
		$this->mintGuard->expects($this->once())
			->method('mayMint')
			->with('object', 'somebody-elses-object')
			->willReturn(false);
		$this->links->expects($this->never())->method('mint');

		$response = $this->controller->mint();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Not Found'], $response->getData());
	}

	// ---- index(), update() and revoke(). -----------------------------------

	public function testTheListingIsScopedToTheCallingPrincipal(): void {
		$this->signIn(uid: 'behandelaar');
		$this->links->expects($this->once())
			->method('listForUser')
			->with('behandelaar')
			->willReturn([['id' => 1]]);

		$response = $this->controller->index();

		$this->assertSame([['id' => 1]], $response->getData()['results']);
	}

	public function testAnAnonymousListingIsEmptyRatherThanEverybodysLinks(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame(['results' => []], $this->controller->index()->getData());
	}

	public function testSwitchingOffALinkSomebodyElseMintedAnswers404(): void {
		$this->signIn(uid: 'somebody-else');
		$this->links->method('setDisabled')->willReturn(null);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->update(id: 1)->getStatus());
	}

	public function testSwitchingOffOwnLinkReturnsTheUpdatedRow(): void {
		$this->signIn();
		$this->params['disabled'] = true;
		$this->links->expects($this->once())
			->method('setDisabled')
			->with(1, 'owner', true)
			->willReturn(['id' => 1, 'disabled' => true]);

		$response = $this->controller->update(id: 1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['disabled']);
	}

	public function testRevokingALinkSomebodyElseMintedAnswers404(): void {
		$this->signIn(uid: 'somebody-else');
		$this->links->method('revoke')->willReturn(false);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->revoke(id: 1)->getStatus());
	}

	public function testRevokingOwnLinkSucceeds(): void {
		$this->signIn();
		$this->links->expects($this->once())->method('revoke')->with(1, 'owner')->willReturn(true);

		$this->assertSame(Http::STATUS_OK, $this->controller->revoke(id: 1)->getStatus());
	}

	public function testAnAnonymousRevokeAnswers404(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->revoke(id: 1)->getStatus());
	}
}
