<?php

/**
 * What AnonymousEnvelope takes out of an output, and what it leaves.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use JsonSerializable;
use OCA\OpenRegister\Service\Rbac\AnonymousEnvelope;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\AnonymousEnvelope
 */
class AnonymousEnvelopeTest extends TestCase {

	/**
	 * Every field that names a person leaves, and the fields around it stay, in their order.
	 *
	 * @return void
	 */
	public function testAnEnvelopeLosesItsPeopleOnly(): void {
		$redacted = (new AnonymousEnvelope())->redact(
			data: [
				'title' => 'kept',
				'owner' => 'a body property of that name is not the envelope',
				'@self' => [
					'id'            => 'obj-1',
					'owner'         => 'alice',
					'organisation'  => 'org-1',
					'authorization' => ['read' => ['user:alice']],
					'groups'        => ['g1'],
					'retention'     => ['legalHold' => ['placedBy' => 'alice']],
					'mail'          => ['1/2'],
					'contacts'      => ['c'],
					'notes'         => ['n'],
					'todos'         => ['t'],
					'calendar'      => ['e'],
					'talk'          => ['r'],
					'deck'          => ['d'],
					'locked'        => ['kind' => 'user', 'user' => 'alice', 'duration' => 60],
					'deleted'       => ['deleted' => 'now', 'deletedBy' => 'alice'],
					'archived'      => ['at' => 'now', 'by' => 'alice'],
					'frozen'        => ['state' => 'x', 'by' => 'alice'],
					'register'      => ['id' => 1, 'owner' => 'alice'],
					'schema'        => ['id' => 2, 'owner' => 'alice'],
				],
			]
		);

		$this->assertSame(
			expected: [
				'title' => 'kept',
				'owner' => 'a body property of that name is not the envelope',
				'@self' => [
					'id'           => 'obj-1',
					'organisation' => 'org-1',
					'groups'       => ['g1'],
					'locked'       => ['kind' => 'user', 'duration' => 60],
					'deleted'      => ['deleted' => 'now'],
					'archived'     => ['at' => 'now'],
					'frozen'       => ['state' => 'x'],
					'register'     => ['id' => 1],
					'schema'       => ['id' => 2],
				],
			],
			actual: $redacted
		);
	}//end testAnEnvelopeLosesItsPeopleOnly()

	/**
	 * A plain-id register, a null lock and an empty field are left as they are.
	 *
	 * @return void
	 */
	public function testScalarFieldsAreNotTouched(): void {
		$self = ['locked' => null, 'register' => '1', 'schema' => 2, 'frozen' => 'x'];

		$this->assertSame(expected: ['@self' => $self], actual: (new AnonymousEnvelope())->redact(data: ['@self' => $self]));
	}//end testScalarFieldsAreNotTouched()

	/**
	 * An envelope is found at any depth, and inside a serializable object.
	 *
	 * @return void
	 */
	public function testEnvelopesAreFoundAtAnyDepth(): void {
		$serializable = new class implements JsonSerializable {
			/**
			 * The object as rendered.
			 *
			 * @return array<string, mixed> The rendering.
			 */
			public function jsonSerialize(): array {
				return ['@self' => ['owner' => 'alice', 'id' => 'obj-2']];
			}//end jsonSerialize()
		};

		$redacted = (new AnonymousEnvelope())->redact(
			data: [
				'results' => [
					$serializable,
					['linked' => ['deep' => ['@self' => ['owner' => 'bob', 'id' => 'obj-3']]]],
				],
				'facets'  => ['@self' => ['owner' => ['buckets' => [['key' => 'alice']]], 'organisation' => ['buckets' => []]]],
			]
		);

		$this->assertSame(
			expected: [
				'results' => [
					['@self' => ['id' => 'obj-2']],
					['linked' => ['deep' => ['@self' => ['id' => 'obj-3']]]],
				],
				'facets'  => ['@self' => ['organisation' => ['buckets' => []]]],
			],
			actual: $redacted
		);
	}//end testEnvelopesAreFoundAtAnyDepth()

	/**
	 * An answer without an envelope encodes to the same bytes, whichever app it came from.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutAnEnvelopeKeepsItsBytes(): void {
		$answer = ['a' => 1.50, 'empty' => [], 'object' => new stdClass(), 'owner' => 'x', 'list' => [null, true, 'é']];

		$this->assertSame(
			expected: json_encode($answer),
			actual: json_encode((new AnonymousEnvelope())->redact(data: $answer))
		);
		$this->assertSame(expected: 'plain', actual: (new AnonymousEnvelope())->redact(data: 'plain'));
	}//end testAnAnswerWithoutAnEnvelopeKeepsItsBytes()
}//end class
