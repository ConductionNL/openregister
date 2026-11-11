<?php

/**
 * Golden record of AuditTrailMapper::buildEntry() over the actions and states it distinguishes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail\AuditedChange;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper
 * @uses \OCA\OpenRegister\Db\AuditTrail
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\AuditFlowAttribution
 * @uses \OCA\OpenRegister\Db\AuditTrailPayloadHelper
 * @uses \OCA\OpenRegister\Service\Audit\PurposeAttribution
 * @uses \OCA\OpenRegister\Service\Audit\TokenAttribution
 * @uses \OCA\OpenRegister\Service\WriteCause
 * @uses \OCA\OpenRegister\Service\Audit\OrganisationContextAttribution
 * @uses \OCA\OpenRegister\Service\OrganisationContext
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
class AuditTrailMapperBuildGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/audit-trail-build-golden.json';

	/**
	 * A stored object.
	 *
	 * @param array<string, mixed> $data Its data.
	 *
	 * @return ObjectEntity
	 */
	private static function object(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId(7);
		$object->setUuid('u-7');
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setObject($data);
		$object->setVersion('1.0.' . count($data));
		return $object;
	}//end object()

	/**
	 * The cases: old data, new data, action, cascade context, signed-in user.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$a = ['title' => 'A', 'status' => 'open'];
		$b = ['title' => 'B', 'status' => 'open', 'note' => 'x'];
		$cascade = ['triggerObject' => 't-1', 'triggerSchema' => 's-1', 'property' => 'parent'];
		$cases = [
			'create inferred' => ['old' => null, 'new' => $b],
			'update inferred' => ['old' => $a, 'new' => $b],
			'update removes a field' => ['old' => $b, 'new' => $a],
			'delete inferred' => ['old' => $a, 'new' => null],
			'explicit update without old' => ['old' => null, 'new' => $b, 'action' => 'update'],
			'explicit create with old' => ['old' => $a, 'new' => $b, 'action' => 'create'],
			'explicit delete with both' => ['old' => $a, 'new' => $b, 'action' => 'delete'],
			'read' => ['old' => $a, 'new' => $a, 'action' => 'read'],
			'referential cascade delete' => ['old' => $a, 'new' => null, 'action' => 'referential_integrity.cascade_delete', 'cascade' => $cascade],
			'referential set null with new' => ['old' => $a, 'new' => $b, 'action' => 'referential_integrity.set_null'],
			'cascade context on update' => ['old' => $a, 'new' => $b, 'cascade' => ['triggerObject' => 't-2']],
			'cascade context default action type' => ['old' => $a, 'new' => null, 'cascade' => []],
			'same data update' => ['old' => $a, 'new' => $a],
			'signed in user' => ['old' => $a, 'new' => $b, 'user' => 'alice'],
			'custom action' => ['old' => $a, 'new' => $b, 'action' => 'publish'],
			'read with differing data' => ['old' => $a, 'new' => $b, 'action' => 'read'],
			'explicit create removing a field' => ['old' => $b, 'new' => $a, 'action' => 'create'],
		];

		$out = [];
		foreach ($cases as $name => $case) {
			$out[$name] = [$case];
		}

		return $out;
	}//end cases()

	/**
	 * Build the mapper with a user session for the given uid, or none.
	 *
	 * @param string|null $uid The signed-in uid.
	 *
	 * @return AuditTrailMapper
	 */
	private function mapper(?string $uid): AuditTrailMapper {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not registered'));

		$request = $this->createMock(IRequest::class);
		$request->method('getId')->willReturn('req-1');
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn(ucfirst($uid));
		}

		$session->method('getUser')->willReturn($user);

		return new AuditTrailMapper($this->createMock(IDBConnection::class), new AuditTrailMapperServices($container, $this->createMock(LoggerInterface::class), $session, $request));
	}//end mapper()

	#[DataProvider('cases')]
	public function testBuildMatchesGolden(array $case): void {
		$trail = $this->mapper(uid: ($case['user'] ?? null))->buildEntry(change: new AuditedChange(
			old: (array_key_exists('old', $case) === true && $case['old'] !== null) ? self::object($case['old']) : null,
			new: (array_key_exists('new', $case) === true && $case['new'] !== null) ? self::object($case['new']) : null,
			action: ($case['action'] ?? null),
			cascadeContext: ($case['cascade'] ?? null)
		));

		$row = $trail->jsonSerialize();
		foreach (['id', 'uuid', 'created', 'session', 'expires', 'hash', 'previousHash'] as $volatile) {
			unset($row[$volatile]);
		}

		$actual = json_decode((string)json_encode($row), true);
		$name = $this->dataName();
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testBuildMatchesGolden()
}//end class
