<?php

/**
 * Golden test: the full answers of the cross-register existence probe
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\CrossRegisterExistenceService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Pins every answer shape (incomplete probe, refused search, no match, a
 * match in a bare list, a paged envelope or as serialisable objects), the
 * field narrowing (declared, write-only, behind authorization, blank,
 * unreadable schema), and exactly what the search is asked: the filters,
 * the register/schema pin, the limit and RBAC and multitenancy ON.
 */
class CrossRegisterExistenceAnswerGoldenTest extends TestCase {

	/** @var array<int, array<string, mixed>> The searches made. */
	private array $searches = [];

	/** @var array<int, string> The schema lookups made. */
	private array $lookups = [];

	/**
	 * The service over a search answering $result (or throwing it) and a schema store.
	 *
	 * @param mixed $result The search answer, or a Throwable to throw.
	 * @param bool  $schemaReadable Whether the schema loads.
	 *
	 * @return CrossRegisterExistenceService
	 */
	private function service(mixed $result, bool $schemaReadable = true): CrossRegisterExistenceService {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query = [], bool $_rbac = true, bool $_multitenancy = true) use ($result) {
				$this->searches[] = ['query' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				if ($result instanceof \Throwable) {
					throw $result;
				}

				return $result;
			}
		);

		$schema = new Schema();
		$schema->setProperties(
			[
				'caseNumber' => ['type' => 'string'],
				'status' => ['type' => 'string'],
				'secret' => ['type' => 'string', 'writeOnly' => true],
				'note' => ['type' => 'string', 'authorization' => ['read' => ['x']]],
			]
		);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			function (mixed $id) use ($schema, $schemaReadable): Schema {
				$this->lookups[] = (string)$id;
				if ($schemaReadable === false) {
					throw new RuntimeException('unreadable');
				}

				return $schema;
			}
		);

		return new CrossRegisterExistenceService($objects, $schemas, new NullLogger());
	}//end service()

	/**
	 * An answer in full.
	 *
	 * @param array<string, mixed> $fields The fields over the refused default.
	 *
	 * @return array<string, mixed>
	 */
	private function answer(array $fields): array {
		return array_replace(
			['register' => 'r', 'schema' => 's', 'exists' => false, 'matches' => 0, 'revealed' => [], 'refusedFields' => [], 'refused' => ''],
			$fields
		);
	}//end answer()

	/**
	 * Incomplete probes are refused without a search or schema lookup.
	 *
	 * @return void
	 */
	public function testIncompleteProbes(): void {
		$why = 'A probe names a register, a schema and at least one filter.';
		$answers = $this->service([])->probe(
			[
				['register' => ' ', 'schema' => 's', 'filters' => ['a' => 1]],
				['register' => 'r', 'filters' => ['a' => 1]],
				['register' => 'r', 'schema' => 's', 'filters' => 'a=1'],
				['register' => 'r', 'schema' => 's', 'filters' => []],
				['register' => ' r ', 'schema' => ' s ', 'reveal' => ['status']],
				'not a probe',
			]
		);

		$this->assertSame(
			[
				'probes' => [
					$this->answer(['register' => '', 'refused' => $why]),
					$this->answer(['schema' => '', 'refused' => $why]),
					$this->answer(['refused' => $why]),
					$this->answer(['refused' => $why]),
					$this->answer(['refused' => $why]),
				],
			],
			$answers
		);
		$this->assertSame([], $this->searches);
		$this->assertSame([], $this->lookups);
	}//end testIncompleteProbes()

	/**
	 * Too many probes: nothing is queried.
	 *
	 * @return void
	 */
	public function testTooManyProbes(): void {
		$this->assertSame(
			['error' => 'too-many-probes', 'message' => 'A call carries at most 10 probes; this one carried 11. Nothing was queried.'],
			$this->service([])->probe(array_fill(0, 11, ['register' => 'r', 'schema' => 's', 'filters' => ['a' => 1]]))
		);
		$this->assertSame([], $this->searches);
	}//end testTooManyProbes()

	/**
	 * A refused search, a miss, and matches in every shape.
	 *
	 * @return void
	 */
	public function testSearchOutcomes(): void {
		$probe = ['register' => 'r', 'schema' => 's', 'filters' => ['bsn' => '1', '_limit' => 5], 'reveal' => ['status', 'secret', 'note', 'unknown', '', ' caseNumber ']];
		$refusedFields = ['secret', 'note', 'unknown'];

		$this->assertSame(
			['probes' => [$this->answer(['refusedFields' => $refusedFields, 'refused' => 'This probe was refused. It is not an answer about whether a row exists.'])]],
			$this->service(new RuntimeException('forbidden'))->probe([$probe])
		);
		$this->assertSame(
			[['query' => ['bsn' => '1', '_limit' => 100, '@self' => ['register' => 'r', 'schema' => 's']], 'rbac' => true, 'multitenancy' => true]],
			$this->searches
		);
		$this->assertSame(['s'], $this->lookups);

		$this->assertSame(
			['probes' => [$this->answer(['refusedFields' => $refusedFields])]],
			$this->service([])->probe([$probe])
		);
		$this->assertSame(
			['probes' => [$this->answer(['refusedFields' => $refusedFields])]],
			$this->service(5)->probe([$probe])
		);

		$rows = [['status' => 'open', 'secret' => 'x', 'note' => 'y'], ['status' => 'closed', 'caseNumber' => 'C-2']];
		$found = $this->answer(['exists' => true, 'matches' => 2, 'revealed' => ['status' => 'open'], 'refusedFields' => $refusedFields]);
		$this->assertSame(['probes' => [$found]], $this->service($rows)->probe([$probe]), 'From the first match only; absent stays absent.');
		$this->assertSame(['probes' => [$found]], $this->service(['results' => $rows, 'total' => 2])->probe([$probe]));
		$this->assertSame(
			['probes' => [$this->answer(['exists' => false, 'matches' => 0, 'refusedFields' => $refusedFields])]],
			$this->service(['results' => 'x'])->probe([$probe]),
			'An envelope whose results is not a list reads as the bare array: its one value is not a row.'
		);

		$object = new class implements \JsonSerializable {
			/**
			 * The row.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['caseNumber' => 'C-1', 'status' => 'open'];
			}
		};
		$this->assertSame(
			['probes' => [$this->answer(['exists' => true, 'matches' => 1, 'revealed' => ['status' => 'open', 'caseNumber' => 'C-1'], 'refusedFields' => $refusedFields])]],
			$this->service([$object, 'scalar', 7])->probe([$probe])
		);
	}//end testSearchOutcomes()

	/**
	 * Reveal narrowing: nothing asked, a non-list ask, an unreadable schema.
	 *
	 * @return void
	 */
	public function testRevealNarrowing(): void {
		$probe = ['register' => 'r', 'schema' => 's', 'filters' => ['a' => 1]];
		$rows = [['status' => 'open']];

		$this->assertSame(['probes' => [$this->answer(['exists' => true, 'matches' => 1])]], $this->service($rows)->probe([$probe]));
		$this->assertSame(['probes' => [$this->answer(['exists' => true, 'matches' => 1])]], $this->service($rows)->probe([$probe + ['reveal' => 'status']]));
		$this->assertSame([], $this->lookups, 'Nothing asked: the schema is not read.');

		$this->assertSame(
			['probes' => [$this->answer(['exists' => true, 'matches' => 1, 'refusedFields' => ['status', '', '7']])]],
			$this->service($rows, false)->probe([$probe + ['reveal' => ['status', '', 7]]]),
			'An unreadable schema refuses every asked field, untrimmed.'
		);
	}//end testRevealNarrowing()
}//end class
