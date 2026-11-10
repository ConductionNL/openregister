<?php

/**
 * Golden for CorrectionService::correct
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Object
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\CorrectionService;
use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use OCA\OpenRegister\Tests\Support\AuditTrailEvents;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * What a correction checks, in which order, what it saves and how it records the act.
 * Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\CorrectionService
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses   \OCA\OpenRegister\Exception\CorrectionRefusedException
 */
class CorrectionServiceGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/correction-service-golden.json';

	/**
	 * Every call the service made, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * Run one correction in a world shaped by the options, and describe what happened.
	 *
	 * @param array $o Options: user, refuse, lastLog, sealed, findFails, updateFails, createFails, values, reason.
	 *
	 * @return array
	 */
	private function correctIn(array $o): array {
		$this->trace = [];
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if (($o['user'] ?? true) === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}

		$session->method('getUser')->willReturn($user);
		$auth = $this->createMock(OpenRegisterActionAuthService::class);
		$auth->method('requireAction')->willReturnCallback(
			function ($user, string $action) use ($o): void {
				$this->trace[] = ['requireAction', $action];
				if (($o['refuse'] ?? false) === true) {
					throw new RuntimeException('no right', 7);
				}
			}
		);

		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('setRegister')->willReturnCallback(fn ($r) => $this->note(['setRegister', $r], $objects));
		$objects->method('setSchema')->willReturnCallback(fn ($s) => $this->note(['setSchema', $s], $objects));
		$objects->method('findSilent')->willReturnCallback(
			function (...$args) use ($o): ObjectEntity {
				$this->trace[] = ['findSilent', FindCallShape::findSilent(args: $args)];
				$existing = new ObjectEntity();
				$existing->setUuid('u-1');
				$existing->setObject(['name' => 'old', 'age' => 3]);
				return $existing;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (...$args) use ($o): ObjectEntity {
				$this->trace[] = ['saveObject', $args];
				$saved = new ObjectEntity();
				$saved->setUuid('u-1');
				$saved->setObject($args['object'] ?? $args[0]);
				if (array_key_exists('lastLog', $o) === true) {
					$saved->setLastLog($o['lastLog']);
				}

				return $saved;
			}
		);

		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->method('find')->willReturnCallback(
			function (int $id) use ($o): AuditTrail {
				$this->trace[] = ['find', $id];
				if (($o['findFails'] ?? false) === true) {
					throw new RuntimeException('gone');
				}

				$entry = new AuditTrail();
				$entry->setId($id);
				$entry->setAction('update');
				$entry->setChanged(['name' => ['old' => 'old', 'new' => 'new']]);
				if (($o['sealed'] ?? false) === true) {
					$entry->setHash('h');
				}

				return $entry;
			}
		);
		$audit->method('update')->willReturnCallback(
			function (AuditTrail $entry) use ($o): AuditTrail {
				$this->trace[] = ['update', $entry->getAction(), $entry->getChanged()];
				if (($o['updateFails'] ?? false) === true) {
					throw new RuntimeException('locked');
				}

				return $entry;
			}
		);
		$audit->method('recordObjectEvent')->willReturnCallback(AuditTrailEvents::positional(
			function ($object, $action, $context, $actorId) use ($o): AuditTrail {
				$this->trace[] = ['create', $object->getUuid(), $action, $context, $actorId];
				if (($o['createFails'] ?? false) === true) {
					throw new RuntimeException('disk');
				}

				$entry = new AuditTrail();
				$entry->setAction((string)$action);
				return $entry;
			}
		));

		$trace = &$this->trace;
		$logger = new class($trace) extends AbstractLogger {
			public function __construct(private array &$trace) {
			}

			public function log($level, \Stringable|string $message, array $context=[]): void {
				unset($context['file'], $context['line']);
				$this->trace[] = ['log', $level, (string)$message, $context];
			}
		};

		$service = new CorrectionService(objectService: $objects, actionAuth: $auth, userSession: $session, auditing: new \OCA\OpenRegister\Service\Object\CorrectionAuditing(auditTrailMapper: $audit, logger: $logger));
		try {
			$result = $service->correct(new \OCA\OpenRegister\Service\Object\CorrectionTarget('reg', 'sch', 'id-1'), $o['values'] ?? ['name' => 'new', '@self' => 1, '_x' => 1, 'id' => 1, 'uuid' => 1, 'register' => 1, 'schema' => 1, 0 => 'zero'], (array_key_exists('reason', $o) === true ? $o['reason'] : '  typo '));
			$outcome = ['object' => $result['object']->getObject(), 'audit' => $result['audit']?->getAction(), 'changed' => $result['audit']?->getChanged()];
		} catch (\Throwable $e) {
			$outcome = ['threw' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode(), 'previous' => $e->getPrevious()?->getMessage()];
		}

		return ['outcome' => $outcome, 'trace' => $this->trace];
	}//end correctIn()

	/**
	 * Record a call and answer with the mock, as the fluent setters do.
	 *
	 * @param array $call The call.
	 * @param mixed $mock The mock to return.
	 *
	 * @return mixed
	 */
	private function note(array $call, mixed $mock): mixed {
		$this->trace[] = $call;
		return $mock;
	}//end note()

	public function testEveryPathMatchesTheGolden(): void {
		$cases = [
			'signed-out'         => ['user' => false, 'refuse' => true, 'reason' => ''],
			'no-right'           => ['refuse' => true, 'reason' => ''],
			'no-reason'          => ['reason' => " \t"],
			'null-reason'        => ['reason' => null, 'values' => ['a' => 1]],
			'nothing-correctable' => ['values' => ['@self' => [], '_t' => 1, 'id' => 2]],
			'amended'            => ['lastLog' => ['id' => '12']],
			'sealed'             => ['lastLog' => ['id' => 12], 'sealed' => true],
			'no-last-log'        => [],
			'non-numeric-log'    => ['lastLog' => ['id' => 'x']],
			'find-fails'         => ['lastLog' => ['id' => 5], 'findFails' => true],
			'update-fails'       => ['lastLog' => ['id' => 5], 'updateFails' => true],
			'create-fails'       => ['createFails' => true],
		];
		$actual = [];
		foreach ($cases as $name => $options) {
			$actual[$name] = $this->correctIn(o: $options);
		}

		$actual = json_decode((string) json_encode($actual), true);
		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(expected: json_decode((string) file_get_contents(self::FIXTURE), true), actual: $actual);
	}//end testEveryPathMatchesTheGolden()
}//end class
