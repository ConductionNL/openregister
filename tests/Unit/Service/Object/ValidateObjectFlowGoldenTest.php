<?php

/**
 * ValidateObject validates, formats and resolves as recorded.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use Opis\Uri\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Walks real schemas and data through validateObject() on the real Opis validator.
 *
 * The recording was made on the code before these units were split.
 *
 * @covers \OCA\OpenRegister\Service\Object\ValidateObject
 */
class ValidateObjectFlowGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/validate-object-flow-golden.json';

	private function handler(): ValidateObject {
		$urls = $this->createStub(IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn('http://nc.test');
		$urls->method('getAbsoluteURL')->willReturnArgument(0);
		$urls->method('linkToRoute')->willReturn('/route');

		$schemas = $this->createStub(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			function ($id): Schema {
				return $this->schema(['title' => ['type' => 'string']], (int) $id);
			}
		);

		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => ($uid === 'alice' ? $this->createStub(IUser::class) : null)
		);

		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueBool')->willReturn(false);

		return new ValidateObject(
			$config,
			$this->createStub(MagicMapperInterface::class),
			$schemas,
			$urls,
			$this->createStub(LoggerInterface::class),
			$users
		);
	}//end handler()

	private function schema(array $properties, int $id=7, array $required=[]): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('thing');
		$schema->setTitle('Thing');
		$schema->setVersion('1.0.0');
		$schema->setProperties($properties);
		$schema->setRequired($required);

		return $schema;
	}//end schema()

	/**
	 * The property declarations and values each case writes.
	 *
	 * @return array<string, array{0: array, 1: array<int|string, mixed>}>
	 */
	private static function fields(): array {
		$text = ['type' => 'string'];

		return [
			'color'       => [['type' => 'color'], ['#ff0000', 'nope', 12, '', null]],
			'color rgb'   => [['type' => 'color', 'format' => 'rgb'], ['rgb(1,2,3)', '#ff0000']],
			'color rgba'  => [['type' => 'color', 'format' => 'rgba'], ['rgba(1,2,3,0.5)', '#ff0000']],
			'color oklch' => [['type' => 'color', 'format' => 'oklch'], ['oklch(0.5 0.2 100)', '#ff0000']],
			'recurrence'  => [['type' => 'recurrence'], ['FREQ=DAILY', 'nope', 12, null]],
			'bsn'         => [['type' => 'string', 'format' => 'bsn'], ['123456782', '123456789']],
			'semver'      => [['type' => 'string', 'format' => 'semver'], ['1.2.3', 'x']],
			'cron'        => [['type' => 'string', 'format' => 'cron'], ['* * * * *', 'x']],
			'user'        => [['type' => 'string', 'format' => 'user'], ['alice', 'ghost']],
			'date-time'   => [['type' => 'string', 'format' => 'date-time'], ['2026-01-02T10:00', '2026-01-02T10:00:00Z', 'x']],
			'translated'  => [['type' => 'string', 'translatable' => true], ['x', ['nl' => 'x'], ['nl' => 1], 5]],
			'nested null' => [
				['type' => 'object', 'title' => 'Nested', 'properties' => ['a' => ['type' => 'string', 'nullable' => true], 'b' => ['type' => 'string']]],
				[['a' => null], ['b' => null], ['a' => 'x']],
			],
			'nested enum' => [
				['type' => 'object', 'title' => 'Nested', 'properties' => ['a' => ['type' => 'string', 'enum' => ['x'], 'nullable' => true]]],
				[['a' => null], ['a' => 'y']],
			],
			'nested list' => [
				['type' => 'array', 'items' => ['type' => 'object', 'title' => 'Nested', 'properties' => ['a' => ['type' => ['integer'], 'nullable' => true]]]],
				[[['a' => null]], [['a' => 'x']]],
			],
			'union'       => [
				['type' => 'object', 'title' => 'Nested', 'properties' => ['a' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']], 'nullable' => true]]],
				[['a' => null], ['a' => 1.5]],
			],
			'null union'  => [
				['type' => 'object', 'title' => 'Nested', 'properties' => ['a' => ['anyOf' => [['type' => 'string'], ['type' => 'null']], 'nullable' => true]]],
				[['a' => null]],
			],
			'null oneOf'  => [
				['type' => 'object', 'title' => 'Nested', 'properties' => ['a' => ['oneOf' => [['type' => 'string'], ['type' => 'null']], 'nullable' => true]]],
				[['a' => null]],
			],
			'null type'   => [
				['type' => 'object', 'title' => 'Nested', 'properties' => ['a' => ['type' => 'null', 'nullable' => true]]],
				[['a' => null], ['a' => 'x']],
			],
			'enum'        => [['type' => 'string', 'enum' => ['a', 'b']], ['a', 'c', null, '']],
			'enum empty'  => [['type' => 'string', 'enum' => ['', 'b']], ['', null]],
			'list'        => [['type' => 'array', 'minItems' => 1], [[], ['a']]],
			'max list'    => [['type' => 'array', 'maxItems' => 2], [[], ['a', 'b', 'c']]],
			'unique list' => [['type' => 'array', 'uniqueItems' => true], [[], ['a', 'a']]],
			'plain list'  => [['type' => 'array'], [[]]],
			'text'        => [$text, ['x', '', null, 5, ['a']]],
			'computed'    => [['type' => 'string', 'computed' => true], [5, 'x']],
			'calculation' => [['type' => 'string', 'calculation' => 'a+b'], [5]],
		];
	}//end fields()

	/**
	 * One validation's outcome.
	 *
	 * @param ValidateObject $handler The handler.
	 * @param array          $args    The validateObject() arguments.
	 *
	 * @return array The outcome.
	 */
	private function outcome(ValidateObject $handler, array $args): array {
		try {
			$result = $handler->validateObject(...$args);

			return ['valid' => $result->isValid(), 'message' => $handler->generateErrorMessage(result: $result)];
		} catch (CustomValidationException $e) {
			return ['custom' => $e->getMessage(), 'errors' => $e->getErrors()];
		} catch (Throwable $e) {
			return ['threw' => get_class($e)];
		}
	}//end outcome()

	/**
	 * Every declaration and value: as stored, as required, as a given schema, and left out.
	 *
	 * @return array<string, array>
	 */
	private function recordFields(): array {
		$cases = [];
		foreach (self::fields() as $name => [$declaration, $values]) {
			foreach ($values as $index => $value) {
				$object = ['field' => $value, 'extend' => ['a'], 'filters' => ['b']];
				$stored = $this->schema(['field' => $declaration]);
				$must   = $this->schema(['field' => $declaration], 8, ['field']);
				$given  = json_decode(json_encode(['type' => 'object', 'properties' => ['field' => $declaration]]));

				$cases["$name $index stored"]   = $this->outcome($this->handler(), ['object' => $object, 'schema' => $stored]);
				$cases["$name $index required"] = $this->outcome($this->handler(), ['object' => $object, 'schema' => $must]);
				$cases["$name $index given"]    = $this->outcome($this->handler(), ['object' => $object, 'schemaObject' => $given]);
				$cases["$name $index omitted"]  = $this->outcome($this->handler(), ['object' => ['other' => 1], 'schema' => $must]);
			}
		}

		return $cases;
	}//end recordFields()

	/**
	 * The paths into validateObject(): by id, no schema, no properties, repeated, excused.
	 *
	 * @return array<string, array>
	 */
	private function recordPaths(): array {
		$handler = $this->handler();
		$stored  = $this->schema(['title' => ['type' => 'string']], 9, ['title']);
		$empty   = (object) ['type' => 'object'];

		return [
			'by id'            => $this->outcome($handler, ['object' => ['title' => 5], 'schema' => 3]),
			'by slug'          => $this->outcome($handler, ['object' => ['title' => 'x'], 'schema' => 'thing']),
			'no schema'        => $this->outcome($handler, ['object' => ['title' => 5]]),
			'no properties'    => $this->outcome($handler, ['object' => ['title' => 5], 'schemaObject' => $empty]),
			'first of two'     => $this->outcome($handler, ['object' => ['title' => 5], 'schema' => $stored]),
			'second of two'    => $this->outcome($handler, ['object' => ['title' => 'x'], 'schema' => $stored]),
			'excused'          => $this->outcome($handler, ['object' => ['other' => 1], 'schema' => $stored, 'notSupplied' => ['title' => 'unknown']]),
			'not excused'      => $this->outcome($handler, ['object' => ['other' => 1], 'schema' => $stored]),
			'given and stored' => $this->outcome(
				$handler,
				['object' => ['title' => 5], 'schema' => $stored, 'schemaObject' => (object) ['properties' => (object) ['title' => (object) ['type' => 'integer']]]]
			),
			'planned user'     => (function () use ($handler): array {
				$schema = $this->schema(['who' => ['type' => 'string', 'format' => 'user']], 10);
				$result = $handler->validateObjectWithPlannedUsers(object: ['who' => 'ghost'], schema: $schema, plannedUsers: ['ghost', 5]);

				return [$result->isValid(), $this->outcome($handler, ['object' => ['who' => 'ghost'], 'schema' => $schema])];
			})(),
		];
	}//end recordPaths()

	/**
	 * What resolveSchema() answers per URI.
	 *
	 * @return array<string, string>
	 */
	private function recordResolving(): array {
		$handler = $this->handler();
		$cases   = [];
		foreach (['http://nc.test/api/schemas/4', 'http://nc.test/api/files/schema', 'http://nc.test/other', 'http://other.test/api/schemas/4'] as $uri) {
			$cases[$uri] = $handler->resolveSchema(Uri::create($uri));
		}

		return $cases;
	}//end recordResolving()

	/**
	 * The responses to validation exceptions.
	 *
	 * @return array<string, array>
	 */
	private function recordResponses(): array {
		$handler = $this->handler();
		$failed  = $handler->validateObject(object: ['title' => 5], schema: $this->schema(['title' => ['type' => 'string']]));
		$cases   = [];
		foreach ([
			'custom'         => new CustomValidationException(message: 'bad', errors: ['a' => 'b', 'c' => 'd']),
			'message alone'  => new ValidationException(message: 'bad'),
			'with an error'  => new ValidationException(message: 'bad', errors: $failed->error()),
		] as $name => $exception) {
			$response = $handler->handleValidationException($exception);
			$cases[$name] = [$response->getStatus(), $response->getData()];
		}

		return $cases;
	}//end recordResponses()

	private function record(): array {
		return ['fields' => $this->recordFields(), 'paths' => $this->recordPaths(), 'resolving' => $this->recordResolving(), 'responses' => $this->recordResponses()];
	}//end record()

	public function testEveryFlowRunsAsRecorded(): void {
		$recording = json_decode(json_encode($this->record(), JSON_THROW_ON_ERROR), true);
		if (getenv('RECORD_GOLDEN') === 'validate-flow') {
			file_put_contents(self::GOLDEN, json_encode($recording, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR), $recording);
	}//end testEveryFlowRunsAsRecorded()
}//end class
