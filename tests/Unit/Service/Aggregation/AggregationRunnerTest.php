<?php

/**
 * Unit tests for the metadata-read bypass on `AggregationRunner::loadSchema()`
 * and `AggregationRunner::loadRegister()` — see auth-system requirement
 * "Schema and register METADATA-READ lookups MUST bypass multi-tenancy".
 *
 * Locks in:
 * - `loadSchema()` MUST call `SchemaMapper::find(..., _multitenancy: false)`.
 * - `loadRegister()` MUST call `RegisterMapper::find(..., _multitenancy: false)`.
 * - The unknown-ref path MUST rethrow `DoesNotExistException` as
 *   `RuntimeException('Schema "%s" not found.')` / register equivalent.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/aggregation-runner-multitenancy-policy/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * No `covers` metadata, deliberately — see
 * {@see AggregationJoinAndCompositeGroupByTest} for the measurement.
 *
 * Under `beStrictAboutCoverageMetadata="true"`, naming the class under test
 * makes PHPUnit mark every test that also touches a collaborator RISKY and
 * DISCARD that test's coverage wholesale. Almost every test here legitimately
 * runs one — AggregationQuery, PlaceholderResolver, the Db entities — so the
 * annotation threw the measurement away instead of focusing it, and the
 * subject reported 0%. #2847
 */
class AggregationRunnerTest extends TestCase {

	private MagicMapper&MockObject $magicMapper;

	private RegisterMapper&MockObject $registerMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private PlaceholderResolver $placeholderResolver;

	private IDBConnection&MockObject $db;

	private AggregationCache&MockObject $cache;

	private PermissionHandler&MockObject $permissionHandler;

	private IUserSession&MockObject $userSession;

	private OrganisationService&MockObject $organisationService;

	/**
	 * Stand up every collaborator the runner needs. Only the schema/register
	 * mapper expectations matter for these tests; the rest are inert mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->cache = $this->createMock(AggregationCache::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->placeholderResolver = new PlaceholderResolver($this->userSession);

		$this->userSession->method('getUser')->willReturn(null);
		$this->organisationService->method('getActiveOrganisation')->willReturn(null);

	}//end setUp()

	/**
	 * Build the runner with the mocked collaborators.
	 *
	 * @return AggregationRunner
	 */
	private function makeRunner(): AggregationRunner {
		return new AggregationRunner(
			magicMapper: $this->magicMapper,
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper,
			placeholders: $this->placeholderResolver,
			db: $this->db,
			cache: $this->cache,
			permissionHandler: $this->permissionHandler,
			userSession: $this->userSession,
			organisationService: $this->organisationService,
			organizationHandler: $this->orgHandlerScopedTo('__no_active_org__'),
			translationHandler: $this->createMock(TranslationHandler::class),
			languageService: $this->createMock(LanguageService::class)
		);

	}//end makeRunner()

	/**
	 * `loadSchema` is private; reflection lets the test exercise it directly
	 * without dragging an end-to-end aggregation through the suite.
	 *
	 * @param AggregationRunner $runner The runner instance.
	 * @param string $method The private method name.
	 *
	 * @return ReflectionMethod The accessible reflection handle.
	 */
	private function privateMethod(AggregationRunner $runner, string $method): ReflectionMethod {
		$ref = new ReflectionMethod($runner, $method);
		$ref->setAccessible(true);
		return $ref;
	}//end privateMethod()

	/**
	 * Locks the metadata-read bypass for loadSchema(): the call MUST pass
	 * `_multitenancy: false` to `SchemaMapper::find`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/aggregation-runner-multitenancy-policy/specs/auth-system/spec.md
	 */
	public function testLoadSchemaPassesMultitenancyFalseToTheMapper(): void {
		$schema = $this->createMock(Schema::class);

		$this->schemaMapper->expects($this->once())
			->method('find')
			->with(
				$this->equalTo('meldingen'),
				$this->anything(),
				$this->anything(),
				$this->isFalse()
			)
			->willReturn($schema);

		$runner = $this->makeRunner();
		$result = $this->privateMethod($runner, 'loadSchema')->invoke($runner, 'meldingen');

		$this->assertSame($schema, $result, 'loadSchema MUST return the schema the mapper resolves');

	}//end testLoadSchemaPassesMultitenancyFalseToTheMapper()

	/**
	 * Locks the metadata-read bypass for loadRegister(): the call MUST pass
	 * `_multitenancy: false` to `RegisterMapper::find`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/aggregation-runner-multitenancy-policy/specs/auth-system/spec.md
	 */
	public function testRegisterLoadPassesMultitenancyFalse(): void {
		// The standalone `loadRegister()` is gone — the register is now loaded as
		// part of the register-scoped (register, schema) pair resolution, because
		// resolving a register WITHOUT then bounding a schema by it is the shape
		// that let the aggregation endpoints serve another app's rows. The
		// metadata-read bypass it documented is unchanged and is asserted here at
		// its new home.
		$register = new Register();
		$register->setId(12);
		$register->setSlug('zaken');
		$register->setSchemas([7]);

		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('zaak');

		$this->registerMapper->expects($this->once())
			->method('find')
			->with(
				$this->equalTo('zaken'),
				$this->anything(),
				$this->isFalse()
			)
			->willReturn($register);

		$this->schemaMapper->expects($this->once())
			->method('findInIds')
			->with('zaak', [7])
			->willReturn($schema);
		// The global resolver must not run once a register is named.
		$this->schemaMapper->expects($this->never())->method('find');

		$runner = $this->makeRunner();
		$result = $this->privateMethod($runner, 'loadSchemaInRegister')->invoke($runner, 'zaak', 'zaken');

		$this->assertSame($register, $result['register'], 'the register the mapper resolves MUST be returned');
		$this->assertSame($schema, $result['schema'], 'the schema MUST come from the register-scoped lookup');

	}//end testRegisterLoadPassesMultitenancyFalse()

	/**
	 * Locks the 404-rethrow path: when the mapper raises DoesNotExistException
	 * for an unknown ref, the runner MUST rethrow as RuntimeException with the
	 * exact `Schema "%s" not found.` message AggregationController translates
	 * into HTTP 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/aggregation-runner-multitenancy-policy/specs/auth-system/spec.md
	 */
	public function testLoadSchemaUnknownRefRethrowsAsNotFoundRuntimeException(): void {
		$this->schemaMapper->expects($this->once())
			->method('find')
			->willThrowException(new DoesNotExistException('not found in DB'));

		$runner = $this->makeRunner();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Schema "does-not-exist" not found.');

		$this->privateMethod($runner, 'loadSchema')->invoke($runner, 'does-not-exist');

	}//end testLoadSchemaUnknownRefRethrowsAsNotFoundRuntimeException()

	/**
	 * A MagicOrganizationHandler that reports the caller scoped to exactly one
	 * organisation. The fixtures in these tests seed rows carrying that same
	 * value in `_organisation`, so the rendered predicate matches them — which
	 * is what the old hard-coded `_organisation = :activeOrg` did implicitly.
	 *
	 * @param string $orgUuid The organisation the caller is scoped to.
	 *
	 * @return MagicOrganizationHandler
	 */
	private function orgHandlerScopedTo(string $orgUuid): MagicOrganizationHandler {
		$handler = $this->createMock(MagicOrganizationHandler::class);
		$handler->method('resolveOrganizationScope')->willReturn(
			['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => [$orgUuid]]
		);

		return $handler;
	}//end orgHandlerScopedTo()

}//end class
