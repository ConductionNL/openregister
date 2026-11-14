<?php

/**
 * GeneratedIdentifierListener: a random sequence issues codes under the counter's lock.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-sequence-may-be-random-rather-than-counted-req-gid-004
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Listener\GeneratedIdentifierListener;
use OCA\OpenRegister\Service\Object\ObjectEventGate;
use OCA\OpenRegister\Service\Schemas\RandomSequenceCode;
use OCA\OpenRegister\Service\SequenceService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-GID-004: a random sequence draws its number from the same counter and
 * shows it as a code keyed by the instance secret and the sequence name.
 *
 * @covers \OCA\OpenRegister\Listener\GeneratedIdentifierListener
 * @uses   \OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration
 * @uses   \OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclarationReader
 * @uses   \OCA\OpenRegister\Service\Schemas\RandomSequenceCode
 * @uses   \OCA\OpenRegister\Service\Object\ObjectEventGate
 * @uses   \OCA\OpenRegister\Service\SequenceScope
 * @uses   \OCA\OpenRegister\Listener\GeneratedIdentifierIssueRun
 * @uses   \OCA\OpenRegister\Event\ObjectCreatingEvent
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Event\AbstractObjectEntityEvent
 * @uses   \OCA\OpenRegister\Event\WriteVetoState
 */
final class GeneratedIdentifierRandomTest extends TestCase {

	/**
	 * @var SequenceService&MockObject
	 */
	private $sequences;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private $schemaMapper;

	/**
	 * Build the listener over doubles, with this instance secret.
	 *
	 * @param string $secret The instance secret.
	 *
	 * @return GeneratedIdentifierListener
	 */
	private function listener(string $secret): GeneratedIdentifierListener {
		$this->sequences    = $this->createMock(originalClassName: SequenceService::class);
		$this->schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getSystemValueString')->with('secret', '')->willReturn($secret);

		$schema = $this->createMock(originalClassName: Schema::class);
		$schema->method('getProperties')->willReturn(
			[
				'identifier' => [
					'type' => 'string',
					'x-openregister-generated' => ['sequence' => 'case', 'format' => 'Z-{seq}', 'kind' => 'random', 'length' => 8, 'alphabet' => '0123456789'],
				],
			]
		);
		$this->schemaMapper->method('find')->willReturn($schema);

		return new GeneratedIdentifierListener(
			$this->schemaMapper,
			$this->sequences,
			$this->createMock(originalClassName: LoggerInterface::class),
			new ObjectEventGate($this->createMock(originalClassName: IEventDispatcher::class)),
			$config
		);
	}//end listener()

	/**
	 * Create an object.
	 *
	 * @param GeneratedIdentifierListener $listener The listener.
	 *
	 * @return array{0: array<string, mixed>, 1: ObjectCreatingEvent}
	 */
	private function create(GeneratedIdentifierListener $listener): array {
		$object = new ObjectEntity();
		$object->setSchema('777');
		$object->setObject(['title' => 'A case']);
		$event = new ObjectCreatingEvent($object);
		$listener->handle($event);

		return [$object->getObject(), $event];
	}//end create()

	/**
	 * Each create takes the next counter value and shows it as its keyed code.
	 *
	 * @return void
	 */
	public function testEachCreateGetsTheCodeOfTheNextNumber(): void {
		$listener = $this->listener(secret: 'instance-secret');
		$this->sequences->expects($this->exactly(count: 3))->method('reserveNext')->willReturnOnConsecutiveCalls(1, 2, 3);
		$key = hash_hmac('sha256', 'openregister-generated-identifier:case', 'instance-secret');
		$code = new RandomSequenceCode(alphabet: '0123456789', length: 8, key: $key);

		$issued = [];
		for ($i = 0; $i < 3; $i++) {
			[$data] = $this->create(listener: $listener);
			$issued[] = $data['identifier'];
		}

		$this->assertSame(expected: ['Z-'.$code->encode(number: 1), 'Z-'.$code->encode(number: 2), 'Z-'.$code->encode(number: 3)], actual: $issued);
		$this->assertCount(expectedCount: 3, haystack: array_unique($issued));
	}//end testEachCreateGetsTheCodeOfTheNextNumber()

	/**
	 * Without an instance secret no random code is issued and the create is refused.
	 *
	 * @return void
	 */
	public function testWithoutASecretTheCreateIsRefused(): void {
		$listener = $this->listener(secret: '');
		$this->sequences->method('reserveNext')->willReturn(1);

		[$data, $event] = $this->create(listener: $listener);

		$this->assertArrayNotHasKey(key: 'identifier', array: $data);
		$this->assertSame(expected: 'generated-identifier-unavailable', actual: $event->getErrors()['code'] ?? null);
	}//end testWithoutASecretTheCreateIsRefused()
}//end class
