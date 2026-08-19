<?php

/**
 * `updateObject()` REPLACES; `patchObject()` MERGES. Pinned side by side.
 *
 * The published contract used to document `updateObject()` as "apply a partial
 * update" while it was implemented as `$data['id'] = $id; saveObject($data)` —
 * no read, no merge. Meanwhile the genuinely merging `patchObject()` was not on
 * `ObjectServiceInterface` at all, so a consumer that migrated a one-key update
 * onto the contract silently erased every field it did not send AND reported
 * success. procest's `withdraw()` is the payload modelled below; the obvious
 * migration would have wiped that publication's title, summary, category and
 * case reference.
 *
 * These tests exist because nothing else can tell the two apart. Both methods
 * return an object and neither throws, so a functional check passes either way —
 * the erasure is only visible in the payload each hands to the save pipeline.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\AuditHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\MergeHandler;
use OCA\OpenRegister\Service\Object\MetadataHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\IAppContainer;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * The replace/merge distinction, and the contract that publishes both.
 */
class ObjectServiceUpdateVersusPatchTest extends TestCase {

	/**
	 * The stored object. Every key except `status` is one the partial payload
	 * below does NOT mention, so each is a field that replace semantics destroy.
	 */
	private const STORED = [
		'title'         => 'Quarterly report Q2',
		'summary'       => 'An overview of the second quarter.',
		'category'      => 'finance',
		'caseReference' => 'ZAAK-2026-0042',
		'publishedAt'   => '2026-04-01',
		'status'        => 'published',
	];

	/**
	 * The one-key payload. This is the shape of procest's `withdraw()`.
	 */
	private const PARTIAL = ['status' => 'withdrawn'];

	private ObjectService $service;

	private ReflectionClass $reflection;

	/** @var MockObject&MagicMapper */
	private $objectMapper;

	/** @var MockObject&CascadingHandler */
	private $cascadingHandler;

	private Register $register;

	private Schema $schema;

	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper     = $this->createMock(MagicMapper::class);
		$this->cascadingHandler = $this->createMock(CascadingHandler::class);

		$this->register = new Register();
		$this->register->setId(1);

		$this->schema = new Schema();
		$this->schema->setId(2);

		$this->service = new ObjectService(
			$this->createMock(DataManipulationHandler::class),
			$this->createMock(DeleteObject::class),
			$this->createMock(GetObject::class),
			$this->createMock(PermissionHandler::class),
			$this->createMock(RenderObject::class),
			$this->createMock(SaveObject::class),
			$this->createMock(SaveObjects::class),
			$this->createMock(SearchQueryHandler::class),
			$this->createMock(ValidateObject::class),
			$this->createMock(LockHandler::class),
			$this->createMock(AuditHandler::class),
			$this->createMock(RelationHandler::class),
			$this->createMock(MergeHandler::class),
			$this->createMock(FacetHandler::class),
			$this->createMock(MetadataHandler::class),
			$this->createMock(PerformanceOptimizationHandler::class),
			$this->createMock(QueryHandler::class),
			$this->createMock(RevertHandler::class),
			$this->createMock(UtilityHandler::class),
			$this->createMock(ValidationHandler::class),
			$this->cascadingHandler,
			$this->createMock(MigrationHandler::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(ViewMapper::class),
			$this->objectMapper,
			$this->createMock(FileService::class),
			$this->createMock(IUserSession::class),
			$this->createMock(SearchTrailService::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(CacheHandler::class),
			$this->createMock(SettingsService::class),
			$this->createMock(DateTimeNormalizer::class),
			$this->createMock(IAppContainer::class),
			$this->createMock(ObjectSourceRegistry::class)
		);

		$this->reflection = new ReflectionClass(ObjectService::class);

		$stored = new ObjectEntity();
		$stored->setUuid('u-1');
		$stored->setObject(self::STORED);
		$this->objectMapper->method('find')->willReturn($stored);

		$this->setProperty('currentRegister', $this->register);
		$this->setProperty('currentSchema', $this->schema);

	}//end setUp()

	private function setProperty(string $name, mixed $value): void {
		$property = $this->reflection->getProperty($name);
		$property->setAccessible(true);
		$property->setValue($this->service, $value);

	}//end setProperty()

	/**
	 * Capture the payload that reaches the save pipeline.
	 *
	 * `handlePreValidationCascading()` is inside `saveObject()`, which is where
	 * BOTH methods converge — so it observes the one thing that differs between
	 * them: what each decided to save. This reads the OBSERVED EFFECT rather
	 * than a mock's recorded argument list, which matters because a PHPUnit
	 * mock cannot see named arguments and both call sites use them.
	 *
	 * @param callable $call The service call to run.
	 *
	 * @return array<string, mixed> The payload handed to the save.
	 */
	private function payloadReachingTheSave(callable $call): array {
		$seen = null;
		$this->cascadingHandler->method('handlePreValidationCascading')->willReturnCallback(
			static function (array $object, ?Schema $schema = null, ?string $uuid = null) use (&$seen): array {
				$seen = $object;

				return [$object, $uuid];
			}
		);

		try {
			$call();
		} catch (Throwable $e) {
			// Expected — the rest of the save pipeline is mocked out. The
			// payload has already been captured by the time anything downstream
			// complains, and the payload is the entire subject here.
		}

		$this->assertNotNull($seen, 'the payload never reached the save pipeline — the observation point is wrong, not the behaviour');

		return $seen;

	}//end payloadReachingTheSave()

	// ── The distinction ─────────────────────────────────────────────────

	/**
	 * `updateObject()` drops every field the partial payload omitted.
	 *
	 * This is the defect's blast radius, asserted rather than described.
	 *
	 * @return void
	 */
	public function testUpdateObjectDropsTheFieldsThePayloadDidNotSend(): void {
		$saved = $this->payloadReachingTheSave(
			fn () => $this->service->updateObject(objectId: 'u-1', data: self::PARTIAL)
		);

		$this->assertSame('withdrawn', $saved['status'], 'the one field that WAS sent is applied');

		foreach (['title', 'summary', 'category', 'caseReference', 'publishedAt'] as $erased) {
			$this->assertArrayNotHasKey(
				$erased,
				$saved,
				"updateObject() REPLACES: '{$erased}' was stored, was not sent, and must not survive. "
				. 'If this key is present, updateObject() has started merging — which is a behaviour '
				. 'change for every caller that passes a complete object and relies on omission clearing.'
			);
		}

	}//end testUpdateObjectDropsTheFieldsThePayloadDidNotSend()

	/**
	 * `patchObject()` preserves every field the same partial payload omitted.
	 *
	 * @return void
	 */
	public function testPatchObjectPreservesTheFieldsThePayloadDidNotSend(): void {
		$saved = $this->payloadReachingTheSave(
			fn () => $this->service->patchObject(objectId: 'u-1', data: self::PARTIAL)
		);

		$this->assertSame('withdrawn', $saved['status'], 'the field that WAS sent is applied');

		$this->assertSame('Quarterly report Q2', $saved['title'], 'an unmentioned field survives a patch');
		$this->assertSame('An overview of the second quarter.', $saved['summary']);
		$this->assertSame('finance', $saved['category']);
		$this->assertSame('ZAAK-2026-0042', $saved['caseReference']);
		$this->assertSame('2026-04-01', $saved['publishedAt']);

	}//end testPatchObjectPreservesTheFieldsThePayloadDidNotSend()

	/**
	 * The two methods disagree on the SAME payload, and that is the point.
	 *
	 * Asserting the difference directly means neither half can be quietly
	 * changed into the other without this failing.
	 *
	 * @return void
	 */
	public function testTheTwoMethodsDisagreeOnTheIdenticalPayload(): void {
		$replaced = $this->payloadReachingTheSave(
			fn () => $this->service->updateObject(objectId: 'u-1', data: self::PARTIAL)
		);
		$merged = $this->payloadReachingTheSave(
			fn () => $this->service->patchObject(objectId: 'u-1', data: self::PARTIAL)
		);

		$this->assertNotEquals(
			$replaced,
			$merged,
			'updateObject() and patchObject() must not agree on a partial payload — if they do, '
			. 'one of them is wrong and the contract is lying about at least one of them'
		);

		$lost = array_diff(array_keys(self::STORED), array_keys($replaced));
		sort($lost);
		$this->assertSame(
			['caseReference', 'category', 'publishedAt', 'summary', 'title'],
			$lost,
			'five stored fields are lost by the replacing path'
		);

		$survived = array_intersect(array_keys(self::STORED), array_keys($merged));
		sort($survived);
		$this->assertSame(
			['caseReference', 'category', 'publishedAt', 'status', 'summary', 'title'],
			$survived,
			'all six survive the merging path'
		);

	}//end testTheTwoMethodsDisagreeOnTheIdenticalPayload()

	// ── The published contract ──────────────────────────────────────────

	/**
	 * `patchObject()` is reachable through the published contract.
	 *
	 * The merging path existing on the concrete class was never the problem —
	 * it was that a consumer type-hinting the interface could not call it, and
	 * so reached for `updateObject()` on the strength of its docblock.
	 *
	 * @return void
	 */
	public function testThePublishedContractDeclaresBothWritePaths(): void {
		$contract = new ReflectionClass(ObjectServiceInterface::class);

		$this->assertTrue($contract->hasMethod('updateObject'), 'the replacing path stays published');
		$this->assertTrue(
			$contract->hasMethod('patchObject'),
			'the merging path must be reachable through the contract, or every consumer '
			. 'reimplements read-merge-write or silently erases data'
		);

	}//end testThePublishedContractDeclaresBothWritePaths()

	/**
	 * The published signature mirrors the implementation, defaults included.
	 *
	 * Parameter NAMES are load-bearing: consumers call these by name, and a
	 * renamed parameter is a silent break at the call site rather than at
	 * class load. Defaults are equally part of the signature — a double that
	 * omits `_rbac=true` tests the unscoped path while looking correct.
	 *
	 * @return void
	 */
	public function testThePublishedPatchSignatureMirrorsTheImplementation(): void {
		$published      = new ReflectionMethod(ObjectServiceInterface::class, 'patchObject');
		$implementation = new ReflectionMethod(ObjectService::class, 'patchObject');

		$names = array_map(
			static fn ($parameter) => $parameter->getName(),
			$published->getParameters()
		);
		$this->assertSame(
			['objectId', 'data', 'register', 'schema', '_rbac', '_multitenancy', 'currentUser'],
			$names,
			'the contract names the same parameters the implementation does'
		);

		$this->assertSame(
			$names,
			array_map(static fn ($parameter) => $parameter->getName(), $implementation->getParameters()),
			'contract and implementation must not drift apart on parameter names'
		);

		$defaults = [];
		foreach ($published->getParameters() as $parameter) {
			if ($parameter->isDefaultValueAvailable() === true) {
				$defaults[$parameter->getName()] = $parameter->getDefaultValue();
			}
		}

		$this->assertSame(
			[
				'register'      => null,
				'schema'        => null,
				'_rbac'         => true,
				'_multitenancy' => true,
				'currentUser'   => null,
			],
			$defaults,
			'RBAC and multitenancy default to ON — a contract that defaulted them off '
			. 'would publish the unscoped path as the easy one'
		);

		$this->assertSame(
			'?OCP\IUser',
			(string) $published->getParameters()[6]->getType(),
			'a sessionless caller can be attributed through the contract'
		);

	}//end testThePublishedPatchSignatureMirrorsTheImplementation()

	/**
	 * The contract no longer describes `updateObject()` as a partial update.
	 *
	 * The docblock IS the deliverable here. Three defects in this campaign
	 * existed only because documentation asserted something the code did not
	 * do, and the description is what a consuming developer actually reads
	 * before choosing between two similarly named methods.
	 *
	 * @return void
	 */
	public function testTheContractDoesNotDescribeUpdateObjectAsAPartialUpdate(): void {
		$doc = (new ReflectionMethod(ObjectServiceInterface::class, 'updateObject'))->getDocComment();

		$this->assertIsString($doc, 'updateObject() must stay documented on the contract');

		// The SUMMARY line — the first prose line of the block, and the only part
		// an IDE tooltip or a docs generator shows next to the method name. The
		// defect lived here: the body can explain the history at length, but the
		// one line a chooser actually reads must not promise a merge.
		$summary = '';
		foreach (explode("\n", $doc) as $line) {
			$line = trim(ltrim(trim($line), '/*'));
			if ($line !== '') {
				$summary = $line;
				break;
			}
		}

		$this->assertNotSame('', $summary, 'updateObject() must carry a summary line');

		$this->assertDoesNotMatchRegularExpression(
			'/partial update/i',
			$summary,
			"the summary line reads '{$summary}' — describing this method as a partial update "
			. 'is what sent a consumer down the erasing path. updateObject() REPLACES.'
		);

		$this->assertMatchesRegularExpression(
			'/REPLACE|does NOT merge/i',
			$summary,
			"the summary line reads '{$summary}' — it must say plainly that this method "
			. 'replaces rather than merges'
		);

		$this->assertStringContainsString(
			'patchObject',
			$doc,
			'a reader who wants a partial update must be pointed at the method that does one'
		);

	}//end testTheContractDoesNotDescribeUpdateObjectAsAPartialUpdate()
}//end class
