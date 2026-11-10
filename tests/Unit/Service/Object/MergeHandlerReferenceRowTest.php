<?php

/**
 * A merge saves the stored row of a referencing object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\MergeHandler;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\MergeHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\MergeRun
 */
class MergeHandlerReferenceRowTest extends TestCase {

	/**
	 * The entities update() received, by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $written = [];

	private function object(string $uuid, array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('3');
		$object->setSchema('5');
		$object->setObject($data);

		return $object;
	}//end object()

	private function merge(ObjectEntity $slim, ObjectEntity $stored): void {
		$register = new Register();
		$register->setId(3);
		$schema = new Schema();
		$schema->setId(5);
		$objects = [
			'src' => $this->object('src', ['title' => 'source']),
			'tgt' => $this->object('tgt', ['title' => 'target']),
			'ref' => $stored,
		];

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('findAcrossAllSources')->willReturnCallback(
			static fn (...$args): array => ['object' => $objects[$args[0]], 'register' => $register, 'schema' => $schema]
		);
		$mapper->method('findByRelation')->willReturn([$slim]);
		$mapper->method('update')->willReturnCallback(
			function (...$args): ObjectEntity {
				$this->written[(string)$args[0]->getUuid()] = $args[0];
				return $args[0];
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		$handler = new MergeHandler($mapper, $this->createMock(FileService::class), $session);
		$handler->mergeObjects('src', ['target' => 'tgt', 'object' => ['title' => 'merged'], 'fileAction' => 'delete', 'relationAction' => 'drop']);
	}//end merge()

	public function testTheStoredRowIsRedirectedAndKeepsItsMetadata(): void {
		$stored = $this->object('ref', ['title' => 'refers', 'link' => 'src']);
		$stored->setOwner('alice');
		$stored->setOrganisation('org-1');
		$stored->setVersion('0.0.3');
		$stored->setRelations(['link' => 'src']);

		$this->merge(slim: $this->object('ref', ['title' => 'refers', 'link' => 'src']), stored: $stored);

		$written = $this->written['ref'];
		$this->assertSame($stored, $written);
		$this->assertSame('alice', $written->getOwner());
		$this->assertSame('org-1', $written->getOrganisation());
		$this->assertSame('0.0.3', $written->getVersion());
		$this->assertSame('tgt', $written->getObject()['link']);
		$this->assertSame(['link' => 'tgt'], $written->getRelations());
	}//end testTheStoredRowIsRedirectedAndKeepsItsMetadata()

	public function testAnObjectThatDoesNotCiteTheSourceIsNotWritten(): void {
		$other = $this->object('ref', ['title' => 'unrelated']);

		$this->merge(slim: $other, stored: clone $other);

		$this->assertArrayNotHasKey('ref', $this->written);
	}//end testAnObjectThatDoesNotCiteTheSourceIsNotWritten()
}//end class
