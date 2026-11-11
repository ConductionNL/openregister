<?php

/**
 * A share reaches an object only through a folder named by a uuid.
 *
 * Register and schema folders sit in the same tree. Both readers of a share's
 * folder name must refuse those, or a share of a container becomes a grant on
 * an object that merely shares its name.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Controller\ObjectShareLinkController;
use OCA\OpenRegister\Service\Rbac\ShareGrantAttributes;
use OCP\Files\Node;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\ShareGrantAttributes
 * @covers \OCA\OpenRegister\Controller\ObjectShareLinkController
 * @uses   \OCA\OpenRegister\Formats\UuidFormat
 */
class ShareFolderUuidTest extends TestCase {

	private const UUID = '550e8400-e29b-41d4-a716-446655440000';

	/**
	 * Folder names and what each reader answers.
	 *
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function names(): array {
		return [
			'a uuid' => [self::UUID, self::UUID],
			'an uppercase uuid' => [strtoupper(self::UUID), strtoupper(self::UUID)],
			'a register folder' => ['Publicaties', null],
			'an empty name' => ['', null],
			'a uuid inside a longer name' => ['register-' . self::UUID, null],
			'32 hex' => [str_replace('-', '', self::UUID), null],
		];
	}//end names()

	/**
	 * A folder share with this name.
	 *
	 * @param string $name The folder name.
	 *
	 * @return IShare The share.
	 */
	private function share(string $name): IShare {
		$node = $this->createMock(Node::class);
		$node->method('getName')->willReturn($name);
		$share = $this->createMock(IShare::class);
		$share->method('getNodeType')->willReturn('folder');
		$share->method('getNode')->willReturn($node);

		return $share;
	}//end share()

	/**
	 * The grant reader.
	 *
	 * @param string      $name     The folder name.
	 * @param string|null $expected The answer.
	 *
	 * @return void
	 */
	#[DataProvider('names')]
	public function testTheGrantReaderTakesOnlyAUuidName(string $name, ?string $expected): void {
		$this->assertSame($expected, (new ShareGrantAttributes())->objectUuidOf(share: $this->share(name: $name)));
	}//end testTheGrantReaderTakesOnlyAUuidName()

	/**
	 * The share-link reader.
	 *
	 * @param string      $name     The folder name.
	 * @param string|null $expected The answer.
	 *
	 * @return void
	 */
	#[DataProvider('names')]
	public function testTheShareLinkReaderTakesOnlyAUuidName(string $name, ?string $expected): void {
		$controller = (new ReflectionClass(ObjectShareLinkController::class))->newInstanceWithoutConstructor();
		$reader = new ReflectionMethod(ObjectShareLinkController::class, 'objectUuidOf');

		$this->assertSame($expected, $reader->invoke($controller, $this->share(name: $name)));
	}//end testTheShareLinkReaderTakesOnlyAUuidName()
}//end class
