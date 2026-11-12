<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Federation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Federation;

use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Federation\ShareTargets;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolver;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A share's targets, read as the caller.
 *
 * @covers \OCA\OpenRegister\Service\Federation\ShareTargets
 * @uses   \OCA\OpenRegister\Db\FederatedShare
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Exception\NotAuthorizedException
 */
class ShareTargetsTest extends TestCase {

	/** @var RegisterScopedSchemaResolver&MockObject */
	private RegisterScopedSchemaResolver $resolver;

	/** @var ObjectService&MockObject */
	private ObjectService $objects;

	private ShareTargets $targets;

	protected function setUp(): void {
		$this->resolver = $this->createMock(RegisterScopedSchemaResolver::class);
		$this->objects  = $this->createMock(ObjectService::class);
		$this->targets  = new ShareTargets($this->resolver, $this->objects);
	}

	private function share(array $fields): FederatedShare {
		return (new FederatedShare())->hydrate(object: $fields + ['register' => 'zaken', 'schema' => 'zaak']);
	}

	public function testTheSchemaIsResolvedInsideTheSharesRegister(): void {
		$schema = new Schema();
		$this->resolver->expects($this->once())->method('resolvePair')->with('zaken', 'zaak')->willReturn(['register' => new Register(), 'schema' => $schema]);

		$this->assertSame($schema, $this->targets->schema(share: $this->share(fields: [])));
	}

	/** @return array<string, array{array<string, string>}> */
	public static function incompletePairs(): array {
		return ['no schema' => [['schema' => '']], 'no register' => [['register' => '']]];
	}

	/**
	 * @dataProvider incompletePairs
	 *
	 * @param array<string, string> $fields The share's fields.
	 */
	public function testAShareThatNamesNoRegisterOrSchemaHasNoSchema(array $fields): void {
		$this->resolver->expects($this->never())->method('resolvePair');

		$this->assertNull($this->targets->schema(share: $this->share(fields: $fields)));
	}

	public function testASchemaItsRegisterDoesNotCarryIsNone(): void {
		$this->resolver->method('resolvePair')->willThrowException(new \RuntimeException('not carried'));

		$this->assertNull($this->targets->schema(share: $this->share(fields: [])));
	}

	public function testTheRegisterIsFoundByTheNameTheShareStores(): void {
		$register = new Register();
		$this->resolver->expects($this->once())->method('resolveRegister')->with('zaken')->willReturn($register);

		$this->assertSame($register, $this->targets->register(share: $this->share(fields: [])));
	}

	public function testAShareWithoutARegisterHasNone(): void {
		$this->resolver->expects($this->never())->method('resolveRegister');

		$this->assertNull($this->targets->register(share: $this->share(fields: ['register' => ''])));
	}

	public function testARegisterThatDoesNotResolveIsNone(): void {
		$this->resolver->method('resolveRegister')->willThrowException(new DoesNotExistException('gone'));

		$this->assertNull($this->targets->register(share: $this->share(fields: [])));
	}

	public function testTheObjectIsReadWithTheCallersOwnAccessControl(): void {
		$this->objects->expects($this->once())->method('find')
			->with(...FindCallShape::findArgs(
				$this->identicalTo('zaak-7'),
				$this->anything(),
				$this->anything(),
				$this->identicalTo('zaken'),
				$this->identicalTo('zaak'),
				$this->isTrue(),
				$this->isTrue()
			))
			->willReturn(new ObjectEntity());

		$this->assertTrue(
			$this->targets->callerReadsObject(share: $this->share(fields: ['objectUri' => 'https://or.example/api/objects/zaken/zaak/zaak-7/']))
		);
	}

	public function testARefusedReadIsNoRead(): void {
		$this->objects->method('find')->willThrowException(new NotAuthorizedException(message: 'no'));

		$this->assertFalse($this->targets->callerReadsObject(share: $this->share(fields: ['objectUri' => 'zaak-7'])));
	}

	public function testAnObjectThatIsNotFoundIsNoRead(): void {
		$this->objects->method('find')->willReturn(null);

		$this->assertFalse($this->targets->callerReadsObject(share: $this->share(fields: ['objectUri' => 'zaak-7'])));
	}

	public function testAShareNamingNoObjectReadsNothing(): void {
		$this->objects->expects($this->never())->method('find');

		$this->assertFalse($this->targets->callerReadsObject(share: $this->share(fields: ['objectUri' => ''])));
	}
}
