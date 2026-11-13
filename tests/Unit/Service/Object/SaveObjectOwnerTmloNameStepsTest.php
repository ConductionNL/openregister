<?php

/**
 * Golden behaviour of SaveObject's owner-claim refusal, TMLO checks and parent-name pre-cache.
 *
 * Pins allowed and refused owner claims; when TMLO defaults are stamped and
 * which stored values refuse them; which TMLO updates are refused, merged or
 * ignored; and which parent name (if any) is pre-cached, with its organisation.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\CascadeRun;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\SaveObject\SaveLookups;
use OCA\OpenRegister\Service\TmloService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionClass;
use Stringable;

/**
 * Owner, TMLO and name-cache steps, golden.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveLookups
 * @uses \OCA\OpenRegister\Db\RegisterSchema
 */
final class SaveObjectOwnerTmloNameStepsTest extends TestCase {

	/**
	 * Every log record, as [level, message, uuid, error].
	 *
	 * @var array<int, array{0: mixed, 1: string, 2: mixed, 3: mixed}>
	 */
	private array $records = [];

	/**
	 * Every TMLO service call, as [method, argument].
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $tmloCalls = [];

	/**
	 * Every cached name, as [identifier, name, organisation].
	 *
	 * @var array<int, array{0: mixed, 1: string, 2: mixed}>
	 */
	private array $names = [];

	/**
	 * A constructorless save handler with the collaborators these steps use.
	 *
	 * @param array $config `actor` (uid|null), `tmloEnabled`, `fieldErrors`, `transitionErrors`, `registerFails`, `hydrationFails`.
	 *
	 * @return SaveObject The handler.
	 */
	private function handler(array $config = []): SaveObject {
		$config += ['actor' => 'alice', 'tmloEnabled' => true, 'fieldErrors' => [], 'transitionErrors' => [], 'registerFails' => false, 'hydrationFails' => false];
		$handler = $this->getMockBuilder(SaveObject::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
		$reflection = new ReflectionClass(SaveObject::class);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($config['actor'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($config['actor']);
		}

		$session->method('getUser')->willReturn($user);
		$reflection->getProperty('userSession')->setValue($handler, $session);

		$tmlo = $this->createMock(TmloService::class);
		$tmlo->method('isTmloEnabled')->willReturnCallback(fn (Register $r): bool => $this->track('isTmloEnabled', $r->getId(), $config['tmloEnabled']));
		$tmlo->method('validateFieldValues')->willReturnCallback(fn (array $t): array => $this->track('validateFieldValues', $t, $config['fieldErrors']));
		$tmlo->method('validateStatusTransition')->willReturnCallback(fn (array $t, string $old): array => $this->track('validateStatusTransition', [$t, $old], $config['transitionErrors']));
		$tmlo->method('populateDefaults')->willReturnCallback(fn (ObjectEntity $o): ObjectEntity => $this->track('populateDefaults', $o->getUuid(), $o));
		$reflection->getProperty('tmloService')->setValue($handler, $tmlo);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$register = new Register();
		$register->setId(10);
		if ($config['registerFails'] === true) {
			$registers->method('find')->willThrowException(new Exception('register gone'));
		} else {
			$registers->method('find')->willReturn($register);
		}

		$reflection->getProperty('lookups')->setValue($handler, new SaveLookups(schemaMapper: $this->createMock(SchemaMapperInterface::class), registerMapper: $registers));

		$hydration = $this->createMock(MetadataHydrationHandler::class);
		if ($config['hydrationFails'] === true) {
			$hydration->method('hydrateObjectMetadata')->willThrowException(new Exception('no name field'));
		}

		$reflection->getProperty('metaHydrationHandler')->setValue($handler, $hydration);
		$cache = $this->createMock(CacheHandler::class);
		$cache->method('setObjectName')->willReturnCallback(
			function ($identifier, string $name, ?string $organisation = null): void {
				$this->names[] = [$identifier, $name, $organisation];
			}
		);
		$reflection->getProperty('cacheHandler')->setValue($handler, $cache);
		$logger = new class ($this->records) extends AbstractLogger {
			/**
			 * @param array $records The test's record list.
			 */
			public function __construct(private array &$records) {
			}

			/**
			 * @param mixed             $level   The level.
			 * @param string|Stringable $message The message.
			 * @param array             $context The context.
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->records[] = [$level, (string) $message, $context['uuid'] ?? null, $context['error'] ?? null];
			}
		};
		$reflection->getProperty('logger')->setValue($handler, $logger);

		return $handler;
	}//end handler()

	/**
	 * Record a TMLO call and answer with the given value.
	 *
	 * @param string $method The method.
	 * @param mixed  $arg    Its argument.
	 * @param mixed  $answer What it answers.
	 *
	 * @return mixed The answer.
	 */
	private function track(string $method, mixed $arg, mixed $answer): mixed {
		$this->tmloCalls[] = [$method, $arg];

		return $answer;
	}//end track()

	/**
	 * Invoke a private SaveObject method.
	 *
	 * @param SaveObject $handler The handler.
	 * @param string     $method  The method.
	 * @param array      $args    Its arguments.
	 *
	 * @return mixed What it answers.
	 */
	private static function call(SaveObject $handler, string $method, array $args): mixed {
		return (new ReflectionClass(SaveObject::class))->getMethod($method)->invoke($handler, ...$args);
	}//end call()

	/**
	 * An object owned by `bob`, in register 10, with the given TMLO.
	 *
	 * @param array|null $tmlo The stored TMLO.
	 *
	 * @return ObjectEntity The object.
	 */
	private static function object(?array $tmlo = null): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('o-1');
		$object->setOwner('bob');
		$object->setRegister('10');
		$object->setOrganisation('org-1');
		if ($tmlo !== null) {
			$object->setTmlo($tmlo);
		}

		return $object;
	}//end object()

	/**
	 * Whether the call throws, and with which class and message.
	 *
	 * @param callable $call The call.
	 *
	 * @return string|null `Class: message`, or null.
	 */
	private static function refusal(callable $call): ?string {
		try {
			$call();
		} catch (Exception $e) {
			return (new ReflectionClass($e))->getShortName() . ': ' . $e->getMessage();
		}

		return null;
	}//end refusal()

	/**
	 * The owner claims that pass and the ones refused.
	 *
	 * @return void
	 */
	public function testOwnerClaimsAllowedAndRefused(): void {
		$refused = 'NotAuthorizedException: An owner cannot be set through a save. Use the ownership endpoint to hand a record over.';
		$cases = [
			'no owner key' => [[], 'alice', null, 'bob'],
			'null owner' => [['owner' => null], 'alice', null, 'bob'],
			'empty owner' => [['owner' => ''], 'alice', null, 'bob'],
			'expanded owner object' => [['owner' => ['uid' => 'carol']], 'alice', null, 'bob'],
			'no session user' => [['owner' => 'carol'], null, null, 'bob'],
			'the acting user' => [['owner' => 'alice'], 'alice', null, 'bob'],
			'the stored owner' => [['owner' => 'bob'], 'alice', null, 'bob'],
			'someone else' => [['owner' => 'carol'], 'alice', $refused, 'bob'],
			'empty stored owner' => [['owner' => ''], 'alice', null, ''],
			'claim against empty stored owner' => [['owner' => 'carol'], 'alice', $refused, ''],
		];
		foreach ($cases as $name => [$self, $actor, $expected, $stored]) {
			$object = self::object();
			$object->setOwner($stored);
			$outcome = self::refusal(fn () => self::call($this->handler(['actor' => $actor]), 'refuseOwnerClaim', [$object, $self]));
			$this->assertSame($expected, $outcome, $name);
		}
	}//end testOwnerClaimsAllowedAndRefused()

	/**
	 * TMLO defaults are stamped only for a resolvable, TMLO-enabled register, after the stored values validate.
	 *
	 * @return void
	 */
	public function testTmloDefaultsAreStampedOnlyWhenEnabled(): void {
		$schema = new Schema();
		$noRegister = self::object();
		$noRegister->setRegister(null);
		$cases = [
			'no register' => [$noRegister, [], [], null],
			'register gone' => [self::object(), ['registerFails' => true], [], null],
			'disabled' => [self::object(), ['tmloEnabled' => false], [['isTmloEnabled', 10]], null],
			'enabled, nothing stored' => [self::object(), [], [['isTmloEnabled', 10], ['populateDefaults', 'o-1']], null],
			'enabled, empty stored' => [self::object(tmlo: []), [], [['isTmloEnabled', 10], ['populateDefaults', 'o-1']], null],
			'enabled, valid stored' => [self::object(tmlo: ['archiefstatus' => 'actief']), [], [['isTmloEnabled', 10], ['validateFieldValues', ['archiefstatus' => 'actief']], ['populateDefaults', 'o-1']], null],
			'enabled, invalid stored' => [self::object(tmlo: ['archiefstatus' => 'x']), ['fieldErrors' => ['bad status', 'bad date']], [['isTmloEnabled', 10], ['validateFieldValues', ['archiefstatus' => 'x']]], 'Exception: TMLO validation failed: bad status; bad date'],
		];
		foreach ($cases as $name => [$object, $config, $calls, $expected]) {
			$this->tmloCalls = [];
			$outcome = self::refusal(fn () => self::call($this->handler($config), 'populateTmloDefaults', [$object, $schema]));
			$this->assertSame($expected, $outcome, $name);
			$this->assertSame($calls, $this->tmloCalls, $name);
		}
	}//end testTmloDefaultsAreStampedOnlyWhenEnabled()

	/**
	 * TMLO on update: ignored without a tmlo array, refused on bad fields or a bad transition, else merged.
	 *
	 * @return void
	 */
	public function testTmloUpdatesAreValidatedThenMerged(): void {
		$stored = ['archiefstatus' => 'actief', 'bewaartermijn' => 'P5Y'];
		$cases = [
			'no tmlo' => [[], [], [], null, $stored],
			'tmlo not an array' => [['tmlo' => 'x'], [], [], null, $stored],
			'bad fields' => [['tmlo' => ['bewaartermijn' => 'x']], ['fieldErrors' => ['bad term']], [['validateFieldValues', ['bewaartermijn' => 'x']]], 'Exception: TMLO validation failed: bad term', $stored],
			'no status change' => [['tmlo' => ['bewaartermijn' => 'P7Y']], [], [['validateFieldValues', ['bewaartermijn' => 'P7Y']]], null, ['archiefstatus' => 'actief', 'bewaartermijn' => 'P7Y']],
			'same status' => [['tmlo' => ['archiefstatus' => 'actief']], [], [['validateFieldValues', ['archiefstatus' => 'actief']]], null, $stored],
			'allowed change' => [['tmlo' => ['archiefstatus' => 'semi-statisch']], [], [['validateFieldValues', ['archiefstatus' => 'semi-statisch']], ['validateStatusTransition', [['archiefstatus' => 'semi-statisch', 'bewaartermijn' => 'P5Y'], 'actief']]], null, ['archiefstatus' => 'semi-statisch', 'bewaartermijn' => 'P5Y']],
			'refused change' => [['tmlo' => ['archiefstatus' => 'vernietigd']], ['transitionErrors' => ['not allowed', 'too early']], [['validateFieldValues', ['archiefstatus' => 'vernietigd']], ['validateStatusTransition', [['archiefstatus' => 'vernietigd', 'bewaartermijn' => 'P5Y'], 'actief']]], 'Exception: TMLO status transition failed: not allowed; too early', $stored],
		];
		foreach ($cases as $name => [$self, $config, $calls, $expected, $after]) {
			$this->tmloCalls = [];
			$object = self::object(tmlo: $stored);
			$outcome = self::refusal(fn () => self::call($this->handler($config), 'validateTmloOnUpdate', [$object, $self]));
			$this->assertSame($expected, $outcome, $name);
			$this->assertSame($calls, $this->tmloCalls, $name);
			$this->assertSame($after, $object->getTmlo(), $name);
		}

		$this->tmloCalls = [];
		$fresh = self::object();
		self::call($this->handler(), 'validateTmloOnUpdate', [$fresh, ['tmlo' => ['archiefstatus' => 'semi-statisch']]]);
		$this->assertSame([['validateFieldValues', ['archiefstatus' => 'semi-statisch']], ['validateStatusTransition', [['archiefstatus' => 'semi-statisch'], 'actief']]], $this->tmloCalls, 'no stored tmlo starts from actief');
	}//end testTmloUpdatesAreValidatedThenMerged()

	/**
	 * The hydrated name is cached as is; a blank one falls back to the trimmed `naam`; nothing else is cached.
	 *
	 * @return void
	 */
	public function testTheParentNameIsPreCachedWithItsOrganisation(): void {
		$cases = [
			'hydrated name' => [' Case 1 ', ['naam' => 'ignored'], [['o-1', ' Case 1 ', 'org-1']]],
			'blank name, naam' => ['  ', ['naam' => '  Zaak 7 '], [['o-1', 'Zaak 7', 'org-1']]],
			'no name, naam' => [null, ['naam' => 12], [['o-1', '12', 'org-1']]],
			'no name, blank naam' => [null, ['naam' => '   '], []],
			'no name, no naam' => [null, ['title' => 'x'], []],
		];
		foreach ($cases as $name => [$hydrated, $data, $expected]) {
			$this->names = [];
			$object = self::object();
			$object->setName($hydrated);
			$run = new CascadeRun();
			$run->parent = $object;
			$run->data = $data;
			self::call($this->handler(), 'preCacheParentName', [$run, new Schema()]);
			$this->assertSame($expected, $this->names, $name);
			$this->assertSame($data, array_diff_key($object->getObject(), ['id' => true]), $name);
		}
	}//end testTheParentNameIsPreCachedWithItsOrganisation()

	/**
	 * A parent without uuid caches nothing; a failed hydration is a debug record and caches nothing.
	 *
	 * @return void
	 */
	public function testNoUuidOrAFailedHydrationCachesNothing(): void {
		$object = self::object();
		$object->setUuid(null);
		$object->setName('Case');
		$run = new CascadeRun();
		$run->parent = $object;
		$run->data = ['naam' => 'Zaak'];
		self::call($this->handler(), 'preCacheParentName', [$run, new Schema()]);
		$this->assertSame([[], []], [$this->names, $this->records]);
		$this->assertArrayNotHasKey('naam', $object->getObject());

		$object = self::object();
		$object->setName('Case');
		$run->parent = $object;
		self::call($this->handler(['hydrationFails' => true]), 'preCacheParentName', [$run, new Schema()]);
		$this->assertSame([], $this->names);
		$this->assertSame([['debug', '[SaveObject] Pre-cache name hydration failed, sub-objects may show UUID names', 'o-1', 'no name field']], $this->records);
	}//end testNoUuidOrAFailedHydrationCachesNothing()
}//end class
