<?php

/**
 * An `edit` link carries exactly one journey, and only an object link can (or-form-and-journey-registry, task 6.1).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-form-link-may-open-an-existing-record-for-change-req-fjr-008
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Sharing;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\AccessLinkMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Audit\AccessLinkUseRecorder;
use OCA\OpenRegister\Service\Sharing\AccessLinkSecrets;
use OCA\OpenRegister\Service\Sharing\AccessLinkService;
use OCA\OpenRegister\Service\Sharing\AccessLinkTerms;
use OCA\OpenRegister\Service\Sharing\AccessLinkUseLog;
use OCA\OpenRegister\Service\Sharing\LinkMintRequest;
use OCP\IURLGenerator;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Db\AccessLink
 * @covers \OCA\OpenRegister\Service\Sharing\AccessLinkService
 * @covers \OCA\OpenRegister\Service\Sharing\AccessLinkTerms
 * @uses   \OCA\OpenRegister\Service\Sharing\AccessLinkSecrets
 * @uses   \OCA\OpenRegister\Service\Sharing\AccessLinkUseLog
 * @uses   \OCA\OpenRegister\Service\Audit\AccessLinkUseRecorder
 * @uses   \OCA\OpenRegister\Service\Sharing\LinkMintRequest
 * @uses   \OCA\OpenRegister\Db\AccessLink\AccessLinkFieldTypes
 */
class AccessLinkEditMintTest extends TestCase {

	private const JOURNEY = '9b2e4c1a-0000-4000-8000-00000000j001';

	private AccessLinkMapper&MockObject $mapper;

	private AccessLinkService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(AccessLinkMapper::class);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('AnchorValueThatIsOpaque');
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/links/AnchorValueThatIsOpaque');
		$urls->method('getAbsoluteURL')->willReturnArgument(0);

		$this->service = new AccessLinkService(
			mapper: $this->mapper,
			useLog: new AccessLinkUseLog(recorder: new AccessLinkUseRecorder(auditTrail: $this->createMock(AuditTrailMapper::class)), logger: new NullLogger()),
			secrets: new AccessLinkSecrets(secureRandom: $random, hasher: $this->createMock(IHasher::class)),
			urlGenerator: $urls
		);
	}

	private function request(string $subjectType, array $capabilities, ?string $journey): LinkMintRequest {
		return new LinkMintRequest(
			minter: 'owner',
			subjectType: $subjectType,
			subjectId: 'object-uuid',
			terms: new AccessLinkTerms(capabilities: $capabilities, expiresAt: '+1 day', journey: $journey)
		);
	}

	public function testAnEditLinkIsMintedWithItsJourney(): void {
		$stored = null;
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(
			function (AccessLink $link) use (&$stored): AccessLink {
				$stored = $link;
				return $link;
			}
		);

		$minted = $this->service->mint($this->request(subjectType: 'object', capabilities: ['EDIT'], journey: ' ' . self::JOURNEY . ' '));

		$this->assertSame(self::JOURNEY, $stored->getJourney());
		$this->assertSame('read,edit', $stored->getCapabilities());
		$this->assertTrue($stored->allows(capability: AccessLink::CAP_EDIT));
		$this->assertSame(['read', 'edit'], $minted['capabilities']);
		$this->assertSame(self::JOURNEY, $minted['journey']);
	}

	/**
	 * @return array<string, array{0: string, 1: list<string>, 2: string|null, 3: string}>
	 */
	public static function refusals(): array {
		return [
			'edit without a journey' => ['object', ['edit'], null, 'A link that declares edit must name the journey it changes the object through.'],
			'edit with a blank journey' => ['object', ['edit'], '  ', 'A link that declares edit must name the journey it changes the object through.'],
			'a journey without edit' => ['object', ['comment'], self::JOURNEY, 'Only a link that declares edit can carry a journey.'],
			'edit on a view' => ['view', ['edit'], self::JOURNEY, 'Only a link over one object can declare edit.'],
			'edit on a file' => ['file', ['edit'], self::JOURNEY, 'Only a link over one object can declare edit.'],
		];
	}

	/**
	 * @dataProvider refusals
	 */
	public function testAnUnfitEditRequestIsRefusedAndWritesNothing(string $subjectType, array $capabilities, ?string $journey, string $message): void {
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		$this->service->mint($this->request(subjectType: $subjectType, capabilities: $capabilities, journey: $journey));
	}

	public function testALinkWithoutAJourneyNeverDeclaresEdit(): void {
		$link = new AccessLink();
		$link->setSubjectType(AccessLink::SUBJECT_OBJECT);
		$link->setCapabilities('read,edit,comment');
		$link->setExpiresAt(new DateTime('+1 day'));

		$this->assertSame(['read', 'comment'], $link->declaredCapabilities());
		$this->assertFalse($link->allows(capability: 'edit'));
		$this->assertNull($link->journeyId());
		$this->assertArrayNotHasKey('journey', $link->jsonSerialize());
		$this->assertArrayNotHasKey('journey', $link->publicDescriptor());

		$link->setJourney(self::JOURNEY);
		$this->assertSame(['read', 'edit', 'comment'], $link->declaredCapabilities());
		$this->assertTrue($link->allows(capability: ' Edit '));
		$this->assertSame(self::JOURNEY, $link->jsonSerialize()['journey']);
		$this->assertSame(self::JOURNEY, $link->publicDescriptor()['journey']);
	}
}//end class
