<?php

/**
 * Unit tests for AccessLinkService.
 *
 * A link is a credential handed to somebody with no account, so the tests that
 * matter are the refusals: a mint with no expiry, a mint with an expiry that
 * has already passed, a capability this app does not know, a revoked or expired
 * anchor resolving to nothing, and a wrong password opening nothing. The audit
 * test is the other half: four uses of one link write four entries, each naming
 * the link rather than a person, with the calling address on them.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sharing
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

namespace OCA\OpenRegister\Tests\Unit\Service\Sharing;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.WrongStyle -- the section banners above tests are banners, not doc comments.
// phpcs:disable PEAR.Commenting.FunctionComment.MissingReturn -- PHPUnit fixtures and tests; the signature IS the contract.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\AccessLinkMapper;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Sharing\AccessLinkService;
use OCP\IURLGenerator;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class AccessLinkServiceTest extends TestCase {

	private AccessLinkMapper&MockObject $mapper;
	private AuditTrailMapper&MockObject $auditTrail;
	private ISecureRandom&MockObject $secureRandom;
	private IHasher&MockObject $hasher;
	private IURLGenerator&MockObject $urlGenerator;
	private LoggerInterface&MockObject $logger;
	private AccessLinkService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(AccessLinkMapper::class);
		$this->auditTrail = $this->createMock(AuditTrailMapper::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->hasher = $this->createMock(IHasher::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->secureRandom->method('generate')->willReturn('AnchorValueThatIsOpaque');
		$this->urlGenerator->method('linkToRoute')->willReturn('/index.php/apps/openregister/api/public/links/AnchorValueThatIsOpaque');
		$this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://nc.example.org' . $path
		);
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->mapper->method('update')->willReturnArgument(0);

		$this->service = new AccessLinkService(
			mapper: $this->mapper,
			auditTrail: $this->auditTrail,
			secureRandom: $this->secureRandom,
			hasher: $this->hasher,
			urlGenerator: $this->urlGenerator,
			logger: $this->logger
		);
	}

	private function liveLink(string $capabilities = 'read'): AccessLink {
		$link = new AccessLink();
		$link->setUuid('7a1f0f2e-0000-4000-8000-000000000001');
		$link->setAnchor('AnchorValueThatIsOpaque');
		$link->setSubjectType(AccessLink::SUBJECT_OBJECT);
		$link->setSubjectId('object-uuid');
		$link->setCapabilities($capabilities);
		$link->setCreatedBy('owner');
		$link->setCreated(new DateTime('-1 hour'));
		$link->setExpiresAt(new DateTime('+1 day'));
		$link->setDisabled(false);
		$link->setUseCount(0);

		return $link;
	}

	// ---- Task 2.3: an expiry is required at mint. --------------------------

	public function testAMintWithNoExpiryIsRefusedNamingTheRequirement(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/must carry an expiry/');

		$this->service->mint(
			userId: 'owner',
			subjectType: AccessLink::SUBJECT_OBJECT,
			subjectId: 'object-uuid'
		);
	}

	public function testAMintWithAnExpiryAlreadyPastIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/expire in the future/');

		$this->service->mint(
			userId: 'owner',
			subjectType: AccessLink::SUBJECT_OBJECT,
			subjectId: 'object-uuid',
			expiresAt: '2001-01-01T00:00:00+00:00'
		);
	}

	public function testAMintWithAnUnreadableExpiryIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service->mint(
			userId: 'owner',
			subjectType: AccessLink::SUBJECT_OBJECT,
			subjectId: 'object-uuid',
			expiresAt: 'whenever'
		);
	}

	// ---- Task 1.1: the anchor is random, not derived. ----------------------

	public function testTheAnchorIsDrawnFromTheSecureRandomAndNotFromTheSubject(): void {
		$minted = $this->mintOne();

		$this->assertSame('AnchorValueThatIsOpaque', $minted['anchor']);
		$this->assertStringNotContainsString('object-uuid', (string)$minted['anchor']);
		$this->assertStringContainsString('AnchorValueThatIsOpaque', (string)$minted['url']);
	}

	public function testAnAnchorConstructedFromTheSubjectResolvesToNothing(): void {
		$this->mapper->method('findByAnchor')->with('object-uuid')->willReturn(null);

		$this->assertNull($this->service->resolve(anchor: 'object-uuid'));
	}

	// ---- Tasks 2.1 and 2.2: declared capabilities. -------------------------

	public function testCapabilitiesDefaultToReadOnly(): void {
		$minted = $this->mintOne();

		$this->assertSame(['read'], $minted['capabilities']);
	}

	public function testReadIsAlwaysPresentBesideAnotherCapability(): void {
		$minted = $this->mintOne(capabilities: ['comment']);

		$this->assertSame(['read', 'comment'], $minted['capabilities']);
	}

	public function testACapabilityThisAppDoesNotKnowIsRefusedRatherThanDropped(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/is not a link capability/');

		$this->mintOne(capabilities: ['read', 'delete']);
	}

	public function testASubjectKindThisAppDoesNotKnowIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/is not a link subject/');

		$this->service->mint(
			userId: 'owner',
			subjectType: 'register',
			subjectId: 'x',
			expiresAt: '+1 day'
		);
	}

	public function testAnAnonymousMintIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service->mint(
			userId: '  ',
			subjectType: AccessLink::SUBJECT_OBJECT,
			subjectId: 'object-uuid',
			expiresAt: '+1 day'
		);
	}

	// ---- Task 3.1: revoked, expired and switched off all resolve to null. --

	public function testALiveAnchorResolves(): void {
		$this->mapper->method('findByAnchor')->willReturn($this->liveLink());

		$this->assertInstanceOf(AccessLink::class, $this->service->resolve(anchor: 'AnchorValueThatIsOpaque'));
	}

	public function testARevokedAnchorResolvesToNothing(): void {
		$link = $this->liveLink();
		$link->setRevokedAt(new DateTime('-1 minute'));
		$this->mapper->method('findByAnchor')->willReturn($link);

		$this->assertNull($this->service->resolve(anchor: 'AnchorValueThatIsOpaque'));
	}

	public function testAnExpiredAnchorResolvesToNothing(): void {
		$link = $this->liveLink();
		$link->setExpiresAt(new DateTime('-1 minute'));
		$this->mapper->method('findByAnchor')->willReturn($link);

		$this->assertNull($this->service->resolve(anchor: 'AnchorValueThatIsOpaque'));
	}

	public function testASwitchedOffAnchorResolvesToNothing(): void {
		$link = $this->liveLink();
		$link->setDisabled(true);
		$this->mapper->method('findByAnchor')->willReturn($link);

		$this->assertNull($this->service->resolve(anchor: 'AnchorValueThatIsOpaque'));
	}

	public function testAnUnknownAnchorResolvesToNothing(): void {
		$this->mapper->method('findByAnchor')->willReturn(null);

		$this->assertNull($this->service->resolve(anchor: 'NotAnAnchor'));
	}

	// ---- Task 2.3: the password is checked at use. -------------------------

	public function testALinkWithNoPasswordOpensWithoutOne(): void {
		$this->assertTrue($this->service->passwordAccepted(link: $this->liveLink(), password: null));
	}

	public function testALinkWithAPasswordNeverOpensWithoutOne(): void {
		$link = $this->liveLink();
		$link->setPasswordHash('1|hashed');

		$this->assertFalse($this->service->passwordAccepted(link: $link, password: null));
		$this->assertFalse($this->service->passwordAccepted(link: $link, password: ''));
	}

	public function testAWrongPasswordOpensNothing(): void {
		$link = $this->liveLink();
		$link->setPasswordHash('1|hashed');
		$this->hasher->method('verify')->willReturn(false);

		$this->assertFalse($this->service->passwordAccepted(link: $link, password: 'guess'));
	}

	public function testTheRightPasswordOpensTheLink(): void {
		$link = $this->liveLink();
		$link->setPasswordHash('1|hashed');
		$this->hasher->expects($this->once())
			->method('verify')
			->with('geheim', '1|hashed')
			->willReturn(true);

		$this->assertTrue($this->service->passwordAccepted(link: $link, password: 'geheim'));
	}

	public function testAPasswordIsStoredHashedAndNeverInTheClear(): void {
		$this->hasher->expects($this->once())->method('hash')->with('geheim')->willReturn('1|hashed');

		$minted = $this->mintOne(password: 'geheim');

		$this->assertTrue($minted['hasPassword']);
		$this->assertArrayNotHasKey('passwordHash', $minted);
	}

	// ---- Task 3.1: revocation, and only by the minter. ---------------------

	public function testRevokingSetsTheRevocationStamp(): void {
		$link = $this->liveLink();
		$this->mapper->method('findById')->willReturn($link);

		$this->assertTrue($this->service->revoke(id: 1, userId: 'owner'));
		$this->assertNotNull($link->getRevokedAt());
		$this->assertFalse($link->isLive());
	}

	public function testALinkSomebodyElseMintedCannotBeRevoked(): void {
		$this->mapper->method('findById')->willReturn($this->liveLink());

		$this->assertFalse($this->service->revoke(id: 1, userId: 'somebody-else'));
	}

	public function testSwitchingOffALinkSomebodyElseMintedAnswersNothing(): void {
		$this->mapper->method('findById')->willReturn($this->liveLink());

		$this->assertNull($this->service->setDisabled(id: 1, userId: 'somebody-else', disabled: true));
	}

	public function testSwitchingOffAndBackOnIsReversible(): void {
		$link = $this->liveLink();
		$this->mapper->method('findById')->willReturn($link);

		$off = $this->service->setDisabled(id: 1, userId: 'owner', disabled: true);
		$this->assertIsArray($off);
		$this->assertTrue($off['disabled']);
		$this->assertFalse($link->isLive());

		$on = $this->service->setDisabled(id: 1, userId: 'owner', disabled: false);
		$this->assertIsArray($on);
		$this->assertFalse($on['disabled']);
		$this->assertTrue($link->isLive());
	}

	// ---- Task 3.2: every use is audited, as the link. ----------------------

	public function testFourUsesOfOneLinkWriteFourEntriesNamingTheLink(): void {
		$link = $this->liveLink();
		$link->setLabel('Advice request');
		$object = $this->createMock(ObjectEntity::class);

		$actors = [];
		$addresses = [];
		$actions = [];
		$this->auditTrail->expects($this->exactly(4))
			->method('createAuditTrailEntry')
			->willReturnCallback(
				function (
					ObjectEntity $audited,
					string $action,
					array $context,
					?string $actorId,
					?string $actorName,
					?string $ipAddress,
				) use (&$actors, &$addresses, &$actions): AuditTrail {
					unset($audited, $context, $actorName);
					$actors[] = $actorId;
					$actions[] = $action;
					$addresses[] = $ipAddress;

					return new AuditTrail();
				}
			);

		foreach ([
			AccessLinkService::ACT_READ,
			AccessLinkService::ACT_READ,
			AccessLinkService::ACT_COMMENT,
			AccessLinkService::ACT_READ,
		] as $act) {
			$this->service->recordUse(
				link: $link,
				act: $act,
				object: $object,
				ipAddress: '198.51.100.7'
			);
		}

		$this->assertSame(
			['link:7a1f0f2e-0000-4000-8000-000000000001'],
			array_values(array_unique($actors)),
			'every entry names the link and never a user'
		);
		$this->assertSame(['198.51.100.7'], array_values(array_unique($addresses)));
		$this->assertSame(
			[
				AccessLinkService::ACT_READ,
				AccessLinkService::ACT_READ,
				AccessLinkService::ACT_COMMENT,
				AccessLinkService::ACT_READ,
			],
			$actions
		);
		$this->assertSame(4, $link->getUseCount());
		$this->assertNotNull($link->getLastUsedAt());
	}

	public function testAUseWithNoResolvedObjectStillCountsOnTheRow(): void {
		$link = $this->liveLink();
		$this->auditTrail->expects($this->never())->method('createAuditTrailEntry');

		$this->service->recordUse(link: $link, act: AccessLinkService::ACT_READ, object: null);

		$this->assertSame(1, $link->getUseCount());
	}

	// ---- Listing. ----------------------------------------------------------

	public function testAnAnonymousListingIsEmpty(): void {
		$this->assertSame([], $this->service->listForUser(userId: ''));
	}

	public function testTheListingCarriesTheUrlForEachLink(): void {
		$this->mapper->method('findByCreator')->willReturn([$this->liveLink()]);

		$rows = $this->service->listForUser(userId: 'owner');

		$this->assertCount(1, $rows);
		$this->assertStringContainsString('AnchorValueThatIsOpaque', (string)$rows[0]['url']);
	}

	/**
	 * Mint one link with the awkward arguments already filled in.
	 *
	 * @param array<int, string> $capabilities The capabilities to declare.
	 * @param string|null $password The password, when the link carries one.
	 *
	 * @return array<string, mixed> The minted link.
	 */
	private function mintOne(array $capabilities = [], ?string $password = null): array {
		return $this->service->mint(
			userId: 'owner',
			subjectType: AccessLink::SUBJECT_OBJECT,
			subjectId: 'object-uuid',
			capabilities: $capabilities,
			expiresAt: '+7 days',
			password: $password,
			label: 'Advice request'
		);
	}
}
