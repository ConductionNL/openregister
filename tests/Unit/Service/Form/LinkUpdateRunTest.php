<?php

/**
 * An update run started from an `edit` link (or-form-and-journey-registry, task 6.2).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-form-link-may-open-an-existing-record-for-change-req-fjr-008
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Form\LinkUpdateRun;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Sharing\LinkJourneyGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Prefill from the object, refuse undeclared fields, update through the save path.
 *
 * @covers \OCA\OpenRegister\Service\Form\LinkUpdateRun
 * @uses   \OCA\OpenRegister\Service\Sharing\LinkJourneyGuard
 * @uses   \OCA\OpenRegister\Service\Form\JourneyDefinition
 * @uses   \OCA\OpenRegister\Exception\FormSubmitRefusedException
 * @uses   \OCA\OpenRegister\Db\AccessLink
 * @uses   \OCA\OpenRegister\Db\AccessLink\AccessLinkFieldTypes
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 */
class LinkUpdateRunTest extends TestCase {

	private const OBJECT = '5f0c0a1e-0000-4000-8000-000000000001';

	private const JOURNEY = '9b2e4c1a-0000-4000-8000-000000000002';

	private ObjectOperationsInterface&MockObject $objects;

	/**
	 * The journey body the store answers.
	 *
	 * @var array<string, mixed>
	 */
	private array $journey = [];

	protected function setUp(): void {
		parent::setUp();
		$this->journey = [
			'title' => 'Correct your request',
			'access' => 'anonymous',
			'mode' => 'update',
			'status' => 'published',
			'steps' => [
				[
					'id' => 'contact',
					'type' => 'form',
					'fields' => ['telefoon'],
					'writes' => [['register' => 'aanvragen', 'schema' => 'aanvraag', 'mapping' => ['fields' => [['field' => 'email', 'property' => 'emailadres']]]]],
				],
				['id' => 'mail', 'type' => 'form', 'fields' => ['email']],
				['id' => 'done', 'type' => 'confirmation'],
			],
		];
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->objects->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntity {
				if ($id !== self::JOURNEY) {
					throw new DoesNotExistException('none');
				}

				$journey = new ObjectEntity();
				$journey->setUuid(self::JOURNEY);
				$journey->setObject($this->journey);
				return $journey;
			}
		);
	}

	private function service(): LinkUpdateRun {
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			static function (string|int $id): Schema {
				$schema = new Schema();
				$schema->setId(['aanvraag' => 12, '12' => 12][(string)$id] ?? 99);
				return $schema;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $args = []): string => vsprintf($text, $args));

		return new LinkUpdateRun(
			objects: $this->objects,
			guard: new LinkJourneyGuard(objects: $this->objects, schemas: $schemas, permissions: $this->createMock(PermissionHandlerInterface::class)),
			l10n: $l10n
		);
	}

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid(self::OBJECT);
		$object->setRegister('3');
		$object->setSchema('12');
		$object->setObject(['telefoon' => '0612345678', 'emailadres' => 'a@example.org', 'status' => 'open', 'bsn' => '999993653']);
		return $object;
	}

	private function link(string $capabilities = 'read,edit', ?string $journey = self::JOURNEY, string $subject = self::OBJECT): AccessLink {
		$link = new AccessLink();
		$link->setUuid('7a1f0f2e-0000-4000-8000-000000000003');
		$link->setSubjectType(AccessLink::SUBJECT_OBJECT);
		$link->setSubjectId($subject);
		$link->setCapabilities($capabilities);
		$link->setJourney($journey);
		$link->setExpiresAt(new DateTime('+1 day'));
		return $link;
	}

	public function testTheRunIsPrefilledWithTheDeclaredFieldsOnly(): void {
		$run = $this->service()->start(link: $this->link(), object: $this->object());

		$this->assertSame(self::JOURNEY, $run['journey']);
		$this->assertSame(self::OBJECT, $run['object']);
		$this->assertSame('contact', $run['step']);
		$this->assertSame(['contact', 'mail', 'done'], $run['steps']);
		$this->assertSame(['telefoon', 'email'], $run['fields']);
		$this->assertSame(['telefoon', 'email'], $run['declared']);
		$this->assertSame(['telefoon' => '0612345678', 'email' => 'a@example.org'], $run['answers'], 'status and bsn are not shown');
	}

	public function testASubmitUpdatesTheObjectThroughTheSavePath(): void {
		$this->objects->expects($this->once())->method('saveObject')->with(
			['telefoon' => '0687654321', 'emailadres' => 'b@example.org', 'status' => 'open', 'bsn' => '999993653', 'id' => self::OBJECT],
			$this->callback(
				static fn (ObjectSaveOptions $options): bool => $options->uuid === self::OBJECT
					&& $options->target->register === '3'
					&& $options->target->schema === '12'
			),
			false,
			false
		)->willReturnCallback(
			static function (array $data): ObjectEntity {
				$saved = new ObjectEntity();
				$saved->setUuid(self::OBJECT);
				$saved->setObject($data);
				return $saved;
			}
		);

		$outcome = $this->service()->submit(
			link: $this->link(),
			object: $this->object(),
			answers: ['telefoon' => '0687654321', 'email' => 'b@example.org']
		);

		$this->assertSame(['telefoon', 'emailadres'], $outcome['changed']);
		$this->assertSame(['telefoon' => '0687654321', 'email' => 'b@example.org'], $outcome['answers']);
		$this->assertSame(self::OBJECT, $outcome['saved']->getUuid());
	}

	public function testAFieldOutsideTheJourneyIsRefusedAndNothingIsSaved(): void {
		$this->objects->expects($this->never())->method('saveObject');

		try {
			$this->service()->submit(
				link: $this->link(),
				object: $this->object(),
				answers: ['telefoon' => '0687654321', 'status' => 'afgehandeld']
			);
			$this->fail('an undeclared field was accepted');
		} catch (FormSubmitRefusedException $refused) {
			$this->assertSame(422, $refused->getStatus());
			$this->assertSame(
				[['field' => 'status', 'code' => 'out-of-journey', 'message' => 'The field status is not part of this journey.']],
				$refused->getFindings()
			);
		}
	}

	/**
	 * @return array<string, array{0: string, 1: string|null, 2: string, 3: array<string, mixed>}>
	 */
	public static function unfitLinks(): array {
		return [
			'a link without edit' => ['read,comment', self::JOURNEY, self::OBJECT, []],
			'a link without a journey' => ['read,edit', null, self::OBJECT, []],
			'a link over another object' => ['read,edit', self::JOURNEY, '5f0c0a1e-0000-4000-8000-0000000000ff', []],
			'another journey' => ['read,edit', '9b2e4c1a-0000-4000-8000-0000000000ee', self::OBJECT, []],
			'a create journey' => ['read,edit', self::JOURNEY, self::OBJECT, ['mode' => 'create']],
			'an unpublished journey' => ['read,edit', self::JOURNEY, self::OBJECT, ['status' => 'draft']],
		];
	}

	/**
	 * @dataProvider unfitLinks
	 *
	 * @param array<string, mixed> $journeyChange
	 */
	public function testALinkThatCannotRunTheJourneyAnswersTheOne404(string $capabilities, ?string $journey, string $subject, array $journeyChange): void {
		$this->journey = array_merge($this->journey, $journeyChange);
		$this->objects->expects($this->never())->method('saveObject');

		foreach (['start', 'submit'] as $act) {
			try {
				if ($act === 'start') {
					$this->service()->start(link: $this->link(capabilities: $capabilities, journey: $journey, subject: $subject), object: $this->object());
				} else {
					$this->service()->submit(link: $this->link(capabilities: $capabilities, journey: $journey, subject: $subject), object: $this->object(), answers: ['telefoon' => '1']);
				}

				$this->fail($act . ' was allowed');
			} catch (FormSubmitRefusedException $refused) {
				$this->assertSame(404, $refused->getStatus(), $act);
				$this->assertSame('This journey does not exist.', $refused->getMessage(), $act);
			}
		}
	}
}//end class
