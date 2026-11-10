<?php

/**
 * Golden tests for minting and using an access link.
 *
 * Pins, against the code as it stood before the parameter-count refactor, the
 * exact row a mint writes, every refusal a mint answers (and which one wins
 * when a request is wrong in two ways), the exact audit entry a use writes, and
 * the mint guard's allowed and refused outcomes per subject kind. Construction
 * and the use call each go through one helper, so a change to a constructor or
 * to the use signature edits only those helpers and never an expectation.
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

use OCA\OpenRegister\Db\MagicMapperInterface;
use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\AccessLinkMapper;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Search\Db\ViewMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Sharing\AccessLinkMintGuard;
use OCA\OpenRegister\Service\Sharing\AccessLinkSecrets;
use OCA\OpenRegister\Service\Sharing\AccessLinkService;
use OCA\OpenRegister\Service\Sharing\AccessLinkTerms;
use OCA\OpenRegister\Service\Sharing\LinkMintRequest;
use OCA\OpenRegister\Service\Sharing\AccessLinkSubject;
use OCA\OpenRegister\Service\Sharing\AccessLinkUse;
use OCA\OpenRegister\Service\Sharing\AccessLinkUseLog;
use OCA\OpenRegister\Service\Sharing\AccessLinkViewReaders;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use OCP\IURLGenerator;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class AccessLinkGoldenTest extends TestCase {

	private const ANCHOR = 'AnchorAnchorAnchorAnchorAnchorAnchorAnchor1';

	private AccessLinkMapper&MockObject $mapper;
	private AuditTrailMapper&MockObject $auditTrail;
	private ISecureRandom&MockObject $secureRandom;
	private IHasher&MockObject $hasher;
	private IURLGenerator&MockObject $urlGenerator;
	private LoggerInterface&MockObject $logger;
	private ObjectService&MockObject $objects;
	private ViewMapper&MockObject $views;
	private RegisterMapper&MockObject $registers;
	private SchemaMapper&MockObject $schemas;

	/**
	 * The rows handed to insert(), in order.
	 *
	 * @var array<int, AccessLink>
	 */
	private array $inserted = [];

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(AccessLinkMapper::class);
		$this->auditTrail = $this->createMock(AuditTrailMapper::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->hasher = $this->createMock(IHasher::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->views = $this->createMock(ViewMapper::class);
		$this->registers = $this->createMock(RegisterMapper::class);
		$this->schemas = $this->createMock(SchemaMapper::class);

		$this->urlGenerator->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $parameters = []): string => '/' . $route . '/' . ($parameters['anchor'] ?? '')
		);
		$this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://nc.example.org' . $path
		);
		$this->mapper->method('insert')->willReturnCallback(
			function (AccessLink $link): AccessLink {
				$this->inserted[] = $link;
				return $link;
			}
		);
	}

	// ---- Construction and the use call: the only places a refactor edits. --

	private function service(): AccessLinkService {
		return new AccessLinkService(
			mapper: $this->mapper,
			useLog: new AccessLinkUseLog(auditTrail: $this->auditTrail, logger: $this->logger),
			secrets: new AccessLinkSecrets(secureRandom: $this->secureRandom, hasher: $this->hasher),
			urlGenerator: $this->urlGenerator
		);
	}

	private function guard(): AccessLinkMintGuard {
		return new AccessLinkMintGuard(
			objects: $this->objects,
			viewReaders: new AccessLinkViewReaders(views: $this->views, catalog: new SchemaCatalog(registers: $this->registers, schemas: $this->schemas, tables: $this->createMock(MagicMapperInterface::class)))
		);
	}

	/**
	 * Record one use through the service.
	 *
	 * @param AccessLink $link The link.
	 * @param string $act The act.
	 * @param ObjectEntity|null $object The object, when one resolved.
	 * @param string|null $ipAddress The calling address.
	 * @param array<string, mixed>|null $context Extra context; null means the caller passed none.
	 */
	private function recordUse(
		AccessLink $link,
		string $act,
		?ObjectEntity $object,
		?string $ipAddress,
		?array $context = null,
	): void {
		if ($context === null) {
			$this->service()->recordUse(link: $link, use: new AccessLinkUse(act: $act, object: $object, ipAddress: $ipAddress));
			return;
		}

		$this->service()->recordUse(
			link: $link,
			use: new AccessLinkUse(act: $act, object: $object, ipAddress: $ipAddress, context: $context)
		);
	}

	// ---- mint: allowed. ----------------------------------------------------

	public function testAMintWritesExactlyThisRowAndAnswersTheOwnerDescriptor(): void {
		$this->secureRandom->expects($this->once())
			->method('generate')
			->with(43, ISecureRandom::CHAR_ALPHANUMERIC)
			->willReturn(self::ANCHOR);
		$this->hasher->expects($this->once())->method('hash')->with(' geheim ')->willReturn('1|hash');

		$before = new DateTime();
		$minted = $this->service()->mint(
			new LinkMintRequest(
				minter: '  owner  ',
				subjectType: ' OBJECT ',
				subjectId: '  object-uuid  ',
				terms: new AccessLinkTerms(
					capabilities: ['Upload', ' ', 'comment', 'READ', 'upload'],
					expiresAt: '+7 days',
					password: ' geheim ',
					label: '  Advice request  '
				)
			)
		);
		$after = new DateTime();

		$this->assertCount(1, $this->inserted);
		$row = $this->inserted[0];
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string)$row->getUuid());
		$this->assertSame(self::ANCHOR, $row->getAnchor());
		$this->assertSame('object', $row->getSubjectType());
		$this->assertSame('object-uuid', $row->getSubjectId());
		$this->assertSame('read,upload,comment', $row->getCapabilities());
		$this->assertSame('Advice request', $row->getLabel());
		$this->assertSame('1|hash', $row->getPasswordHash());
		$this->assertSame('owner', $row->getCreatedBy());
		$this->assertGreaterThanOrEqual($before, $row->getCreated());
		$this->assertLessThanOrEqual($after, $row->getCreated());
		$this->assertGreaterThan($after, $row->getExpiresAt());
		$this->assertNull($row->getRevokedAt());
		$this->assertFalse($row->getDisabled());
		$this->assertNull($row->getLastUsedAt());
		$this->assertSame(0, $row->getUseCount());

		$this->assertSame(
			array_merge(
				$row->jsonSerialize(),
				[
					'url' => 'https://nc.example.org/openregister.accessLink.open/' . self::ANCHOR,
					'pageUrl' => 'https://nc.example.org/openregister.accessLinkPage.show/' . self::ANCHOR,
				]
			),
			$minted
		);
	}

	public function testAMintWithNoPasswordAndABlankLabelStoresNeither(): void {
		$this->secureRandom->method('generate')->willReturn(self::ANCHOR);
		$this->hasher->expects($this->never())->method('hash');

		$minted = $this->service()->mint(
			new LinkMintRequest(
				minter: 'owner',
				subjectType: AccessLink::SUBJECT_FILE,
				subjectId: 'object-uuid/12',
				terms: new AccessLinkTerms(
					expiresAt: '+1 day',
					password: '',
					label: '   '
				)
			)
		);

		$row = $this->inserted[0];
		$this->assertNull($row->getPasswordHash());
		$this->assertNull($row->getLabel());
		$this->assertSame('read', $row->getCapabilities());
		$this->assertSame('file', $minted['subjectType']);
		$this->assertSame('object-uuid/12', $minted['subjectId']);
		$this->assertFalse($minted['hasPassword']);
	}

	// ---- mint: refused, and which refusal wins. ----------------------------

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedMints(): array {
		$ok = [
			'userId' => 'owner',
			'subjectType' => 'object',
			'subjectId' => 'object-uuid',
			'capabilities' => [],
			'expiresAt' => '+1 day',
		];

		return [
			'blank user' => [array_merge($ok, ['userId' => ' ']), 'A logged-in user is required to mint an access link.'],
			'blank user beats unknown kind' => [
				array_merge($ok, ['userId' => '', 'subjectType' => 'register']),
				'A logged-in user is required to mint an access link.',
			],
			'unknown kind' => [
				array_merge($ok, ['subjectType' => ' Register ']),
				"'register' is not a link subject: use object, view, file.",
			],
			'unknown kind beats blank subject' => [
				array_merge($ok, ['subjectType' => 'register', 'subjectId' => '']),
				"'register' is not a link subject: use object, view, file.",
			],
			'blank subject' => [
				array_merge($ok, ['subjectId' => '  ']),
				'An access link must name the subject it opens.',
			],
			'blank subject beats bad capability' => [
				array_merge($ok, ['subjectId' => '', 'capabilities' => ['delete']]),
				'An access link must name the subject it opens.',
			],
			'non-string capability' => [
				array_merge($ok, ['capabilities' => [7]]),
				'A capability must be named as a string.',
			],
			'unknown capability' => [
				array_merge($ok, ['capabilities' => ['read', ' Delete ']]),
				"'delete' is not a link capability: use read, comment, upload.",
			],
			'bad capability beats missing expiry' => [
				array_merge($ok, ['capabilities' => ['delete'], 'expiresAt' => null]),
				"'delete' is not a link capability: use read, comment, upload.",
			],
			'missing expiry' => [
				array_merge($ok, ['expiresAt' => null]),
				'An access link must carry an expiry: pass expiresAt as a date this instance can read.',
			],
			'blank expiry' => [
				array_merge($ok, ['expiresAt' => '   ']),
				'An access link must carry an expiry: pass expiresAt as a date this instance can read.',
			],
			'unreadable expiry' => [
				array_merge($ok, ['expiresAt' => ' whenever ']),
				"'whenever' is not a date this instance can read: pass expiresAt as an ISO 8601 date.",
			],
			'past expiry' => [
				array_merge($ok, ['expiresAt' => '2001-01-01T00:00:00+00:00']),
				'An access link must expire in the future.',
			],
		];
	}

	/**
	 * @param array<string, mixed> $request The mint arguments.
	 * @param string $message The exact refusal.
	 *
	 * @dataProvider refusedMints
	 */
	public function testARefusedMintNamesItsReasonAndWritesNothing(array $request, string $message): void {
		$this->mapper->expects($this->never())->method('insert');
		$this->secureRandom->expects($this->never())->method('generate');
		$this->hasher->expects($this->never())->method('hash');

		try {
			$this->service()->mint(
				new LinkMintRequest(
					minter: $request['userId'],
					subjectType: $request['subjectType'],
					subjectId: $request['subjectId'],
					terms: new AccessLinkTerms(
						capabilities: $request['capabilities'],
						expiresAt: $request['expiresAt'],
						password: 'geheim',
						label: 'label'
					)
				)
			);
			$this->fail('The mint was not refused.');
		} catch (InvalidArgumentException $refused) {
			$this->assertSame($message, $refused->getMessage());
		}
	}

	// ---- recordUse. --------------------------------------------------------

	private function link(?string $label = 'Advice request'): AccessLink {
		$link = new AccessLink();
		$link->setUuid('7a1f0f2e-0000-4000-8000-000000000001');
		$link->setAnchor(self::ANCHOR);
		$link->setSubjectType(AccessLink::SUBJECT_OBJECT);
		$link->setSubjectId('object-uuid');
		$link->setCapabilities('read,upload');
		$link->setLabel($label);
		$link->setCreatedBy('owner');
		$link->setCreated(new DateTime('-1 hour'));
		$link->setExpiresAt(new DateTime('+1 day'));
		$link->setDisabled(false);
		$link->setUseCount(2);

		return $link;
	}

	public function testAUseWritesExactlyThisEntryAndBumpsTheRowAfterIt(): void {
		$link = $this->link();
		$object = new ObjectEntity();
		$calls = [];

		$this->auditTrail->expects($this->once())
			->method('createAuditTrailEntry')
			->with(
				$object,
				AccessLinkService::ACT_UPLOAD,
				[
					'fileName' => 'advies.pdf',
					'subjectId' => 'object-uuid',
					'accessLink' => '7a1f0f2e-0000-4000-8000-000000000001',
					'capabilities' => ['read', 'upload'],
					'subjectType' => 'object',
					'ipAddress' => '198.51.100.7',
				],
				'link:7a1f0f2e-0000-4000-8000-000000000001',
				'Advice request',
				'198.51.100.7'
			)
			->willReturnCallback(
				function () use (&$calls): AuditTrail {
					$calls[] = 'audit';
					return new AuditTrail();
				}
			);
		$this->mapper->expects($this->once())
			->method('update')
			->with($link)
			->willReturnCallback(
				function (AccessLink $row) use (&$calls): AccessLink {
					$calls[] = 'update';
					return $row;
				}
			);
		$this->logger->expects($this->never())->method('error');

		$this->recordUse(
			link: $link,
			act: AccessLinkService::ACT_UPLOAD,
			object: $object,
			ipAddress: '198.51.100.7',
			context: ['fileName' => 'advies.pdf', 'subjectId' => 'caller-said-something-else']
		);

		$this->assertSame(['audit', 'update'], $calls);
		$this->assertSame(3, $link->getUseCount());
		$this->assertNotNull($link->getLastUsedAt());
	}

	public function testAUseWithNoContextAndNoAddressStillNamesTheLink(): void {
		$link = $this->link(label: null);
		$object = new ObjectEntity();

		$this->auditTrail->expects($this->once())
			->method('createAuditTrailEntry')
			->with(
				$object,
				AccessLinkService::ACT_READ,
				[
					'accessLink' => '7a1f0f2e-0000-4000-8000-000000000001',
					'capabilities' => ['read', 'upload'],
					'subjectType' => 'object',
					'subjectId' => 'object-uuid',
					'ipAddress' => null,
				],
				'link:7a1f0f2e-0000-4000-8000-000000000001',
				'link:7a1f0f2e-0000-4000-8000-000000000001',
				null
			)
			->willReturn(new AuditTrail());

		$this->recordUse(link: $link, act: AccessLinkService::ACT_READ, object: $object, ipAddress: null);

		$this->assertSame(3, $link->getUseCount());
	}

	public function testAFailedAuditWriteIsLoggedAndTheUseIsStillCounted(): void {
		$link = $this->link();
		$this->auditTrail->method('createAuditTrailEntry')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'[AccessLinkService] Could not record a link use: db down',
				['accessLink' => '7a1f0f2e-0000-4000-8000-000000000001', 'act' => AccessLinkService::ACT_COMMENT]
			);
		$this->mapper->expects($this->once())->method('update');

		$this->recordUse(link: $link, act: AccessLinkService::ACT_COMMENT, object: new ObjectEntity(), ipAddress: '10.0.0.1');

		$this->assertSame(3, $link->getUseCount());
	}

	public function testAUseWithNoObjectWritesNoEntryButCounts(): void {
		$link = $this->link();
		$this->auditTrail->expects($this->never())->method('createAuditTrailEntry');
		$this->mapper->expects($this->once())->method('update');

		$this->recordUse(link: $link, act: AccessLinkService::ACT_READ, object: null, ipAddress: '10.0.0.1');

		$this->assertSame(3, $link->getUseCount());
	}

	public function testAFailedCounterWriteNeverFailsTheUse(): void {
		$link = $this->link();
		$this->auditTrail->expects($this->once())->method('createAuditTrailEntry')->willReturn(new AuditTrail());
		$this->mapper->method('update')->willThrowException(new RuntimeException('db down'));

		$this->recordUse(link: $link, act: AccessLinkService::ACT_READ, object: new ObjectEntity(), ipAddress: null);

		$this->assertSame(3, $link->getUseCount());
	}

	// ---- The mint guard: allowed and refused per subject kind. -------------

	private function readable(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('object-uuid');

		return $object;
	}

	private function view(array $query): View {
		$view = new View();
		$view->setQuery($query);

		return $view;
	}

	public function testTheGuardAdmitsAReadableObjectAskedWithTheRulesOn(): void {
		$this->objects->expects($this->once())
			->method('find')
			->with(...FindCallShape::findArgs('object-uuid', [], false, null, null, true, true, false, false))
			->willReturn($this->readable());

		$this->assertTrue($this->guard()->mayMint(subjectType: AccessLink::SUBJECT_OBJECT, subjectId: 'object-uuid'));
	}

	public function testTheGuardAdmitsAFileOnlyThroughItsReadableOwner(): void {
		$this->objects->expects($this->once())
			->method('find')
			->with(...FindCallShape::findArgs('object-uuid', [], false, null, null, true, true, false, false))
			->willReturn($this->readable());

		$this->assertTrue($this->guard()->mayMint(subjectType: AccessLink::SUBJECT_FILE, subjectId: 'object-uuid/12'));
	}

	public function testTheGuardRefusesObjectsTheCallerCannotHave(): void {
		$deleted = $this->readable();
		$deleted->setDeleted(['deletedBy' => 'x']);
		$this->objects->method('find')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('denied')),
			null,
			$deleted
		);
		$guard = $this->guard();

		$this->assertFalse($guard->mayMint(subjectType: AccessLink::SUBJECT_OBJECT, subjectId: 'object-uuid'));
		$this->assertFalse($guard->mayMint(subjectType: AccessLink::SUBJECT_OBJECT, subjectId: 'object-uuid'));
		$this->assertFalse($guard->mayMint(subjectType: AccessLink::SUBJECT_OBJECT, subjectId: 'object-uuid'));
	}

	public function testTheGuardRefusesMalformedSubjectsWithoutAsking(): void {
		$this->objects->expects($this->never())->method('find');
		$this->views->expects($this->never())->method('find');
		$guard = $this->guard();

		$this->assertFalse($guard->mayMint(subjectType: AccessLink::SUBJECT_OBJECT, subjectId: '  '));
		$this->assertFalse($guard->mayMint(subjectType: AccessLink::SUBJECT_VIEW, subjectId: ''));
		$this->assertFalse($guard->mayMint(subjectType: AccessLink::SUBJECT_FILE, subjectId: 'object-uuid'));
	}

	public function testTheGuardJudgesAFileSubjectByItsOwnerHalfAlone(): void {
		// Only the owning object is checked at mint; an empty file half does not stop the question.
		$this->objects->expects($this->once())->method('find')->willThrowException(new RuntimeException('denied'));

		$this->assertFalse($this->guard()->mayMint(subjectType: AccessLink::SUBJECT_FILE, subjectId: 'object-uuid/'));
	}

	public function testTheGuardAdmitsAViewWhoseEveryTargetAnswersTheCaller(): void {
		$this->views->expects($this->once())
			->method('find')
			->with('view-uuid', true, true)
			->willReturn($this->view(['registers' => [1, 2], 'schemas' => [3]]));
		$this->registers->expects($this->exactly(2))->method('find');
		$this->schemas->expects($this->once())->method('find')->with(3);

		$this->assertTrue($this->guard()->mayMint(subjectType: AccessLink::SUBJECT_VIEW, subjectId: ' view-uuid '));
	}

	public function testTheGuardRefusesAViewTheCallerCannotResolve(): void {
		$this->views->method('find')->willThrowException(new RuntimeException('not yours'));

		$this->assertFalse($this->guard()->mayMint(subjectType: AccessLink::SUBJECT_VIEW, subjectId: 'view-uuid'));
	}

	public function testTheGuardRefusesAViewNamingARegisterTheCallerCannotRead(): void {
		$this->views->method('find')->willReturn($this->view(['registers' => [1, 99], 'schemas' => [3]]));
		$this->registers->method('find')->willReturnCallback(
			static function ($id) {
				if ($id === 99) {
					throw new RuntimeException('other tenant');
				}

				return null;
			}
		);
		$this->schemas->expects($this->never())->method('find');

		$this->assertFalse($this->guard()->mayMint(subjectType: AccessLink::SUBJECT_VIEW, subjectId: 'view-uuid'));
	}

	public function testTheGuardRefusesAViewNamingASchemaTheCallerCannotRead(): void {
		$this->views->method('find')->willReturn($this->view(['schemas' => [3]]));
		$this->schemas->method('find')->willThrowException(new RuntimeException('other tenant'));

		$this->assertFalse($this->guard()->mayMint(subjectType: AccessLink::SUBJECT_VIEW, subjectId: 'view-uuid'));
	}
}
