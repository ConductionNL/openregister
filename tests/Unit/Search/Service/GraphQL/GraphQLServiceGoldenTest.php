<?php

/**
 * Golden test for GraphQLService::execute().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Search\Service\GraphQL
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\GraphQL;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Search\Service\GraphQL\GraphQLErrorFormatter;
use OCA\OpenRegister\Search\Service\GraphQL\GraphQLService;
use OCA\OpenRegister\Search\Service\GraphQL\QueryComplexityAnalyzer;
use OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolver;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Pins what execute() returns and hands its resolvers, whatever shape the
 * service's constructor takes: the service is built by reflection from a map
 * of the collaborators below, so the pin survives regrouping them.
 *
 * @covers \OCA\OpenRegister\Search\Service\GraphQL\GraphQLService
 */
class GraphQLServiceGoldenTest extends TestCase {

	/**
	 * The collaborators, keyed by the type the service asks for.
	 *
	 * @var array<string, object>
	 */
	private array $deps = [];

	/**
	 * The resolver context the `ctx` field last received.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $context = null;

	/**
	 * The app config values the service reads.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The user the session returns.
	 *
	 * @var IUser|null
	 */
	private ?IUser $user = null;

	/**
	 * The remote address the request returns.
	 *
	 * @var string
	 */
	private string $remoteAddress = '';

	/**
	 * Log lines written at error level.
	 *
	 * @var list<string>
	 */
	private array $errors = [];

	/**
	 * Whether schema generation throws.
	 *
	 * @var boolean
	 */
	private bool $generateThrows = false;

	protected function setUp(): void {
		parent::setUp();

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default) => ($this->config[$key] ?? $default)
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn () => $this->user);

		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturnCallback(fn () => $this->remoteAddress);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		$generator = $this->createMock(SchemaGenerator::class);
		$generator->method('generate')->willReturnCallback(fn () => $this->schema());

		$analyzer = $this->createMock(QueryComplexityAnalyzer::class);
		$analyzer->method('analyze')->willReturn(['cost' => 3, 'maxCost' => 500, 'depth' => 1, 'maxDepth' => 10]);

		$formatter = $this->createMock(GraphQLErrorFormatter::class);
		$formatter->method('format')->willReturnCallback(
			fn (Error $e) => ['message' => $e->getMessage(), 'code' => ($e->getExtensions()['code'] ?? null)]
		);

		$this->deps = [
			SchemaGenerator::class           => $generator,
			QueryComplexityAnalyzer::class   => $analyzer,
			GraphQLErrorFormatter::class     => $formatter,
			GraphQLResolver::class           => $this->createMock(GraphQLResolver::class),
			ObjectOperationsInterface::class => $this->createMock(ObjectOperationsInterface::class),
			PermissionHandlerInterface::class         => $this->createMock(PermissionHandlerInterface::class),
			PropertyRbacHandler::class       => $this->createMock(PropertyRbacHandler::class),
			AuditTrailMapperInterface::class => $this->createMock(AuditTrailMapperInterface::class),
			RegisterMapperInterface::class   => $this->createMock(RegisterMapperInterface::class),
			SchemaMapperInterface::class     => $this->createMock(SchemaMapperInterface::class),
			IAppConfig::class                => $config,
			IRequest::class                  => $request,
			IUserSession::class              => $session,
			LoggerInterface::class           => $logger,
		];

		// High enough that an APCu-enabled build never reaches the limit.
		$this->config = ['graphql_rate_limit' => '100000'];
	}//end setUp()

	/**
	 * Build a class from the collaborator map, constructing any type the map
	 * lacks from its own constructor.
	 *
	 * @param string $class The class to build.
	 *
	 * @return object
	 */
	private function build(string $class): object {
		if (isset($this->deps[$class]) === true) {
			return $this->deps[$class];
		}

		$reflection  = new ReflectionClass($class);
		$constructor = $reflection->getConstructor();
		$args        = [];
		foreach (($constructor?->getParameters() ?? []) as $parameter) {
			$type = $parameter->getType();
			$this->assertInstanceOf(ReflectionNamedType::class, $type, $class.'::'.$parameter->getName());
			$args[] = $this->build($type->getName());
		}

		return $reflection->newInstanceArgs($args);
	}//end build()

	private function schema(): Schema {
		if ($this->generateThrows === true) {
			throw new \RuntimeException('generator broke');
		}

		return new Schema(
			[
				'query' => new ObjectType(
					[
						'name'   => 'Query',
						'fields' => [
							'ctx' => [
								'type'    => Type::string(),
								'resolve' => function ($root, array $args, array $context): string {
									$this->context = $context;
									return 'ok';
								},
							],
						],
					]
				),
			]
		);
	}//end schema()

	/**
	 * Build the service and execute a query.
	 *
	 * @param string                    $query     The query.
	 * @param array<string, mixed>|null $variables The variables.
	 * @param string|null               $operation The operation name.
	 *
	 * @return array<string, mixed>
	 */
	private function execute(string $query, ?array $variables = null, ?string $operation = null): array {
		$service = $this->build(GraphQLService::class);
		$this->assertInstanceOf(GraphQLService::class, $service);
		return $service->execute($query, $variables, $operation);
	}//end execute()

	public function testQueryReturnsDataAndComplexity(): void {
		$this->assertSame(
			[
				'data'       => ['ctx' => 'ok'],
				'extensions' => ['complexity' => ['estimated' => 3, 'max' => 500, 'depth' => 1, 'maxDepth' => 10]],
			],
			$this->execute('query Q { ctx }', ['a' => 1], 'Q')
		);
	}//end testQueryReturnsDataAndComplexity()

	public function testResolversReceiveTheContext(): void {
		$this->execute('query Named { ctx }', null, 'Named');

		$this->assertSame(
			[
				'objectService', 'permissionHandler', 'propertyRbac', 'auditTrailMapper', 'registerMapper',
				'schemaMapper', 'schemaGenerator', 'operationName', 'request', 'errors',
			],
			array_keys($this->context ?? [])
		);
		$this->assertSame($this->deps[ObjectOperationsInterface::class], $this->context['objectService']);
		$this->assertSame($this->deps[PermissionHandlerInterface::class], $this->context['permissionHandler']);
		$this->assertSame($this->deps[PropertyRbacHandler::class], $this->context['propertyRbac']);
		$this->assertSame($this->deps[AuditTrailMapperInterface::class], $this->context['auditTrailMapper']);
		$this->assertSame($this->deps[RegisterMapperInterface::class], $this->context['registerMapper']);
		$this->assertSame($this->deps[SchemaMapperInterface::class], $this->context['schemaMapper']);
		$this->assertSame($this->deps[SchemaGenerator::class], $this->context['schemaGenerator']);
		$this->assertSame('Named', $this->context['operationName']);
		$this->assertSame($this->deps[IRequest::class], $this->context['request']);
		$this->assertSame([], $this->context['errors']);
	}//end testResolversReceiveTheContext()

	public function testSchemaIsWiredToAFreshResolver(): void {
		$resolver = $this->deps[GraphQLResolver::class];
		$resolver->expects($this->once())->method('reset');
		$this->deps[SchemaGenerator::class]->expects($this->once())->method('setResolver')->with($resolver);

		$this->execute('{ ctx }');
	}//end testSchemaIsWiredToAFreshResolver()

	public function testIntrospectionDisabledIsRefused(): void {
		$this->config['graphql_introspection'] = 'disabled';

		$this->assertSame(
			['errors' => [['message' => 'Introspection is disabled', 'code' => 'INTROSPECTION_DISABLED']]],
			$this->execute('{ __schema { queryType { name } } }')
		);
		$this->assertSame(['data' => ['ctx' => 'ok']], array_intersect_key($this->execute('{ ctx }'), ['data' => true]));
	}//end testIntrospectionDisabledIsRefused()

	public function testIntrospectionForAuthenticatedNeedsAUser(): void {
		$this->config['graphql_introspection'] = 'authenticated';

		$this->assertSame(
			['errors' => [['message' => 'Introspection requires authentication', 'code' => 'INTROSPECTION_DISABLED']]],
			$this->execute('{ __type(name: "Query") { name } }')
		);

		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('alice');
		$this->assertSame(['__type' => ['name' => 'Query']], $this->execute('{ __type(name: "Query") { name } }')['data']);
	}//end testIntrospectionForAuthenticatedNeedsAUser()

	public function testIntrospectionEnabledByDefault(): void {
		$this->assertSame(['__type' => ['name' => 'Query']], $this->execute('{ __type(name: "Query") { name } }')['data']);
	}//end testIntrospectionEnabledByDefault()

	public function testSyntaxErrorIsFormatted(): void {
		$result = $this->execute('{ ctx');

		$this->assertSame(['errors'], array_keys($result));
		$this->assertCount(1, $result['errors']);
		$this->assertStringStartsWith('Syntax Error', $result['errors'][0]['message']);
	}//end testSyntaxErrorIsFormatted()

	public function testUnexpectedFailureIsLoggedAndHidden(): void {
		$this->generateThrows = true;
		$this->remoteAddress  = '10.0.0.1';

		$this->assertSame(
			['errors' => [['message' => 'Internal server error', 'extensions' => ['code' => 'INTERNAL_ERROR']]]],
			$this->execute('{ ctx }')
		);
		$this->assertSame(['GraphQL execution error: generator broke'], $this->errors);
	}//end testUnexpectedFailureIsLoggedAndHidden()
}//end class
