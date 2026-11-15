<?php

/**
 * Each public read path answers an anonymous caller without the owner, and a signed-in caller as before.
 *
 * Each path runs through its real controller with a real ObjectEntity, then through the middleware.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use OCA\OpenRegister\Tests\Unit\Controller\Support\FederationControllerFactory;
use DateTime;
use GraphQL\GraphQL;
use OCA\OpenRegister\Controller\FederationController;
use OCA\OpenRegister\Controller\McpController;
use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectsControllerFactory;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Middleware\AnonymousEnvelopeMiddleware;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\FederationShareService;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolver;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolverShaping;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolverRights;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolverObjects;
use OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Rbac\AnonymousEnvelope;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Middleware\AnonymousEnvelopeMiddleware
 * @uses \OCA\OpenRegister\Controller\Support\ObjectReadOptions
 * @uses \OCA\OpenRegister\Controller\Support\ObjectShow
 * @uses \OCA\OpenRegister\Controller\Support\ShowServices
 * @uses \OCA\OpenRegister\Controller\Support\ShownEntity
 * @uses \OCA\OpenRegister\Controller\Support\ObjectsRouteScope
 * @covers \OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator
 * @uses   \OCA\OpenRegister\Service\Rbac\AnonymousEnvelope
 * @uses \OCA\OpenRegister\Controller\Support\ObjectRequestParams
 * @uses   \OCA\OpenRegister\Controller\FederationController
 * @uses \OCA\OpenRegister\Controller\Support\EmptyValueStripper
 * @uses \OCA\OpenRegister\Controller\Support\FederationControllerServices
 * @uses   \OCA\OpenRegister\Controller\ObjectsController
 * @uses \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses \OCA\OpenRegister\Controller\Support\ObjectsControllerServices
 * @uses \OCA\OpenRegister\Controller\Support\ObjectStore
 * @uses \OCA\OpenRegister\Controller\Support\ObjectTransfer
 * @uses \OCA\OpenRegister\Controller\Support\ObjectGovernanceServices
 * @uses \OCA\OpenRegister\Controller\Support\ObjectsExtensions
 * @uses \OCA\OpenRegister\Controller\Support\GeoServices
 * @uses \OCA\OpenRegister\Controller\Support\LinkedDataServices
 * @uses   \OCA\OpenRegister\Db\FederatedShare
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator
 * @uses   \OCA\OpenRegister\Service\GraphQL\GraphQLResolver
 * @uses   \OCA\OpenRegister\Search\Service\GraphQL\Scalar\DateTimeType
 * @uses   \OCA\OpenRegister\Search\Service\GraphQL\Scalar\UuidType
 * @uses   \OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\CompositionHandler
 * @uses   \OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\SchemaCaller
 * @uses   \OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\TypeMapperHandler
 * @uses   \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 * @uses   \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses   \OCA\OpenRegister\Service\WriteCause
 * @uses   \OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\AggregationTypes
 * @uses   \OCA\OpenRegister\Service\Rbac\AggregateVisibility
 * @uses   \OCA\OpenRegister\Support\FilterParams
 * @uses \OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\GraphQLNames
 * @uses \OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\GraphQLScalars
 * @uses \OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\ObjectMetadataFields
 * @uses \OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\ObjectPropertyFields
 * @uses \OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\ObjectTypes
 * @uses \OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\SchemaEntry
 * @uses \OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\SchemaEntryFields
 * @uses \OCA\OpenRegister\Db\Register\RegisterSchemaIds
 * @uses \OCA\OpenRegister\Db\Schema\PropertyAuthorization
 * @uses \OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef
 * @uses \OCA\OpenRegister\Kernel\Contracts\RenderSelection
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses \OCA\OpenRegister\Service\CauseFrame
 * @uses \OCA\OpenRegister\Service\GraphQL\GraphQLResolverObjects
 * @uses \OCA\OpenRegister\Service\GraphQL\GraphQLResolverRights
 * @uses \OCA\OpenRegister\Service\GraphQL\GraphQLResolverShaping
 * @uses \OCA\OpenRegister\Service\Object\ObjectFindRequest
 * @uses \OCA\OpenRegister\Service\Object\PermissionCheckOptions
 * @uses \OCA\OpenRegister\Service\Object\ReadScope
 * @uses \OCA\OpenRegister\Service\Object\TranslationScope
 * @uses \OCA\OpenRegister\Service\Rbac\RbacRuleChecks
 * @uses \OCA\OpenRegister\Service\WritePhase
 */
class AnonymousEnvelopeReadPathsTest extends TestCase {

	/**
	 * The golden of the signed-in answers, recorded on the code before the middleware existed.
	 */
	private const GOLDEN = __DIR__ . '/fixtures/anonymous-envelope-signed-in.json';

	/**
	 * The user id the record is owned, locked, archived and frozen by.
	 */
	private const USER_ID = 'alice-user-id';

	/**
	 * What the last property rule was given to read.
	 *
	 * @var array<int|string, mixed>
	 */
	private array $ruleSaw = [];

	/**
	 * A record carrying a user id in every envelope field that can hold one.
	 *
	 * @return ObjectEntity The record.
	 */
	private function record(): ObjectEntity {
		$record = new ObjectEntity();
		$record->setUuid('11111111-1111-4111-8111-111111111111');
		$record->setRegister('1');
		$record->setSchema('2');
		$record->setObject(['title' => 'A public record']);
		$record->setOwner(self::USER_ID);
		$record->setOrganisation('org-1');
		$record->setGroups(['g1']);
		$record->setAuthorization(['read' => ['public', 'user:' . self::USER_ID]]);
		$record->setLocked(['kind' => 'user', 'user' => self::USER_ID, 'duration' => 60]);
		$record->setArchived(['by' => self::USER_ID, 'at' => '2026-01-02T03:04:05+00:00', 'reason' => null]);
		$record->setFrozen(['by' => self::USER_ID, 'at' => '2026-01-02T03:04:05+00:00', 'reason' => null, 'state' => 'x']);
		$record->setRetention(['legalHold' => ['active' => true, 'placedBy' => self::USER_ID]]);
		$record->setDeleted(['deleted' => '2026-01-02T03:04:05+00:00', 'deletedBy' => self::USER_ID]);
		$record->setContacts(['contact-of-' . self::USER_ID]);
		$record->setCreated(new DateTime('2026-01-02T03:04:05+00:00'));
		$record->setUpdated(new DateTime('2026-01-02T03:04:05+00:00'));

		return $record;
	}//end record()

	/**
	 * A session: anonymous, or signed in.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return IUserSession The session.
	 */
	private function session(bool $signedIn): IUserSession {
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * A real ObjectsController over an ObjectService double that finds the record.
	 *
	 * @return array{0: ObjectsController, 1: ObjectService} The controller and its service.
	 */
	private function objectsController(): array {
		$record = $this->record();

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('getRegister')->willReturn(1);
		$objectService->method('getSchema')->willReturn(2);
		$objectService->method('find')->willReturn($record);
		$objectService->method('renderEntity')->willReturnCallback(static fn () => $record->jsonSerialize());
		$objectService->method('getExtendedObjects')->willReturn([]);
		$objectService->method('buildSearchQuery')->willReturn(['_limit' => 20, '_offset' => 0]);
		$objectService->method('searchObjectsPaginated')->willReturn(
			['results' => [$record], 'total' => 1, 'pages' => 1, 'page' => 1, 'limit' => 20]
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);
		$request->method('getRequestUri')->willReturn('/api/objects/1/2');

		$controller = ObjectsControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$objectService,
			$this->session(signedIn: false),
			$this->createMock(IGroupManager::class),
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class)
		);

		return [$controller, $objectService];
	}//end objectsController()

	/**
	 * A real FederationController over a share that may serve the record.
	 *
	 * @return FederationController The controller.
	 */
	private function federationController(): FederationController {
		$share = new FederatedShare();
		$share->setDirection('outgoing');
		$share->setStatus('accepted');
		$share->setScope('object');
		$share->setRegister('zaken');
		$share->setSchema('zaak');
		$share->setObjectUri('https://remote.example/api/objects/' . $this->record()->getUuid());
		$share->setOrganisation('org-1');
		$share->setPermissions('read');

		$shareMapper = $this->createMock(FederatedShareMapper::class);
		$shareMapper->method('findByToken')->willReturn($share);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($this->record());

		return FederationControllerFactory::create(
			'openregister',
			$this->createMock(IRequest::class),
			$shareMapper,
			$objectService,
			$this->createMock(FederationShareService::class),
			$this->createMock(IThrottler::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end federationController()

	/**
	 * The answer of one read path, before the middleware.
	 *
	 * @param string $path The read path.
	 *
	 * @return Response The controller's answer.
	 */
	private function answer(string $path): Response {
		if ($path === 'federation') {
			return $this->federationController()->object(shareToken: 'tok', id: (string)$this->record()->getUuid());
		}

		[$controller, $objectService] = $this->objectsController();

		return match ($path) {
			'show'   => $controller->show((string)$this->record()->getUuid(), '1', '2', $objectService),
			'index'  => $controller->index('1', '2', $objectService),
			'search' => $controller->objects($objectService),
		};
	}//end answer()

	/**
	 * The answer of one read path as the caller receives it.
	 *
	 * @param string $path     The read path.
	 * @param bool   $signedIn Whether the caller is signed in.
	 *
	 * @return string The rendered answer.
	 */
	private function received(string $path, bool $signedIn): string {
		$middleware = new AnonymousEnvelopeMiddleware($this->session(signedIn: $signedIn), new AnonymousEnvelope());

		return (string)$middleware->afterController(
			$this->createMock(Controller::class),
			$path,
			$this->answer(path: $path)
		)->render();
	}//end received()

	/**
	 * A GraphQL query for a record's owner, run on the real generated schema.
	 *
	 * @param bool $signedIn Whether the caller is signed in.
	 *
	 * @return array<string, mixed> The GraphQL result.
	 */
	private function graphQlResult(bool $signedIn): array {
		$schema = new Schema();
		$schema->setId(2);
		$schema->setSlug('documenten');
		$schema->setTitle('Document');
		$schema->setProperties(['title' => ['type' => 'string']]);

		$register = new Register();
		$register->setId(1);
		$register->setSchemas([2]);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn([$register]);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findAll')->willReturn([$schema]);
		$schemaMapper->method('findMultiple')->willReturn([$schema]);

		$getObject = $this->createMock(GetObject::class);
		$getObject->method('find')->willReturn($this->record());

		$permissionHandler = $this->createMock(PermissionHandler::class);
		$permissionHandler->method('hasPermission')->willReturn(true);

		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->method('filterReadableProperties')->willReturnCallback(
			function (Schema $schema, array $object): array {
				$this->ruleSaw = $object;
				return $object;
			}
		);

		$translation = $this->createMock(TranslationHandler::class);
		$translation->method('resolveTranslationsForRender')->willReturnCallback(
			static fn (array $objectData): array => $objectData
		);

		$resolver = new GraphQLResolver(
			new GraphQLResolverObjects(
				$getObject,
				$this->createMock(ObjectService::class),
				$this->createMock(RelationHandler::class),
				$registerMapper
			),
			new GraphQLResolverRights(
				$permissionHandler,
				$rbac
			),
			new GraphQLResolverShaping(
				$translation,
				$this->createMock(AggregationRunner::class),
				new TimeseriesRequestValidator()
			),
			$this->createMock(LoggerInterface::class)
		);

		$generator = new SchemaGenerator($schemaMapper, $this->session(signedIn: $signedIn), $rbac);
		$generator->setResolver($resolver);

		$uuid = (string)$this->record()->getUuid();

		return GraphQL::executeQuery(
			$generator->generate(),
			'{ document(id: "' . $uuid . '") { title _owner madeBy: _owner } }'
		)->toArray();
	}//end graphQlResult()

	/**
	 * The object read paths that answer anonymous callers.
	 *
	 * @return array<string, array{0: string}> The paths.
	 */
	public static function readPaths(): array {
		return [
			'show'       => ['show'],
			'index'      => ['index'],
			'search'     => ['search'],
			'federation' => ['federation'],
		];
	}//end readPaths()

	/**
	 * An anonymous caller reads the record, without one user id anywhere in the answer.
	 *
	 * @param string $path The read path.
	 *
	 * @return void
	 */
	#[DataProvider('readPaths')]
	public function testAnAnonymousAnswerCarriesNoUserId(string $path): void {
		$received = $this->received(path: $path, signedIn: false);

		$this->assertStringContainsString(needle: 'A public record', haystack: $received);
		$this->assertStringContainsString(needle: 'org-1', haystack: $received);
		$this->assertStringNotContainsString(needle: self::USER_ID, haystack: $received);
	}//end testAnAnonymousAnswerCarriesNoUserId()

	/**
	 * Anonymous GraphQL answers null for the owner, under an alias too, with the rest of the record intact.
	 *
	 * @return void
	 */
	public function testAnAnonymousGraphQlAnswerCarriesNoOwner(): void {
		$this->assertSame(
			expected: ['title' => 'A public record', '_owner' => null, 'madeBy' => null],
			actual: $this->graphQlResult(signedIn: false)['data']['document']
		);
	}//end testAnAnonymousGraphQlAnswerCarriesNoOwner()

	/**
	 * A property rule still reads the owner for an anonymous caller, in the envelope and on the row.
	 *
	 * @return void
	 */
	public function testAnAnonymousRuleStillReadsTheOwner(): void {
		$this->graphQlResult(signedIn: false);

		$this->assertSame(expected: self::USER_ID, actual: $this->ruleSaw['@self']['owner']);
		$this->assertSame(expected: self::USER_ID, actual: $this->ruleSaw['_owner']);
	}//end testAnAnonymousRuleStillReadsTheOwner()

	/**
	 * The signed-in answers are byte for byte what they were before the middleware existed.
	 *
	 * @return void
	 */
	public function testTheSignedInAnswersMatchTheGolden(): void {
		$answers = [];
		foreach (array_keys(self::readPaths()) as $path) {
			$answers[$path] = $this->received(path: $path, signedIn: true);
		}

		$answers['graphql'] = json_encode($this->graphQlResult(signedIn: true));

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($answers, JSON_PRETTY_PRINT));
		}

		$this->assertSame(
			expected: json_decode((string)file_get_contents(self::GOLDEN), true),
			actual: $answers
		);
	}//end testTheSignedInAnswersMatchTheGolden()

	/**
	 * Rules read the record, not the answer: redacting leaves the record's envelope whole.
	 *
	 * @return void
	 */
	public function testRedactingLeavesTheRecordsMatchEnvelopeWhole(): void {
		$record = $this->record();
		$before = $record->getMatchEnvelope();

		$middleware = new AnonymousEnvelopeMiddleware($this->session(signedIn: false), new AnonymousEnvelope());
		$answer     = $middleware->afterController(
			$this->createMock(Controller::class),
			'show',
			new JSONResponse(['results' => [$record]])
		);

		$this->assertArrayNotHasKey(key: 'owner', array: $answer->getData()['results'][0]['@self']);
		$this->assertSame(expected: self::USER_ID, actual: $record->getMatchEnvelope()['@self']['owner']);
		$this->assertSame(expected: $before, actual: $record->getMatchEnvelope());
		$this->assertSame(expected: self::USER_ID, actual: $record->jsonSerialize()['@self']['owner']);
	}//end testRedactingLeavesTheRecordsMatchEnvelopeWhole()

	/**
	 * An OCS answer is redacted too; another kind of answer passes untouched, as the same object.
	 *
	 * @return void
	 */
	public function testOnlyDataAnswersAreRedacted(): void {
		$middleware = new AnonymousEnvelopeMiddleware($this->session(signedIn: false), new AnonymousEnvelope());
		$controller = $this->createMock(Controller::class);

		$ocs = $middleware->afterController($controller, 'x', new DataResponse(['@self' => ['owner' => self::USER_ID]]));
		$this->assertSame(expected: ['@self' => []], actual: $ocs->getData());

		$download = new DataDownloadResponse('{}', 'f.json', 'application/json');
		$this->assertSame(expected: $download, actual: $middleware->afterController($controller, 'x', $download));
	}//end testOnlyDataAnswersAreRedacted()

	/**
	 * A signed-in caller's answer is not looked at.
	 *
	 * @return void
	 */
	public function testASignedInAnswerIsNotRewritten(): void {
		$middleware = new AnonymousEnvelopeMiddleware($this->session(signedIn: true), new AnonymousEnvelope());
		$record     = $this->record();
		$answer     = new JSONResponse(['results' => [$record]]);

		$received = $middleware->afterController($this->createMock(Controller::class), 'x', $answer);

		$this->assertSame(expected: $answer, actual: $received);
		$this->assertSame(expected: $record, actual: $received->getData()['results'][0]);
	}//end testASignedInAnswerIsNotRewritten()

	/**
	 * The routes that answer records in a form the middleware does not read stay behind a login.
	 *
	 * Export answers a file, and MCP's public discovery catalogue carries no records.
	 *
	 * @param class-string $controller The controller.
	 * @param string       $method     The route's method.
	 *
	 * @return void
	 */
	#[DataProvider('signedInOnlyRoutes')]
	public function testRoutesOutsideTheMiddlewareStayBehindALogin(string $controller, string $method): void {
		$route = new ReflectionMethod($controller, $method);

		$this->assertStringNotContainsString(needle: '@PublicPage', haystack: (string)$route->getDocComment());
		$this->assertSame(
			expected: [],
			actual: $route->getAttributes(\OCP\AppFramework\Http\Attribute\PublicPage::class)
		);
	}//end testRoutesOutsideTheMiddlewareStayBehindALogin()

	/**
	 * Routes that output records outside a data answer, or live data.
	 *
	 * @return array<string, array{0: class-string, 1: string}> The routes.
	 */
	public static function signedInOnlyRoutes(): array {
		return [
			'export'         => [ObjectsController::class, 'export'],
			'mcp capability' => [McpController::class, 'discoverCapability'],
		];
	}//end signedInOnlyRoutes()
}//end class
