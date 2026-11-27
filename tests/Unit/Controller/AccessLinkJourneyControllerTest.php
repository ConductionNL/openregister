<?php

/**
 * Changing a record through an `edit` link, over HTTP (or-form-and-journey-registry, task 6.3).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-form-link-may-open-an-existing-record-for-change-req-fjr-008
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Controller\AccessLinkJourneyController;
use OCA\OpenRegister\Controller\Support\AccessLinkPlatform;
use OCA\OpenRegister\Controller\Support\LinkJourneyGate;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Kernel\Contracts\AccessLinkReaderInterface;
use OCA\OpenRegister\Service\Form\LinkUpdateRun;
use OCA\OpenRegister\Service\Hardening\ThrottledSurfacesInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkServiceInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkUse;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Controller\AccessLinkJourneyController
 * @uses   \OCA\OpenRegister\Controller\Support\AccessLinkPlatform
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectShareLinkThrottle
 * @covers \OCA\OpenRegister\Controller\Support\LinkJourneyGate
 * @uses   \OCA\OpenRegister\Db\AccessLink
 * @uses   \OCA\OpenRegister\Db\AccessLink\AccessLinkFieldTypes
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\StoredEntity
 * @uses   \OCA\OpenRegister\Exception\FormSubmitRefusedException
 * @uses   \OCA\OpenRegister\Service\Sharing\AccessLinkUse
 * @uses   \OCA\OpenRegister\Service\Sharing\LinkAct
 */
class AccessLinkJourneyControllerTest extends TestCase {

	private const OBJECT = '5f0c0a1e-0000-4000-8000-000000000001';

	private const JOURNEY = '9b2e4c1a-0000-4000-8000-000000000002';

	private AccessLinkServiceInterface&MockObject $links;

	private AccessLinkReaderInterface&MockObject $reader;

	private LinkUpdateRun&MockObject $runs;

	private IThrottler&MockObject $throttler;

	/**
	 * The uses recorded.
	 *
	 * @var list<AccessLinkUse>
	 */
	private array $uses = [];

	protected function setUp(): void {
		parent::setUp();
		$this->links = $this->createMock(AccessLinkServiceInterface::class);
		$this->links->method('recordUse')->willReturnCallback(
			function (AccessLink $link, AccessLinkUse $use): void {
				$this->uses[] = $use;
			}
		);
		$this->reader = $this->createMock(AccessLinkReaderInterface::class);
		$this->reader->method('subjectObject')->willReturn($this->object());
		$this->runs = $this->createMock(LinkUpdateRun::class);
		$this->throttler = $this->createMock(IThrottler::class);
	}

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid(self::OBJECT);
		$object->setObject(['telefoon' => '0612345678', 'status' => 'open']);
		return $object;
	}

	private function link(string $capabilities = 'read,edit'): AccessLink {
		$link = new AccessLink();
		$link->setUuid('7a1f0f2e-0000-4000-8000-000000000003');
		$link->setSubjectType(AccessLink::SUBJECT_OBJECT);
		$link->setSubjectId(self::OBJECT);
		$link->setCapabilities($capabilities);
		$link->setJourney(self::JOURNEY);
		$link->setExpiresAt(new DateTime('2999-01-01T00:00:00+00:00'));
		return $link;
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function controller(?AccessLink $link, bool $passwordOk = true, array $params = []): AccessLinkJourneyController {
		$this->links->method('resolve')->with('Anchor123')->willReturn($link);
		$this->links->method('passwordAccepted')->willReturn($passwordOk);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $name, mixed $default = null): mixed => ($params[$name] ?? $default));
		$request->method('getRemoteAddress')->willReturn('192.0.2.7');
		$request->method('getHeader')->willReturn('');

		return new AccessLinkJourneyController(
			request: $request,
			gate: new LinkJourneyGate(
				links: $this->links,
				reader: $this->reader,
				platform: new AccessLinkPlatform(userSession: $this->createMock(IUserSession::class), throttler: $this->throttler, logger: new NullLogger())
			),
			runs: $this->runs,
			logger: new NullLogger()
		);
	}

	public function testTheRunIsServedPrefilledAndTheReadIsRecorded(): void {
		$run = ['journey' => self::JOURNEY, 'object' => self::OBJECT, 'step' => 'contact', 'answers' => ['telefoon' => '0612345678']];
		$this->runs->expects($this->once())->method('start')->willReturn($run);

		$response = $this->controller(link: $this->link())->show(anchor: 'Anchor123');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($run, $response->getData()['run']);
		$this->assertSame(['read', 'edit'], $response->getData()['link']['capabilities']);
		$this->assertSame(self::JOURNEY, $response->getData()['link']['journey']);
		$this->assertCount(1, $this->uses);
		$this->assertSame(AccessLinkServiceInterface::ACT_READ, $this->uses[0]->act);
		$this->assertSame(['journey' => self::JOURNEY], $this->uses[0]->context);
		$this->assertSame('192.0.2.7', $this->uses[0]->ipAddress);
	}

	public function testASubmitChangesTheRecordAndRecordsTheEditWithTheChangedProperties(): void {
		$saved = $this->object();
		$saved->setObject(['telefoon' => '0687654321', 'status' => 'open']);
		$this->runs->expects($this->once())->method('submit')->with(
			$this->isInstanceOf(AccessLink::class),
			$this->callback(static fn (ObjectEntity $object): bool => $object->getUuid() === self::OBJECT),
			['telefoon' => '0687654321']
		)->willReturn(['journey' => self::JOURNEY, 'object' => self::OBJECT, 'changed' => ['telefoon'], 'answers' => ['telefoon' => '0687654321'], 'saved' => $saved]);

		$response = $this->controller(link: $this->link(), params: ['answers' => ['telefoon' => '0687654321']])->submit(anchor: 'Anchor123');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['journey' => self::JOURNEY, 'object' => self::OBJECT, 'changed' => ['telefoon'], 'answers' => ['telefoon' => '0687654321']], $response->getData());
		$this->assertCount(1, $this->uses);
		$this->assertSame(AccessLinkServiceInterface::ACT_EDIT, $this->uses[0]->act);
		$this->assertSame($saved, $this->uses[0]->object);
		$this->assertSame(['journey' => self::JOURNEY, 'changed' => ['telefoon']], $this->uses[0]->context);
	}

	public function testAnUndeclaredFieldAnswers422WithTheFindingsAndRecordsNothing(): void {
		$findings = [['field' => 'status', 'code' => 'out-of-journey', 'message' => 'The field status is not part of this journey.']];
		$this->runs->method('submit')->willThrowException(new FormSubmitRefusedException(message: 'Some answers are not part of this journey.', status: 422, findings: $findings));

		$response = $this->controller(link: $this->link(), params: ['answers' => ['status' => 'afgehandeld']])->submit(anchor: 'Anchor123');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['message' => 'Some answers are not part of this journey.', 'findings' => $findings], $response->getData());
		$this->assertSame([], $this->uses);
	}

	public function testAJourneyThatNoLongerFitsAndAnUnknownAnchorGiveTheSameCounted404(): void {
		$this->runs->method('start')->willThrowException(new FormSubmitRefusedException(message: 'This journey does not exist.', status: 404));
		$this->throttler->expects($this->once())->method('registerAttempt')->with(ThrottledSurfacesInterface::ACCESS_LINK, '192.0.2.7');

		$unfit = $this->controller(link: $this->link())->show(anchor: 'Anchor123');
		$this->setUp();
		$this->throttler->expects($this->once())->method('registerAttempt');
		$unknown = $this->controller(link: null)->show(anchor: 'Anchor123');

		$this->assertSame([404, ['message' => 'Not Found']], [$unfit->getStatus(), $unfit->getData()]);
		$this->assertSame([404, ['message' => 'Not Found']], [$unknown->getStatus(), $unknown->getData()]);
	}

	public function testALinkWithoutEditIs403AndAWrongPasswordIs401(): void {
		$this->runs->expects($this->never())->method('start');
		$this->runs->expects($this->never())->method('submit');

		$readOnly = $this->controller(link: $this->link(capabilities: 'read,comment'))->submit(anchor: 'Anchor123');
		$this->assertSame(403, $readOnly->getStatus());
		$this->assertSame(['read', 'comment'], $readOnly->getData()['capabilities']);

		$this->setUp();
		$this->runs->expects($this->never())->method('start');
		$locked = $this->controller(link: $this->link(), passwordOk: false)->show(anchor: 'Anchor123');
		$this->assertSame(401, $locked->getStatus());
		$this->assertTrue($locked->getData()['passwordRequired']);
	}

	public function testAnswersThatAreNotAnObjectAndAFailedSaveAre400(): void {
		$this->runs->expects($this->never())->method('submit');
		$bad = $this->controller(link: $this->link(), params: ['answers' => 'telefoon=1'])->submit(anchor: 'Anchor123');
		$this->assertSame([400, ['message' => 'The answers must be an object of field values.']], [$bad->getStatus(), $bad->getData()]);

		$this->setUp();
		$this->runs->method('submit')->willThrowException(new RuntimeException('validation failed'));
		$failed = $this->controller(link: $this->link(), params: ['answers' => ['telefoon' => 'x']])->submit(anchor: 'Anchor123');
		$this->assertSame([400, ['message' => 'The change could not be saved.']], [$failed->getStatus(), $failed->getData()]);
		$this->assertSame([], $this->uses);
	}

	public function testBothRoutesArePublicRateLimitedAndThrottled(): void {
		foreach (['show', 'submit'] as $name) {
			$method = new ReflectionMethod(AccessLinkJourneyController::class, $name);
			foreach ([PublicPage::class, NoCSRFRequired::class, AnonRateLimit::class] as $attribute) {
				$this->assertCount(1, $method->getAttributes($attribute), "$name $attribute");
			}

			$throttle = $method->getAttributes(BruteForceProtection::class);
			$this->assertCount(1, $throttle, $name);
			$this->assertSame(ThrottledSurfacesInterface::ACCESS_LINK, $throttle[0]->getArguments()['action'], $name);
		}
	}
}//end class
