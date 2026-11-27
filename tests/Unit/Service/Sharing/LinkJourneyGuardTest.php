<?php

/**
 * Who may hand out an `edit` link, and which journey fits its object (or-form-and-journey-registry, task 6.1).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-form-link-may-open-an-existing-record-for-change-req-fjr-008
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Sharing;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Sharing\LinkJourneyGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Sharing\LinkJourneyGuard
 * @uses   \OCA\OpenRegister\Service\Form\JourneyDefinition
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class LinkJourneyGuardTest extends TestCase {

	private const OBJECT = '5f0c0a1e-0000-4000-8000-000000000001';

	private const JOURNEY = '9b2e4c1a-0000-4000-8000-000000000002';

	private ObjectOperationsInterface&MockObject $objects;

	private PermissionHandlerInterface&MockObject $permissions;

	/**
	 * The journey body the store answers.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $journeyBody = null;

	/**
	 * Whether the caller can read the object.
	 */
	private bool $callerReads = true;

	/**
	 * The `_rbac` flag of every object read, by id.
	 *
	 * @var array<string, list<bool>>
	 */
	private array $reads = [];

	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->objects->method('find')->willReturnCallback(
			function (int|string $id, $options = null, bool $_rbac = true, bool $_multitenancy = true): ?ObjectEntity {
				$this->reads[(string)$id][] = $_rbac;
				if ($id === self::OBJECT) {
					return ($this->callerReads === true || $_rbac === false) ? $this->object() : null;
				}

				if ($id === self::JOURNEY && $this->journeyBody !== null) {
					$journey = new ObjectEntity();
					$journey->setUuid(self::JOURNEY);
					$journey->setObject($this->journeyBody);
					return $journey;
				}

				throw new DoesNotExistException('none');
			}
		);
		$this->permissions = $this->createMock(PermissionHandlerInterface::class);
	}

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid(self::OBJECT);
		$object->setSchema('12');
		$object->setOwner('alice');
		$object->setObject(['telefoon' => '0612345678', 'status' => 'open']);
		return $object;
	}

	private function guard(): LinkJourneyGuard {
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			static function (string|int $id): Schema {
				$ids = ['12' => 12, 'aanvraag' => 12, 'melding' => 13];
				if (isset($ids[(string)$id]) === false) {
					throw new DoesNotExistException('no schema');
				}

				$schema = new Schema();
				$schema->setId($ids[(string)$id]);
				return $schema;
			}
		);

		return new LinkJourneyGuard(objects: $this->objects, schemas: $schemas, permissions: $this->permissions);
	}

	private function updateJourney(string $schema = 'aanvraag', string $mode = 'update'): array {
		return [
			'title' => 'Correct your request',
			'access' => 'anonymous',
			'mode' => $mode,
			'status' => 'published',
			'steps' => [
				['id' => 'contact', 'type' => 'form', 'fields' => ['telefoon'], 'writes' => [['register' => 'aanvragen', 'schema' => $schema]]],
			],
		];
	}

	public function testAnUpdaterMayMintAnEditLinkForAFittingJourney(): void {
		$this->journeyBody = $this->updateJourney();
		$this->permissions->expects($this->once())->method('hasPermission')->with(
			$this->callback(static function (PermissionAsk $ask): bool {
				$check = $ask->check();

				return $check->schema->getId() === 12
					&& $check->action === 'update'
					&& $check->userId === null
					&& $check->objectOwner === 'alice'
					&& $check->object?->getUuid() === self::OBJECT;
			})
		)->willReturn(true);

		$this->assertNull($this->guard()->mintRefusal(objectUuid: self::OBJECT, journeyId: self::JOURNEY));
		$this->assertSame([true], $this->reads[self::OBJECT], 'the object is read under the caller\'s own rules');
		$this->assertSame([false], $this->reads[self::JOURNEY], 'the journey is the author\'s, read with the rules off');
	}

	public function testAReaderWhoMayNotUpdateCannotHandOutAnEditLink(): void {
		$this->journeyBody = $this->updateJourney();
		$this->permissions->method('hasPermission')->willReturn(false);

		$this->assertSame(
			'You may not change this record, so you cannot hand out a link that changes it.',
			$this->guard()->mintRefusal(objectUuid: self::OBJECT, journeyId: self::JOURNEY)
		);
	}

	public function testAnUnreadableObjectAndAFailingPermissionCheckAreRefusals(): void {
		$this->journeyBody = $this->updateJourney();
		$this->callerReads = false;
		$this->assertSame('The record this link opens cannot be found.', $this->guard()->mintRefusal(objectUuid: self::OBJECT, journeyId: self::JOURNEY));

		$this->callerReads = true;
		$this->permissions->method('hasPermission')->willThrowException(new \RuntimeException('no session'));
		$this->assertSame(
			'You may not change this record, so you cannot hand out a link that changes it.',
			$this->guard()->mintRefusal(objectUuid: self::OBJECT, journeyId: self::JOURNEY)
		);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>|null, 1: string}>
	 */
	public static function unfit(): array {
		$base = [
			'title' => 'J', 'access' => 'anonymous', 'status' => 'published',
			'steps' => [['id' => 's', 'type' => 'form', 'fields' => ['telefoon'], 'writes' => [['register' => 'r', 'schema' => 'aanvraag']]]],
		];
		$second = $base;
		$second['mode'] = 'update';
		$second['steps'][] = ['id' => 't', 'type' => 'form', 'fields' => ['x'], 'writes' => [['register' => 'r', 'schema' => 'melding']]];
		$nowrite = ['mode' => 'update'] + $base;
		$nowrite['steps'] = [['id' => 's', 'type' => 'form', 'fields' => ['telefoon']]];
		$unknown = ['mode' => 'update'] + $base;
		$unknown['steps'][0]['writes'][0]['schema'] = 'gone';

		return [
			'no such journey' => [null, 'The journey this link names cannot be found.'],
			'a create journey' => [['mode' => 'create'] + $base, 'The journey this link names does not change an existing record: its mode is not update.'],
			'mode absent' => [$base, 'The journey this link names does not change an existing record: its mode is not update.'],
			'writes nothing' => [$nowrite, 'The journey this link names writes nothing.'],
			'a write to another schema' => [$second, 'The journey this link names changes records of another schema than the record this link opens.'],
			'a write to an unknown schema' => [$unknown, 'The journey this link names changes records of another schema than the record this link opens.'],
		];
	}

	/**
	 * @dataProvider unfit
	 */
	public function testAJourneyThatDoesNotFitTheObjectIsRefused(?array $body, string $message): void {
		$this->journeyBody = $body;
		$this->permissions->method('hasPermission')->willReturn(true);

		$this->assertSame($message, $this->guard()->mintRefusal(objectUuid: self::OBJECT, journeyId: self::JOURNEY));
		$this->assertNull($this->guard()->journeyFor(object: $this->object(), journeyId: self::JOURNEY));
	}

	public function testAFittingJourneyIsReturnedForUse(): void {
		$this->journeyBody = $this->updateJourney(schema: '12');

		$journey = $this->guard()->journeyFor(object: $this->object(), journeyId: self::JOURNEY);

		$this->assertNotNull($journey);
		$this->assertSame(self::JOURNEY, $journey->id);
		$this->assertSame(['contact'], $journey->stepIds());
		$this->assertNull($this->guard()->journeyFor(object: $this->object(), journeyId: '  '));
	}
}//end class
