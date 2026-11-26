<?php

/**
 * A dangerous detection pattern is refused when it is written (REQ-RPD-004).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-an-officers-own-pattern-runs-beside-the-model-req-rpd-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\DetectionPatternValidationListener;
use OCA\OpenRegister\Service\RedactionPolicy\DetectionPatternValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The write guard over detection patterns of the redaction-policy register.
 */
#[CoversClass(DetectionPatternValidationListener::class)]
#[CoversClass(DetectionPatternValidator::class)]
class DetectionPatternValidationListenerTest extends TestCase {

	/**
	 * Saving `(a+)+$` is refused, naming the backtrack limit.
	 *
	 * @return void
	 */
	public function testADangerousPatternIsRefusedOnCreate(): void {
		$event = new ObjectCreatingEvent($this->object(data: ['kind' => 'regex', 'pattern' => '(a+)+$', 'entityType' => 'X']));

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('detection-pattern-invalid', $event->getErrors()['code']);
		self::assertStringContainsString('backtrack limit', $event->getErrors()['message']);
	}//end testADangerousPatternIsRefusedOnCreate()

	/**
	 * An update to an empty-matching pattern is refused too.
	 *
	 * @return void
	 */
	public function testAnUpdateToAnEmptyMatchIsRefused(): void {
		$event = new ObjectUpdatingEvent($this->object(data: ['kind' => 'regex', 'pattern' => '\d*', 'entityType' => 'X']), $this->object(data: []));

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertStringContainsString('empty string', $event->getErrors()['message']);
	}//end testAnUpdateToAnEmptyMatchIsRefused()

	/**
	 * A sound pattern is saved.
	 *
	 * @return void
	 */
	public function testASoundPatternIsSaved(): void {
		$event = new ObjectCreatingEvent($this->object(data: ['kind' => 'regex', 'pattern' => '\bZK-\d{4}-\d{5}\b', 'entityType' => 'CASE_NUMBER']));

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testASoundPatternIsSaved()

	/**
	 * Another app's schema of the same slug, and any other schema, are not judged.
	 *
	 * @return void
	 */
	public function testOtherRegistersAndSchemasAreNotJudged(): void {
		$bad = ['kind' => 'regex', 'pattern' => '(a+)+$'];

		$elsewhere = new ObjectCreatingEvent($this->object(data: $bad));
		$this->listener(registerSlug: 'other-app')->handle($elsewhere);

		$otherSchema = new ObjectCreatingEvent($this->object(data: $bad));
		$this->listener(schemaSlug: 'termRule')->handle($otherSchema);

		self::assertFalse($elsewhere->isPropagationStopped());
		self::assertFalse($otherSchema->isPropagationStopped());
	}//end testOtherRegistersAndSchemasAreNotJudged()

	/**
	 * A pattern whose register cannot be read is refused rather than stored unchecked.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterRefusesThePattern(): void {
		$event = new ObjectCreatingEvent($this->object(data: ['kind' => 'regex', 'pattern' => 'x', 'entityType' => 'X']));

		$this->listener(registerFails: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertStringContainsString('could not be checked', $event->getErrors()['message']);
	}//end testAnUnreadableRegisterRefusesThePattern()

	/**
	 * The listener over mappers that answer these slugs.
	 *
	 * @param string $schemaSlug    The schema slug.
	 * @param string $registerSlug  The register slug.
	 * @param bool   $registerFails Whether the register lookup throws.
	 *
	 * @return DetectionPatternValidationListener The listener.
	 */
	private function listener(string $schemaSlug = 'detectionPattern', string $registerSlug = 'redaction-policy', bool $registerFails = false): DetectionPatternValidationListener {
		$schema = new Schema();
		$schema->setSlug($schemaSlug);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);

		$register = new Register();
		$register->setSlug($registerSlug);
		$registers = $this->createMock(RegisterMapperInterface::class);
		if ($registerFails === true) {
			$registers->method('find')->willThrowException(new RuntimeException('database gone'));
		} else {
			$registers->method('find')->willReturn($register);
		}

		return new DetectionPatternValidationListener(schemas: $schemas, registers: $registers);
	}//end listener()

	/**
	 * An object in register 3, schema 7 with this data.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setRegister('3');
		$object->setSchema('7');
		$object->setObject($data);

		return $object;
	}//end object()
}//end class
