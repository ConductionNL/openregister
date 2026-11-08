<?php

/**
 * Characterisation tests for MatchResolver: which filters a row's lookup
 * runs with, when it abandons the lookup, how a failed lookup is reported,
 * and which uuids it reads off what the lookup returned.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Import;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Import\MatchLookupFailedException;
use OCA\OpenRegister\Service\Import\MatchResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;

final class MatchResolverTest extends TestCase {

	/**
	 * The object read path.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface $objects;

	/**
	 * The resolver under test.
	 *
	 * @var MatchResolver
	 */
	private MatchResolver $resolver;

	/**
	 * The target register.
	 *
	 * @var Register
	 */
	private Register $register;

	/**
	 * The target schema.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->resolver = new MatchResolver($this->objects, new NullLogger());
		$this->register = new Register();
		$this->register->setId(5);
		$this->schema = new Schema();
		$this->schema->setId(7);
	}

	/**
	 * Resolve against the fixed register and schema.
	 *
	 * @param array<string, mixed> $object The mapped object.
	 * @param array<int, string> $key The match key.
	 *
	 * @return array<int, string> The uuids.
	 */
	private function resolve(array $object, array $key): array {
		return $this->resolver->resolve($object, $key, $this->register, $this->schema);
	}

	public function testAnEmptyKeyMatchesNothingWithoutALookup(): void {
		$this->objects->expects($this->never())->method('findAll');

		$this->assertSame([], $this->resolve(['bsn' => '1'], []));
	}

	public function testABlankKeyValueAbandonsTheLookup(): void {
		$this->objects->expects($this->never())->method('findAll');

		$this->assertSame([], $this->resolve(['bsn' => '1', 'naam' => ''], ['bsn', 'naam']));
		$this->assertSame([], $this->resolve(['bsn' => '1'], ['bsn', 'naam']));
		$this->assertSame([], $this->resolve(['bsn' => ['nested']], ['bsn']));
	}

	public function testTheFiltersCarryTheTargetAndEveryKeyValueStringified(): void {
		$this->objects->expects($this->once())->method('findAll')->with(
			[
				'filters' => ['register' => 5, 'schema' => 7, 'bsn' => '111', 'actief' => '1', 'naam' => 'Jansen'],
				'limit' => 3,
			]
		)->willReturn([]);

		$this->assertSame([], $this->resolve(['bsn' => 111, 'actief' => true, 'naam' => 'Jansen'], ['bsn', 'actief', 'naam']));
	}

	public function testTheIdentifierIsReadFromTheSelfBoxFirstAndFilteredAsId(): void {
		$seen = [];
		$this->objects->method('findAll')->willReturnCallback(
			static function (array $config) use (&$seen): array {
				$seen[] = $config['filters'];

				return [];
			}
		);

		$this->resolve(['id' => 'top', '@self' => ['id' => 'boxed']], ['id']);
		$this->resolve(['id' => 'top'], ['id']);
		$this->resolve(['@self' => ['id' => 'boxed', 'owner' => 'bob']], ['@self.id', '@self.owner']);

		$this->assertSame('boxed', $seen[0]['id']);
		$this->assertSame('top', $seen[1]['id']);
		$this->assertSame('boxed', $seen[2]['id']);
		$this->assertSame('bob', $seen[2]['@self.owner']);
		$this->assertArrayNotHasKey('@self.id', $seen[2]);
	}

	public function testAFailedLookupIsReportedNotTreatedAsNoMatch(): void {
		$cause = new RuntimeException('db down');
		$this->objects->method('findAll')->willThrowException($cause);

		try {
			$this->resolve(['bsn' => '1'], ['bsn']);
			$this->fail('A failed lookup must throw.');
		} catch (MatchLookupFailedException $exception) {
			$this->assertSame('The match key could not be resolved: db down', $exception->getMessage());
			$this->assertSame(0, $exception->getCode());
			$this->assertSame($cause, $exception->getPrevious());
		}
	}

	public function testUuidsAreReadOffEveryShapeTheLookupReturnsAndDeduplicated(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('from-entity');

		$this->objects->method('findAll')->willReturn(
			[
				['@self' => ['id' => 'from-self'], 'uuid' => 'ignored'],
				['uuid' => 'from-uuid', 'id' => 'ignored'],
				['id' => 42],
				['@self' => ['id' => 'from-self']],
				['@self' => ['id' => ['not scalar']]],
				['nothing' => true],
				$entity,
				new stdClass(),
				'a string',
			]
		);

		$this->assertSame(['from-self', 'from-uuid', '42', 'from-entity'], $this->resolve(['bsn' => '1'], ['bsn']));
	}

	public function testAnEntityWithoutAUuidIsSkipped(): void {
		$this->objects->method('findAll')->willReturn([new ObjectEntity()]);

		$this->assertSame([], $this->resolve(['bsn' => '1'], ['bsn']));
	}

	public function testTheKeyIsNormalisedFromAStringOrAList(): void {
		$this->assertSame([], MatchResolver::normaliseKey(null));
		$this->assertSame(['bsn', 'naam'], MatchResolver::normaliseKey(' bsn , naam,,bsn '));
		$this->assertSame(['bsn', '1'], MatchResolver::normaliseKey(['bsn', ' ', 1]));
	}
}
