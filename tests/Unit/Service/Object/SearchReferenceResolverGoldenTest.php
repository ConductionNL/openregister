<?php

/**
 * OpenRegister SearchReferenceResolverGoldenTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
use OCA\OpenRegister\Service\Object\SearchReferenceResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\MagicMapperInterface;

/**
 * The answers, lookups and log lines of the search reference resolver.
 *
 * @covers \OCA\OpenRegister\Service\Object\SearchReferenceResolver
 * @uses   \OCA\OpenRegister\Service\Object\SearchReferenceKey
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Exception\RegisterNotFoundException
 * @uses   \OCA\OpenRegister\Exception\SchemaNotFoundException
 * @uses \OCA\OpenRegister\Service\Object\SearchReferenceText
 * @uses \OCA\OpenRegister\Db\SchemaCatalog
 */
final class SearchReferenceResolverGoldenTest extends TestCase {
	/**
	 * Lookups asked of the mappers, as `kind:reference:rbac:multitenancy`.
	 *
	 * @var array<int, string>
	 */
	private array $lookups = [];

	/**
	 * Warnings logged, as `reference=>resolved`.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * Queries in, queries out, with the lookups and warnings they cost.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<int, string>, 3: array<int, string>}>
	 */
	public static function queries(): array {
		return [
			'nothing to read'        => [['a' => 1], ['a' => 1], [], []],
			'plain names stay'       => [['register' => 'x', 'schema' => 'y'], ['register' => 'x', 'schema' => 'y'], [], []],
			'numeric ids'            => [['@self' => ['register' => 7, 'schema' => '8'], '_register' => ' 9 '], ['@self' => ['register' => 7, 'schema' => 8], '_register' => 9], [], []],
			'top level spellings'    => [['_schema' => 'hond', 'schema' => 2], ['_schema' => 20, 'schema' => 2], ['schema:hond:0:0'], ['hond=>20']],
			'a slug in @self'        => [['@self' => ['register' => 'dieren']], ['@self' => ['register' => 10]], ['register:dieren:0:0'], ['dieren=>10']],
			'register before schema' => [['_schema' => 'hond', '_register' => 'dieren'], ['_schema' => 20, '_register' => 10], ['register:dieren:0:0', 'schema:hond:0:0'], ['dieren=>10', 'hond=>20']],
			'blank drops the key'    => [['_register' => '  ', '@self' => ['schema' => '', 'owner' => 'x']], ['@self' => ['owner' => 'x']], [], []],
			'null stays'             => [['_register' => null], ['_register' => null], [], []],
			'a float stays'          => [['_register' => 1.5], ['_register' => 1.5], [], []],
			'@self not an array'     => [['@self' => 'x'], ['@self' => 'x'], [], []],
			'a list resolves'        => [['_schemas' => [1, '2', 'hond', '', ['x'], null, 1.5]], ['_schemas' => [1, 2, 20]], ['schema:hond:0:0'], ['hond=>20']],
			'a list in @self'        => [['@self' => ['schemas' => ['hond']]], ['@self' => ['schemas' => [20]]], ['schema:hond:0:0'], ['hond=>20']],
			'an empty list'          => [['_schemas' => []], ['_schemas' => []], [], []],
			'a list key as a string' => [['_schemas' => 'hond', '@self' => ['schemas' => 7]], ['_schemas' => 'hond', '@self' => ['schemas' => 7]], [], []],
			'a list at a single key' => [['_register' => ['dieren', 3]], ['_register' => [10, 3]], ['register:dieren:0:0'], ['dieren=>10']],
			'zero asks the mapper'   => [['_register' => 0], ['_register' => 10], ['register:0:0:0'], ['0=>10']],
			'zero as text'           => [['_schema' => '0'], ['_schema' => 20], ['schema:0:0:0'], ['0=>20']],
			'a negative id'          => [['_register' => -3], ['_register' => 10], ['register:-3:0:0'], ['-3=>10']],
			'a padded slug'          => [['_register' => ' dieren '], ['_register' => 10], ['register: dieren :0:0'], [' dieren =>10']],
		];
	}//end queries()

	/**
	 * A query comes back with its references resolved.
	 *
	 * @param array<string, mixed> $query    The query.
	 * @param array<string, mixed> $expected The answer.
	 * @param array<int, string>   $lookups  The lookups.
	 * @param array<int, string>   $warnings The warnings.
	 *
	 * @return void
	 */
	#[DataProvider('queries')]
	public function testAQueryResolves(array $query, array $expected, array $lookups, array $warnings): void {
		$this->assertSame($expected, $this->resolver()->normaliseQuery($query));
		$this->assertSame($lookups, $this->lookups);
		$this->assertSame($warnings, $this->warnings);
	}//end testAQueryResolves()

	/**
	 * The two single-reference entries answer alike.
	 *
	 * @return void
	 */
	public function testTheSingleEntriesAnswer(): void {
		$resolver = $this->resolver();

		$this->assertNull($resolver->register(null));
		$this->assertNull($resolver->schema(' '));
		$this->assertSame(7, $resolver->register('7'));
		$this->assertSame(20, $resolver->schema('hond'));
		$this->assertSame([10, 7], $resolver->register(['dieren', 7, '']));
		$this->assertSame([], $resolver->schema([]));
	}//end testTheSingleEntriesAnswer()

	/**
	 * References that name nothing, and what they raise.
	 *
	 * @return array<string, array{0: string, 1: string, 2: class-string<Throwable>, 3: string, 4: int, 5: class-string<Throwable>|null}>
	 */
	public static function refusals(): array {
		return [
			'no register'        => ['register', 'nope', RegisterNotFoundException::class, 'nope', 404, DoesNotExistException::class],
			'two registers'      => ['register', 'dup', RegisterNotFoundException::class, 'dup', 404, MultipleObjectsReturnedException::class],
			'no schema'          => ['schema', 'nope', SchemaNotFoundException::class, 'nope', 404, DoesNotExistException::class],
			'two schemas'        => ['schema', 'dup', SchemaNotFoundException::class, 'dup', 404, MultipleObjectsReturnedException::class],
			'a database failure' => ['register', 'down', RuntimeException::class, 'down', 0, null],
			'a schema failure'   => ['schema', 'down', RuntimeException::class, 'down', 0, null],
		];
	}//end refusals()

	/**
	 * A reference that names nothing is refused, and a failure travels unchanged.
	 *
	 * @param string                       $entry    `register` or `schema`.
	 * @param string                       $ref      The reference.
	 * @param class-string<Throwable>      $class    What is raised.
	 * @param string                       $fragment Text the message holds.
	 * @param int                          $code     The code.
	 * @param class-string<Throwable>|null $previous What it wraps.
	 *
	 * @return void
	 */
	#[DataProvider('refusals')]
	public function testAReferenceIsRefused(string $entry, string $ref, string $class, string $fragment, int $code, ?string $previous): void {
		try {
			$this->resolver()->$entry($ref);
			$this->fail('expected a refusal');
		} catch (RegisterNotFoundException | SchemaNotFoundException | RuntimeException $e) {
			$this->assertSame($class, $e::class);
			$this->assertStringContainsString($fragment, $e->getMessage());
			$this->assertSame($code, $e->getCode());
			$this->assertSame($previous, ($e->getPrevious() === null ? null : $e->getPrevious()::class));
			$this->assertSame([], $this->warnings);
		}
	}//end testAReferenceIsRefused()

	/**
	 * A refused register says what to do instead.
	 *
	 * @return void
	 */
	public function testARefusedRegisterCarriesItsRemedy(): void {
		try {
			$this->resolver()->normaliseQuery(['_register' => 'nope']);
			$this->fail('expected a refusal');
		} catch (RegisterNotFoundException $e) {
			$this->assertStringContainsString(
				'A search was scoped to this register. Pass a register id, uuid or slug that exists; an unknown reference is refused rather than answered with an empty page.',
				var_export($e, true)
			);
		}
	}//end testARefusedRegisterCarriesItsRemedy()

	/**
	 * The resolver over mappers that record what they are asked.
	 *
	 * @return SearchReferenceResolver The resolver.
	 */
	private function resolver(): SearchReferenceResolver {
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(
			function (string|int $id, bool $_rbac = true, bool $_multitenancy = true): Register {
				$entity = new Register();
				$entity->setId($this->answer(kind: 'register', lookup: [$id, $_rbac, $_multitenancy]));
				return $entity;
			}
		);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			function (string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): Schema {
				$entity = new Schema();
				$entity->setId($this->answer(kind: 'schema', lookup: [$id, $_rbac, $_multitenancy]));
				return $entity;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->assertStringContainsString('resolved a non-numeric', $message);
				$this->warnings[] = $context['reference'] . '=>' . $context['resolved'];
			}
		);

		return new SearchReferenceResolver(new SchemaCatalog(registers: $registers, schemas: $schemas, tables: $this->createMock(MagicMapperInterface::class)), $logger);
	}//end resolver()

	/**
	 * Record one lookup and answer it.
	 *
	 * @param string            $kind   `register` or `schema`.
	 * @param array<int, mixed> $lookup The reference, the rbac flag and the multitenancy flag.
	 *
	 * @return int The id: 10 for a register, 20 for a schema.
	 */
	private function answer(string $kind, array $lookup): int {
		$this->lookups[] = sprintf('%s:%s:%d:%d', $kind, ...$lookup);

		return match ($lookup[0]) {
			'nope' => throw new DoesNotExistException('none'),
			'dup' => throw new MultipleObjectsReturnedException('two'),
			'down' => throw new RuntimeException('down'),
			default => ($kind === 'register' ? 10 : 20),
		};
	}//end answer()
}//end class
