<?php

/**
 * LensResolverAccessGoldenTest
 *
 * Pins the allowed and refused outcomes of a lens read before its units are
 * reshaped (ADR-011): a readable reference renders its value, an unreadable
 * one renders the withheld marker, a reference whose schema cannot be read is
 * withheld, `_rbac: false` skips the check, and the check asks for `read` on
 * the referenced record as the signed-in user.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hinge
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Hinge;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Hinge\LensLookups;
use OCA\OpenRegister\Service\Hinge\LensResolver;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LensResolverAccessGoldenTest extends TestCase {

	/** @var list<array<string, mixed>> */
	private array $asked = [];

	private function lensSchema(): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setConfiguration(
			[
				'x-openregister-lenses' => [
					'besluitDatum' => ['through' => 'besluit', 'property' => 'meta.datum'],
				],
			]
		);
		return $schema;
	}

	private function referenced(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('uuid-besluit');
		$object->setSchema(99);
		$object->setOwner('owner-1');
		$object->setObject(['meta' => ['datum' => '2026-01-01'], 'secret' => 'x']);
		return $object;
	}

	private function resolver(bool $mayRead, bool $schemaReadable = true): LensResolver {
		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('find')->willReturn($this->referenced());

		$schemaMapper = $this->createMock(SchemaMapper::class);
		if ($schemaReadable === true) {
			$schemaMapper->method('find')->willReturn(new Schema());
		} else {
			$schemaMapper->method('find')->willThrowException(new \RuntimeException('gone'));
		}

		$permissionHandler = $this->createMock(PermissionHandlerInterface::class);
		$permissionHandler->method('hasPermission')->willReturnCallback(
			function (...$args) use ($mayRead): bool {
				$this->asked[] = $args;
				return $mayRead;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		return new LensResolver(
			lookups: new LensLookups(magicMapper: $magicMapper, schemaMapper: $schemaMapper),
			permissionHandler: $permissionHandler,
			userSession: $userSession,
			logger: new NullLogger()
		);
	}

	/**
	 * @return array<string, array{bool, bool, bool, mixed}>
	 */
	public static function outcomes(): array {
		return [
			'allowed renders the value'           => [true, true, true, '2026-01-01'],
			'refused renders withheld'            => [false, true, true, LensResolver::WITHHELD],
			'unreadable schema renders withheld'  => [true, false, true, LensResolver::WITHHELD],
			'rbac off renders the value'          => [false, true, false, '2026-01-01'],
			'rbac off ignores the schema lookup'  => [false, false, false, '2026-01-01'],
		];
	}

	/**
	 * @dataProvider outcomes
	 */
	public function testOutcome(bool $mayRead, bool $schemaReadable, bool $rbac, mixed $expected): void {
		$data = $this->resolver($mayRead, $schemaReadable)->apply($this->lensSchema(), ['besluit' => 'https://x/uuid-besluit'], $rbac);

		$this->assertSame($expected, $data['besluitDatum']);
		$this->assertArrayNotHasKey('secret', $data);
	}

	public function testTheCheckAsksReadAsTheSignedInUserOnTheReferencedRecord(): void {
		$this->resolver(true)->apply($this->lensSchema(), ['besluit' => 'uuid-besluit'], true);

		$this->assertCount(1, $this->asked);
		$args = $this->asked[0];
		$this->assertSame('read', $args[1]);
		$this->assertSame('alice', $args[2]);
		$this->assertSame('owner-1', $args[3]);
		$this->assertTrue($args[4]);
		$this->assertSame('uuid-besluit', $args[5]->getUuid());
	}

	public function testAMutatedPermissionAnswerChangesTheOutcome(): void {
		// Mutation check: flipping the permission answer must flip the render.
		$allowed = $this->resolver(true)->apply($this->lensSchema(), ['besluit' => 'uuid-besluit'], true);
		$refused = $this->resolver(false)->apply($this->lensSchema(), ['besluit' => 'uuid-besluit'], true);

		$this->assertNotSame($allowed['besluitDatum'], $refused['besluitDatum']);
	}

	public function testNoReferenceRendersNullAndAsksNothing(): void {
		$data = $this->resolver(false)->apply($this->lensSchema(), ['besluit' => null], true);

		$this->assertNull($data['besluitDatum']);
		$this->assertSame([], $this->asked);
	}
}
