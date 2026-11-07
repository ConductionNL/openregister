<?php

/**
 * The errors GraphQLResolver raises for a missing record and a failed validation.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\GraphQL
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/graphql-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\GraphQL;

use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use OCA\OpenRegister\Db\MagicMapper\EncryptedOrderRefusal;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\EncryptedFieldFilterException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolver;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Not-found and validation errors of the single, update, delete and create resolvers.
 */
class GraphQLResolverErrorsTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * The record lookup double.
	 *
	 * @var GetObject&MockObject
	 */
	private $getObject;

	/**
	 * Build the resolver over permissive doubles.
	 *
	 * @return GraphQLResolver The resolver.
	 */
	private function resolver(): GraphQLResolver {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->getObject     = $this->createMock(GetObject::class);

		$register = new Register();
		$register->setId(1);
		$register->setSchemas([1]);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn([$register]);

		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->method('getUnauthorizedProperties')->willReturn([]);

		return new GraphQLResolver(
			$this->getObject,
			$this->objectService,
			$this->createMock(PermissionHandler::class),
			$rbac,
			$this->createMock(RelationHandler::class),
			$registerMapper,
			$this->createMock(LoggerInterface::class),
			$this->createMock(TranslationHandler::class),
			$this->createMock(AggregationRunner::class),
			new TimeseriesRequestValidator()
		);
	}//end resolver()

	/**
	 * A schema with a slug and, optionally, a title.
	 *
	 * @param string|null $title The title.
	 *
	 * @return Schema The schema.
	 */
	private function schema(?string $title=null): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('zaak');
		$schema->setTitle($title);

		return $schema;
	}//end schema()

	/**
	 * Run a call and return the GraphQL error it raised.
	 *
	 * @param callable $call The call.
	 *
	 * @return Error The error.
	 */
	private function errorOf(callable $call): Error {
		try {
			$call();
		} catch (Error $e) {
			return $e;
		}

		$this->fail('no GraphQL error was raised');
	}//end errorOf()

	/**
	 * The expected not-found error parts.
	 *
	 * @param Error $error The error.
	 *
	 * @return array<string, mixed> Message, extensions and previous.
	 */
	private function parts(Error $error): array {
		return [$error->getMessage(), $error->getExtensions(), $error->getPrevious()];
	}//end parts()

	/**
	 * A list query sorted on an encrypted property, as the resolver sends it.
	 *
	 * @return Error The error.
	 */
	private function encryptedSortError(): Error {
		$resolver = $this->resolver();
		$schema   = $this->schema();
		$schema->setProperties(['bsn' => ['type' => 'string', 'x-openregister-encrypted' => true]]);

		$this->objectService->method('buildSearchQuery')->willReturnArgument(0);
		$this->objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use ($schema): array {
				(new EncryptedOrderRefusal(order: $query['_order']))->check(schemas: [$schema]);
				return [];
			}
		);

		return $this->errorOf(fn () => $resolver->resolveList($schema, null, ['sort' => ['field' => 'bsn', 'order' => 'desc']]));
	}//end encryptedSortError()

	/**
	 * A sort on an encrypted property reaches the caller as a bad request naming it.
	 *
	 * @return void
	 */
	public function testAListSortedOnAnEncryptedPropertyIsABadRequest(): void {
		$error = $this->encryptedSortError();

		$this->assertSame(
			[
				'Property "bsn" is encrypted at rest and cannot be sorted on.',
				['code' => 'BAD_REQUEST', 'reason' => 'encrypted-field-not-sortable', 'property' => 'bsn'],
				null,
			],
			$this->parts(error: $error)
		);
	}//end testAListSortedOnAnEncryptedPropertyIsABadRequest()

	/**
	 * The formatted error keeps the message: a previous exception would hide it.
	 *
	 * @return void
	 */
	public function testTheRefusalSurvivesFormatting(): void {
		$formatted = FormattedError::createFromException($this->encryptedSortError());

		$this->assertSame('Property "bsn" is encrypted at rest and cannot be sorted on.', $formatted['message']);
		$this->assertSame('BAD_REQUEST', $formatted['extensions']['code']);
	}//end testTheRefusalSurvivesFormatting()

	/**
	 * A filter on an encrypted property is the same bad request.
	 *
	 * @return void
	 */
	public function testAListFilteredOnAnEncryptedPropertyIsABadRequest(): void {
		$resolver = $this->resolver();
		$this->objectService->method('buildSearchQuery')->willReturnArgument(0);
		$this->objectService->method('searchObjectsPaginated')->willThrowException(new EncryptedFieldFilterException(property: 'bsn'));

		$error = $this->errorOf(fn () => $resolver->resolveList($this->schema(), null, []));

		$this->assertSame('encrypted-field-not-filterable', $error->getExtensions()['reason']);
		$this->assertSame('bsn', $error->getExtensions()['property']);
	}//end testAListFilteredOnAnEncryptedPropertyIsABadRequest()

	/**
	 * A missing single record names the schema title.
	 *
	 * @return void
	 */
	public function testSingleNotFoundNamesTheTitle(): void {
		$resolver = $this->resolver();
		$this->getObject->method('find')->willThrowException(new DoesNotExistException('gone'));
		$error = $this->errorOf(fn () => $resolver->resolveSingle($this->schema('Zaak'), null, ['id' => 'obj-1']));
		$this->assertSame(["Zaak with ID 'obj-1' not found", ['code' => 'NOT_FOUND'], null], $this->parts($error));
	}//end testSingleNotFoundNamesTheTitle()

	/**
	 * A missing record on update names the slug when the title is empty.
	 *
	 * @return void
	 */
	public function testUpdateNotFoundFallsBackToTheSlug(): void {
		$resolver = $this->resolver();
		$this->getObject->method('find')->willThrowException(new DoesNotExistException('gone'));
		$error = $this->errorOf(fn () => $resolver->resolveUpdate($this->schema(''), ['id' => 'obj-2', 'input' => []]));
		$this->assertSame(["zaak with ID 'obj-2' not found", ['code' => 'NOT_FOUND'], null], $this->parts($error));
	}//end testUpdateNotFoundFallsBackToTheSlug()

	/**
	 * A missing record on delete names the slug when there is no title.
	 *
	 * @return void
	 */
	public function testDeleteNotFoundFallsBackToTheSlug(): void {
		$resolver = $this->resolver();
		$this->objectService->method('deleteObject')->willThrowException(new DoesNotExistException('gone'));
		$error = $this->errorOf(fn () => $resolver->resolveDelete($this->schema(), ['id' => 'obj-3']));
		$this->assertSame(["zaak with ID 'obj-3' not found", ['code' => 'NOT_FOUND'], null], $this->parts($error));
	}//end testDeleteNotFoundFallsBackToTheSlug()

	/**
	 * A validation failure on create carries the message, the code and the cause.
	 *
	 * @return void
	 */
	public function testCreateValidationError(): void {
		$resolver = $this->resolver();
		$cause    = new ValidationException('title is required');
		$this->objectService->method('saveObject')->willThrowException($cause);
		$error = $this->errorOf(fn () => $resolver->resolveCreate($this->schema(), ['input' => ['a' => 1]]));
		$this->assertSame(['title is required', ['code' => 'VALIDATION_ERROR'], $cause], $this->parts($error));
	}//end testCreateValidationError()

	/**
	 * A validation failure on update carries the message, the code and the cause.
	 *
	 * @return void
	 */
	public function testUpdateValidationError(): void {
		$resolver = $this->resolver();
		$stored   = new ObjectEntity();
		$stored->setUuid('obj-4');
		$this->getObject->method('find')->willReturn($stored);
		$cause = new ValidationException('bad value');
		$this->objectService->method('saveObject')->willThrowException($cause);
		$error = $this->errorOf(fn () => $resolver->resolveUpdate($this->schema(), ['id' => 'obj-4', 'input' => ['a' => 1]]));
		$this->assertSame(['bad value', ['code' => 'VALIDATION_ERROR'], $cause], $this->parts($error));
	}//end testUpdateValidationError()
}//end class
