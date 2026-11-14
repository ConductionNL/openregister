<?php

/**
 * What a Nextcloud share says about an object grant: its verbs, whether it
 * passes down to the object's children, whether it is the sharer's own access
 * marker, and the object it names.
 *
 * A share's attributes are written by other code paths and by hand, so every
 * reader here has to survive a missing attribute set, a malformed value and a
 * share whose node has gone, and each must fail CLOSED where a wrong answer
 * would widen access: no verbs rather than garbage, no object rather than a
 * container, inheritable unless explicitly marked local.
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

use OCA\OpenRegister\Service\Rbac\ShareGrantAttributes;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Share\IAttributes;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\ShareGrantAttributes
 * @uses   \OCA\OpenRegister\Formats\UuidFormat
 */
class ShareGrantAttributesTest extends TestCase {

	private const UUID = '550e8400-e29b-41d4-a716-446655440000';

	/**
	 * The reader under test.
	 *
	 * @var ShareGrantAttributes
	 */
	private ShareGrantAttributes $attributes;

	protected function setUp(): void {
		parent::setUp();
		$this->attributes = new ShareGrantAttributes();
	}//end setUp()

	/**
	 * Stored verb values and the verbs read from them.
	 *
	 * @return array<string, array{0: mixed, 1: array<int, string>}>
	 */
	public static function verbValues(): array {
		return [
			'a list' => [['read', 'update'], ['read', 'update']],
			'a JSON string' => ['["read","delete"]', ['read', 'delete']],
			'blanks and non-strings dropped, keys reset' => [[2 => 'read', 3 => '', 4 => 7, 5 => null, 6 => 'share'], ['read', 'share']],
			'malformed JSON' => ['[read', []],
			'a scalar' => [true, []],
			'nothing stored' => [null, []],
		];
	}//end verbValues()

	/**
	 * The verbs a share grants, as a clean list.
	 *
	 * @param mixed              $stored   The stored attribute.
	 * @param array<int, string> $expected The verbs read.
	 *
	 * @return void
	 */
	#[DataProvider('verbValues')]
	public function testTheVerbsAreReadAsACleanList(mixed $stored, array $expected): void {
		$share = $this->share(attributes: $this->attributeSet(values: ['verbs' => $stored]));

		$this->assertSame($expected, $this->attributes->verbsOf(share: $share));
	}//end testTheVerbsAreReadAsACleanList()

	/**
	 * A share with no attribute set, or one that throws, grants no verbs.
	 *
	 * @return void
	 */
	public function testNoAttributeSetGrantsNoVerbs(): void {
		$this->assertSame([], $this->attributes->verbsOf(share: $this->share(attributes: null)));

		$throwing = $this->createMock(IShare::class);
		$throwing->method('getAttributes')->willThrowException(new RuntimeException('corrupt row'));
		$this->assertSame([], $this->attributes->verbsOf(share: $throwing));
	}//end testNoAttributeSetGrantsNoVerbs()

	/**
	 * Stored inheritable values and whether the grant passes down.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function inheritableValues(): array {
		return [
			'false' => [false, false],
			'zero' => [0, false],
			'the string zero' => ['0', false],
			'the string false' => ['false', false],
			'true' => [true, true],
			'nothing stored' => [null, true],
			'an unrecognisable value' => ['no', true],
		];
	}//end inheritableValues()

	/**
	 * Only an explicit, recognisable false marks a grant local.
	 *
	 * @param mixed $stored   The stored attribute.
	 * @param bool  $expected Whether the grant is inheritable.
	 *
	 * @return void
	 */
	#[DataProvider('inheritableValues')]
	public function testOnlyAnExplicitFalseMakesAGrantLocal(mixed $stored, bool $expected): void {
		$share = $this->share(attributes: $this->attributeSet(values: ['inheritable' => $stored]));

		$this->assertSame($expected, $this->attributes->inheritableOf(share: $share));
		$this->assertTrue($this->attributes->inheritableOf(share: $this->share(attributes: null)));
	}//end testOnlyAnExplicitFalseMakesAGrantLocal()

	/**
	 * The sharer-access marker counts only on a user share the owner made, and only when exactly true.
	 *
	 * @return void
	 */
	public function testTheSharerAccessMarkerCountsOnlyOnTheOwnersOwnUserShare(): void {
		$marked = $this->attributeSet(values: ['sharerAccess' => true]);

		$this->assertTrue($this->attributes->isSharerAccess(share: $this->share(attributes: $marked)));
		$this->assertFalse($this->attributes->isSharerAccess(share: $this->share(attributes: $marked, type: IShare::TYPE_GROUP)));
		$this->assertFalse($this->attributes->isSharerAccess(share: $this->share(attributes: $marked, sharedBy: 'someone-else')));
		$this->assertFalse($this->attributes->isSharerAccess(share: $this->share(attributes: $this->attributeSet(values: ['sharerAccess' => 'true']))));
		$this->assertFalse($this->attributes->isSharerAccess(share: $this->share(attributes: null)));

		$throwing = $this->createMock(IShare::class);
		$throwing->method('getShareType')->willThrowException(new RuntimeException('corrupt row'));
		$this->assertFalse($this->attributes->isSharerAccess(share: $throwing));
	}//end testTheSharerAccessMarkerCountsOnlyOnTheOwnersOwnUserShare()

	/**
	 * A folder share named by a uuid names that object; a sharer-access marker,
	 * a file share, a vanished node and a non-uuid name name none.
	 *
	 * @return void
	 */
	public function testOnlyAUuidFolderShareNamesAnObject(): void {
		$this->assertSame(self::UUID, $this->attributes->objectUuidOf(share: $this->share(attributes: null, nodeName: self::UUID)));

		$sharerAccess = $this->share(attributes: $this->attributeSet(values: ['sharerAccess' => true]), nodeName: self::UUID);
		$this->assertNull($this->attributes->objectUuidOf(share: $sharerAccess));
		$this->assertNull($this->attributes->objectUuidOf(share: $this->share(attributes: null, nodeName: self::UUID, nodeType: 'file')));
		$this->assertNull($this->attributes->objectUuidOf(share: $this->share(attributes: null, nodeName: '')));
		$this->assertNull($this->attributes->objectUuidOf(share: $this->share(attributes: null, nodeName: 'Publicaties')));

		$gone = $this->share(attributes: null);
		$gone->method('getNode')->willThrowException(new NotFoundException('node gone'));
		$this->assertNull($this->attributes->objectUuidOf(share: $gone));
	}//end testOnlyAUuidFolderShareNamesAnObject()

	/**
	 * An attribute set answering from a map under the openregister scope.
	 *
	 * @param array<string, mixed> $values The attributes by key.
	 *
	 * @return IAttributes
	 */
	private function attributeSet(array $values): IAttributes {
		$attributes = $this->createMock(IAttributes::class);
		$attributes->method('getAttribute')->willReturnCallback(
			static fn (string $scope, string $key): mixed => ($scope === ShareGrantAttributes::VERB_ATTRIBUTE_SCOPE ? ($values[$key] ?? null) : null)
		);

		return $attributes;
	}//end attributeSet()

	/**
	 * A share double.
	 *
	 * @param IAttributes|null $attributes The attribute set.
	 * @param int              $type       The share type.
	 * @param string           $sharedBy   Who made the share; the owner is `owner`.
	 * @param string|null      $nodeName   The shared node's name, or null for no node set up.
	 * @param string           $nodeType   The node type.
	 *
	 * @return IShare&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function share(?IAttributes $attributes, int $type = IShare::TYPE_USER, string $sharedBy = 'owner', ?string $nodeName = null, string $nodeType = 'folder'): IShare {
		$share = $this->createMock(IShare::class);
		$share->method('getAttributes')->willReturn($attributes);
		$share->method('getShareType')->willReturn($type);
		$share->method('getSharedBy')->willReturn($sharedBy);
		$share->method('getShareOwner')->willReturn('owner');
		$share->method('getNodeType')->willReturn($nodeType);
		if ($nodeName !== null) {
			$node = $this->createMock(Node::class);
			$node->method('getName')->willReturn($nodeName);
			$share->method('getNode')->willReturnCallback(static fn (): Node => $node);
		}

		return $share;
	}//end share()
}//end class
