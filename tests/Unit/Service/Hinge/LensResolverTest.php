<?php

/**
 * Unit tests for LensResolver — a property that reads a referenced record live.
 *
 * Covers the value following the referenced record without a write, the
 * withheld marker standing where an unreadable object's value would be, the
 * difference between withheld and empty, and a schema declaring no lens being
 * left exactly as it was.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hinge
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Hinge;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Hinge\LensResolver;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LensResolverTest extends TestCase {

	private const LENS = [
		'x-openregister-lenses' => [
			'besluitDatum' => ['through' => 'besluit', 'property' => 'datum'],
		],
	];

	private function schema(int $id, ?array $configuration = null): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('bezwaar');
		$schema->setTitle('Bezwaar');
		if ($configuration !== null) {
			$schema->setConfiguration($configuration);
		}

		return $schema;
	}

	private function besluit(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('uuid-besluit');
		$object->setSchema(99);
		$object->setOwner('admin');
		$object->setObject($data);
		return $object;
	}

	private function resolver(?ObjectEntity $referenced, bool $mayRead): LensResolver {
		$magicMapper = $this->createMock(MagicMapper::class);
		if ($referenced === null) {
			$magicMapper->method('find')->willThrowException(new \RuntimeException('not found'));
		} else {
			$magicMapper->method('find')->willReturn($referenced);
		}

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($this->schema(99));

		$permissionHandler = $this->createMock(PermissionHandler::class);
		$permissionHandler->method('hasPermission')->willReturn($mayRead);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		return new LensResolver(
			magicMapper: $magicMapper,
			schemaMapper: $schemaMapper,
			permissionHandler: $permissionHandler,
			userSession: $userSession,
			logger: new NullLogger()
		);
	}

	public function testTheBesluitsDateIsReadThroughTheReferenceWithoutACopy(): void {
		$resolver = $this->resolver($this->besluit(['datum' => '2026-09-01']), true);

		$data = $resolver->apply(
			schema: $this->schema(1, self::LENS),
			data: ['besluit' => 'uuid-besluit', 'onderwerp' => 'Bezwaar 1']
		);

		$this->assertSame('2026-09-01', $data['besluitDatum']);
		// The object's own stored data is untouched: the lens added a key to the
		// render, it did not write one.
		$this->assertSame('uuid-besluit', $data['besluit']);
	}

	public function testTheLensFollowsTheReferencedRecordWhenItMoves(): void {
		$schema = $this->schema(1, self::LENS);
		$payload = ['besluit' => 'uuid-besluit'];

		$before = $this->resolver($this->besluit(['datum' => '2026-09-01']), true)->apply($schema, $payload);
		$after = $this->resolver($this->besluit(['datum' => '2026-09-08']), true)->apply($schema, $payload);

		$this->assertSame('2026-09-01', $before['besluitDatum']);
		$this->assertSame('2026-09-08', $after['besluitDatum']);
	}

	public function testWithheldIsNotTheSameAsEmpty(): void {
		$unreadable = $this->resolver($this->besluit(['datum' => '2026-09-01']), false)
			->apply($this->schema(1, self::LENS), ['besluit' => 'uuid-besluit']);

		$absent = $this->resolver($this->besluit(['datum' => '2026-09-01']), true)
			->apply($this->schema(1, self::LENS), ['besluit' => null]);

		$this->assertSame(LensResolver::WITHHELD, $unreadable['besluitDatum']);
		$this->assertTrue($unreadable['besluitDatum']['@withheld']);
		$this->assertNull($absent['besluitDatum']);
		$this->assertNotSame($unreadable['besluitDatum'], $absent['besluitDatum']);
	}

	public function testAnUnreadableReferenceDisclosesNothingButTheWithheldMarker(): void {
		$data = $this->resolver($this->besluit(['datum' => '2026-09-01', 'geheim' => 'niet tonen']), false)
			->apply($this->schema(1, self::LENS), ['besluit' => 'uuid-besluit']);

		$this->assertStringNotContainsString('niet tonen', json_encode($data));
		$this->assertStringNotContainsString('2026-09-01', json_encode($data));
	}

	public function testASchemaDeclaringNoLensIsLeftExactlyAsItWas(): void {
		$payload = ['besluit' => 'uuid-besluit', 'onderwerp' => 'Bezwaar 1'];

		$data = $this->resolver($this->besluit(['datum' => '2026-09-01']), true)
			->apply($this->schema(1), $payload);

		$this->assertSame($payload, $data);
	}

	public function testAReferenceThatLeadsNowhereRendersNothing(): void {
		$data = $this->resolver(null, true)
			->apply($this->schema(1, self::LENS), ['besluit' => 'uuid-gone']);

		$this->assertNull($data['besluitDatum']);
	}

	public function testAReferenceHeldAsAUriResolvesToItsLastSegment(): void {
		$data = $this->resolver($this->besluit(['datum' => '2026-09-01']), true)
			->apply($this->schema(1, self::LENS), ['besluit' => 'https://example.org/api/objects/1/2/uuid-besluit']);

		$this->assertSame('2026-09-01', $data['besluitDatum']);
	}

	public function testTheLensPropertyNamesAreWhatTheSavePathRefuses(): void {
		$this->assertSame(['besluitDatum'], LensResolver::lensPropertyNames($this->schema(1, self::LENS)));
		$this->assertSame([], LensResolver::lensPropertyNames($this->schema(1)));
		$this->assertSame([], LensResolver::lensPropertyNames(null));
	}

	public function testALensCannotBeWritten(): void {
		$schema = $this->schema(1, self::LENS);

		// A client sending a value for the lens is named in the refusal.
		$this->assertSame(
			['besluitDatum'],
			LensResolver::refusedLensWrites($schema, ['besluit' => 'uuid-besluit', 'besluitDatum' => '2026-01-01'])
		);

		// Sending the value the lens currently resolves to is still a write to
		// somebody else's record, so it is refused on presence, not on value.
		$this->assertSame(
			['besluitDatum'],
			LensResolver::refusedLensWrites($schema, ['besluitDatum' => null])
		);

		// A payload that leaves the lens alone writes normally.
		$this->assertSame([], LensResolver::refusedLensWrites($schema, ['besluit' => 'uuid-besluit']));

		// And a schema with no lens refuses nothing, which is the whole of the
		// backwards-compatibility promise on the write path.
		$this->assertSame([], LensResolver::refusedLensWrites($this->schema(1), ['besluitDatum' => 'x']));
	}
}
