<?php

/**
 * Unit tests for IntegrationCreateIdempotency: one create per Idempotency-Key.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-registry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- PHPUnit fixture properties are named by their type.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Form\FormIdempotencyStore;
use OCA\OpenRegister\Service\Form\IdempotentAnswerStore;
use OCA\OpenRegister\Service\Form\IdempotentRequest;
use OCA\OpenRegister\Service\Integration\IdempotencyConflictException;
use OCA\OpenRegister\Service\Integration\IntegrationCreateIdempotency;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * One create per Idempotency-Key, bound to the user and the payload.
 *
 * @spec openspec/specs/integration-registry/spec.md
 */
class IntegrationCreateIdempotencyTest extends TestCase {

	/**
	 * Remembered entries by scope and key: binding and answer.
	 *
	 * @var array<string, array{binding: string, response: array<mixed>}>
	 */
	private array $entries = [];

	/**
	 * Keys reserved and not yet released.
	 *
	 * @var array<string, true>
	 */
	private array $reserved = [];

	private int $releases = 0;

	private string $uid = 'daan';

	private function store(): FormIdempotencyStore {
		$store = $this->createMock(FormIdempotencyStore::class);
		$store->method('bindingFor')->willReturnCallback(
			fn (string $subject, array $payload): string => hash('sha256', $subject . "\0" . json_encode($payload))
		);
		$store->method('reserve')->willReturnCallback(
			function (string $scope, string $key): void {
				if (isset($this->reserved[$scope . "\0" . $key]) === true) {
					throw new FormSubmitRefusedException('busy', 409);
				}
				$this->reserved[$scope . "\0" . $key] = true;
			}
		);
		$store->method('release')->willReturnCallback(
			function (string $scope, string $key): void {
				unset($this->reserved[$scope . "\0" . $key]);
				$this->releases++;
			}
		);
		$store->method('answerFor')->willReturnCallback(
			function (IdempotentRequest $request): ?array {
				$entry = $this->entries[$request->scope . "\0" . $request->key] ?? null;
				if ($entry === null) {
					return null;
				}
				if ($entry['binding'] !== $request->binding) {
					throw new FormSubmitRefusedException('taken', 409);
				}
				return $entry['response'];
			}
		);
		$store->method('keep')->willReturnCallback(
			function (IdempotentRequest $request, array $response): void {
				$this->entries[$request->scope . "\0" . $request->key] = ['binding' => $request->binding, 'response' => $response];
			}
		);
		return $store;
	}//end store()

	private function service(): IntegrationCreateIdempotency {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function (): IUser {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($this->uid);
				return $user;
			}
		);
		return new IntegrationCreateIdempotency(new IdempotentAnswerStore($this->store()), $session);
	}//end service()

	private function object(string $id = 'obj-1'): ObjectRef {
		return new ObjectRef(register: 'pipelinq', schema: 'client', id: $id);
	}//end object()

	public function testAReplayUnderTheSameKeyGetsTheFirstAnswerAndCreatesNothing(): void {
		$service = $this->service();
		$created = 0;
		$create = function () use (&$created): array {
			$created++;
			return ['id' => 'entry-' . $created];
		};

		$first = $service->run('k-1', 'time-tracker', $this->object(), ['minutes' => 30], $create);
		$second = $service->run('k-1', 'time-tracker', $this->object(), ['minutes' => 30], $create);

		$this->assertSame(1, $created);
		$this->assertSame(['answer' => ['id' => 'entry-1'], 'replayed' => false], $first);
		$this->assertSame(['answer' => ['id' => 'entry-1'], 'replayed' => true], $second);
		$this->assertSame([], $this->reserved);
		$this->assertSame(2, $this->releases);
	}//end testAReplayUnderTheSameKeyGetsTheFirstAnswerAndCreatesNothing()

	public function testWithoutAKeyEveryCreateRuns(): void {
		$service = $this->service();
		$created = 0;
		$create = function () use (&$created): array {
			$created++;
			return ['id' => 'entry-' . $created];
		};

		$service->run('', 'time-tracker', $this->object(), [], $create);
		$result = $service->run('  ', 'time-tracker', $this->object(), [], $create);

		$this->assertSame(2, $created);
		$this->assertFalse($result['replayed']);
		$this->assertSame([], $this->entries);
	}//end testWithoutAKeyEveryCreateRuns()

	public function testAnotherPayloadUnderATakenKeyIsAConflict(): void {
		$service = $this->service();
		$service->run('k-1', 'time-tracker', $this->object(), ['minutes' => 30], fn (): array => ['id' => 'a']);

		$this->expectException(IdempotencyConflictException::class);
		$service->run('k-1', 'time-tracker', $this->object(), ['minutes' => 45], fn (): array => ['id' => 'b']);
	}//end testAnotherPayloadUnderATakenKeyIsAConflict()

	public function testAnotherUserUnderATakenKeyIsAConflict(): void {
		$service = $this->service();
		$service->run('k-1', 'time-tracker', $this->object(), ['minutes' => 30], fn (): array => ['id' => 'a']);

		$this->uid = 'sanne';
		try {
			$service->run('k-1', 'time-tracker', $this->object(), ['minutes' => 30], fn (): array => ['id' => 'b']);
			$this->fail('Another user must not get the first answer.');
		} catch (IdempotencyConflictException $exception) {
			$this->assertStringNotContainsString('daan', $exception->getMessage());
		}
		$this->assertSame([], $this->reserved);
	}//end testAnotherUserUnderATakenKeyIsAConflict()

	public function testAKeyStillRunningIsAConflict(): void {
		$service = $this->service();
		$this->expectException(IdempotencyConflictException::class);

		$service->run(
			'k-1',
			'time-tracker',
			$this->object(),
			[],
			fn (): array => $service->run('k-1', 'time-tracker', $this->object(), [], fn (): array => ['id' => 'inner'])
		);
	}//end testAKeyStillRunningIsAConflict()

	public function testTheSameKeyOnAnotherObjectOrIntegrationIsItsOwnCreate(): void {
		$service = $this->service();
		$created = 0;
		$create = function () use (&$created): array {
			$created++;
			return ['id' => 'entry-' . $created];
		};

		$service->run('k-1', 'time-tracker', $this->object('obj-1'), [], $create);
		$service->run('k-1', 'time-tracker', $this->object('obj-2'), [], $create);
		$service->run('k-1', 'deck', $this->object('obj-1'), [], $create);

		$this->assertSame(3, $created);
	}//end testTheSameKeyOnAnotherObjectOrIntegrationIsItsOwnCreate()

	public function testARunnerWithoutAStoreRunsEveryCreate(): void {
		$service = new IntegrationCreateIdempotency();
		$created = 0;
		$create = function () use (&$created): array {
			$created++;
			return ['id' => 'entry-' . $created];
		};

		$service->run('k-1', 'time-tracker', $this->object(), [], $create);
		$result = $service->run('k-1', 'time-tracker', $this->object(), [], $create);

		$this->assertSame(2, $created);
		$this->assertSame(['answer' => ['id' => 'entry-2'], 'replayed' => false], $result);
	}//end testARunnerWithoutAStoreRunsEveryCreate()

	public function testAFailedCreateRemembersNothingAndReleasesTheKey(): void {
		$service = $this->service();
		try {
			$service->run('k-1', 'time-tracker', $this->object(), [], function (): array {
				throw new \RuntimeException('upstream down');
			});
		} catch (\RuntimeException) {
			// Expected: the failure reaches the caller.
		}

		$this->assertSame([], $this->entries);
		$this->assertSame([], $this->reserved);
		$result = $service->run('k-1', 'time-tracker', $this->object(), [], fn (): array => ['id' => 'retry']);
		$this->assertSame(['answer' => ['id' => 'retry'], 'replayed' => false], $result);
	}//end testAFailedCreateRemembersNothingAndReleasesTheKey()
}//end class
