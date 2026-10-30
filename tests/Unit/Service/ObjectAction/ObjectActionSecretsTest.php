<?php

/**
 * Held writeOnly inputs are redacted by key in stored run data, and found
 * in free text and flow state.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Unit\Service\ObjectAction;

use OCA\OpenRegister\Service\Flow\FlowRunCommit;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionSecrets;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\ObjectAction\ObjectActionSecrets
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunCommit
 */
class ObjectActionSecretsTest extends TestCase {

	private const UUID = '2026aaaa-1111-4111-8111-000000002026';

	/**
	 * The stored per-place items lose the named input only.
	 *
	 * @return void
	 */
	public function testStoredPlaceItemsRedactTheInputAndKeepTheSubject(): void {
		$ref = ObjectActionSecrets::hold(values: ['pin' => '2026']);
		try {
			$run = new \OCA\OpenRegister\Db\FlowRun();
			$run->setContext([ObjectActionSecrets::CONTEXT_KEY => $ref]);
			$items = ['hop' => [['json' => ['uuid' => self::UUID, 'code' => '2026', 'inputs' => ['pin' => '2026', 'reason' => 'ok']], 'binary' => []]]];

			$commit = (new ReflectionClass(objectOrClass: FlowRunCommit::class))->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod(objectOrMethod: FlowRunCommit::class, method: 'storedPlaceItems');
			$stored = $method->invoke($commit, $run, $items);
		} finally {
			ObjectActionSecrets::release(ref: $ref);
		}

		$json = $stored['hop'][0]['json'];
		$this->assertSame(expected: self::UUID, actual: $json['uuid']);
		$this->assertSame(expected: ObjectActionSecrets::REDACTED, actual: $json['inputs']['pin']);
		$this->assertSame(expected: 'ok', actual: $json['inputs']['reason']);
		$this->assertSame(expected: ObjectActionSecrets::REDACTED, actual: $json['code'], message: 'a copy is redacted too');
	}//end testStoredPlaceItemsRedactTheInputAndKeepTheSubject()

	/**
	 * An error text loses a held value where it stands as a whole token, with
	 * punctuation as a boundary; a run of letters and digits (a uuid) is not cut.
	 *
	 * @return void
	 */
	public function testErrorTextReplacesWholeTokensOnly(): void {
		$ref = ObjectActionSecrets::hold(values: ['pin' => '2026']);
		try {
			$text = ObjectActionSecrets::redactText(text: 'PIN-2026 for user_2026 refused, ref ' . self::UUID, ref: $ref);
		} finally {
			ObjectActionSecrets::release(ref: $ref);
		}

		$this->assertSame(expected: 'PIN-[redacted] for user_[redacted] refused, ref ' . self::UUID, actual: $text);
	}//end testErrorTextReplacesWholeTokensOnly()

	/**
	 * A whole-value copy is redacted (a number too); timestamps, uuids and a short secret are not touched.
	 *
	 * @return void
	 */
	public function testCopiesAreRedactedWholeStringsOnly(): void {
		$ref = ObjectActionSecrets::hold(values: ['pin' => '2026', 'digit' => '1']);
		try {
			$data = ObjectActionSecrets::redactCopies(
				data: ['json' => ['code' => '2026', 'created' => '2026-10-30T10:00:00+00:00', 'uuid' => self::UUID, 'n' => '1', 'count' => 2026]],
				ref: $ref
			);
		} finally {
			ObjectActionSecrets::release(ref: $ref);
		}

		$this->assertSame(
			expected: ['code' => '[redacted]', 'created' => '2026-10-30T10:00:00+00:00', 'uuid' => self::UUID, 'n' => '1', 'count' => '[redacted]'],
			actual: $data['json']
		);
	}//end testCopiesAreRedactedWholeStringsOnly()

	/**
	 * A numeric input copied as a number is redacted; a short counter is not.
	 *
	 * @return void
	 */
	public function testNumericCopiesAreRedacted(): void {
		$ref = ObjectActionSecrets::hold(values: ['n' => 123456, 'digit' => 1]);
		try {
			$data = ObjectActionSecrets::redactCopies(data: ['num' => 123456, 'float' => 123456.0, 'count' => 1, 'text' => '123456'], ref: $ref);
		} finally {
			ObjectActionSecrets::release(ref: $ref);
		}

		$this->assertSame(expected: ['num' => '[redacted]', 'float' => '[redacted]', 'count' => 1, 'text' => '[redacted]'], actual: $data);
	}//end testNumericCopiesAreRedacted()

	/**
	 * Flow state keeps its other values; only a copied secret is replaced.
	 *
	 * @return void
	 */
	public function testFlowStateIsRedactedValueByValue(): void {
		$stored = null;
		$states = $this->createMock(originalClassName: \OCA\OpenRegister\Db\FlowStateMapper::class);
		$states->method('put')->willReturnCallback(
			static function (string $flowId, array $state) use (&$stored): \OCA\OpenRegister\Db\FlowState {
				$stored = $state;
				return new \OCA\OpenRegister\Db\FlowState();
			}
		);
		$service = new \OCA\OpenRegister\Service\Flow\FlowRunService(
			$this->createMock(originalClassName: \OCA\OpenRegister\Db\FlowRunMapper::class),
			$states,
			$this->createMock(originalClassName: \OCA\OpenRegister\Service\Flow\FlowEngine::class),
			$this->createMock(originalClassName: \OCA\OpenRegister\Service\Flow\FlowNodeRegistry::class),
			$this->createMock(originalClassName: \Psr\Log\LoggerInterface::class),
			$this->createMock(originalClassName: \Psr\Container\ContainerInterface::class)
		);
		$handle = new \OCA\OpenRegister\Service\Flow\FlowStateHandle(values: []);
		$handle->set(key: 'cursor', value: '1');
		$handle->set(key: 'since', value: '2026-10-30T10:00:00+00:00');
		$handle->set(key: 'lastCode', value: '2026');
		$run = new \OCA\OpenRegister\Db\FlowRun();
		$run->setFlowId('f1');

		$ref = ObjectActionSecrets::hold(values: ['pin' => '2026', 'digit' => '1']);
		try {
			$method = new \ReflectionMethod(objectOrMethod: \OCA\OpenRegister\Service\Flow\FlowRunService::class, method: 'persistFlowState');
			$method->invoke($service, $run, [\OCA\OpenRegister\Service\Flow\FlowStateHandle::CONTEXT_KEY => $handle], $ref);
		} finally {
			ObjectActionSecrets::release(ref: $ref);
		}

		$this->assertSame(
			expected: ['cursor' => '1', 'since' => '2026-10-30T10:00:00+00:00', 'lastCode' => '[redacted]'],
			actual: $stored
		);
	}//end testFlowStateIsRedactedValueByValue()
}//end class
