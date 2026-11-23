<?php

/**
 * The published ObjectServiceInterface names only published values, and
 * openregister accepts a value built under a published name all the way down.
 *
 * A consuming app that moves from `Kernel\Contracts\Value\X` to
 * `Contract\X` (task 7.3) passes the PARENT class. Every parameter that
 * receives it must therefore be typed with the parent, or the call fails with
 * a TypeError deep inside openregister. These tests pin both halves: the
 * contract's own signatures, and the receiving chain behind them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Contract;

use OCA\OpenRegister\Contract;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\Value;
use OCA\OpenRegister\Service\Object\AdvisoryLockStore;
use OCA\OpenRegister\Service\Object\LockAuthorization;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\LockStores;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

class PublishedSignaturesTest extends TestCase {

	/**
	 * The value each contract method takes, by method and parameter name.
	 */
	private const PUBLISHED_PARAMETERS = [
		'saveObject'    => ['options', Contract\ObjectSaveOptions::class],
		'find'          => ['options', Contract\ObjectFindOptions::class],
		'findSilent'    => ['options', Contract\ObjectFindOptions::class],
		'deleteObject'  => ['deletion', Contract\ObjectDeletion::class],
		'saveObjects'   => ['options', Contract\BulkSaveOptions::class],
		'lockObject'    => ['request', Contract\LockRequest::class],
		'unlockObject'  => ['request', Contract\UnlockRequest::class],
		'patchObject'   => ['patch', Contract\ObjectPatch::class],
	];

	private IAppConfig&MockObject $appConfig;

	private MagicMapper&MockObject $magicMapper;

	/**
	 * The class names a type mentions.
	 *
	 * @param ReflectionType|null $type The type.
	 *
	 * @return list<string> The class and interface names in it.
	 */
	private static function classNames(?ReflectionType $type): array {
		if ($type === null) {
			return [];
		}

		$parts = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
		$names = [];
		foreach ($parts as $part) {
			if ($part instanceof ReflectionNamedType && $part->isBuiltin() === false && $part->getName() !== 'static') {
				$names[] = $part->getName();
			}
		}

		return $names;
	}//end classNames()

	/**
	 * Every openregister class the contract's signatures name is published.
	 *
	 * @return void
	 */
	public function testTheContractNamesOnlyPublishedClasses(): void {
		foreach ((new ReflectionClass(ObjectServiceInterface::class))->getMethods() as $method) {
			$types = [$method->getReturnType()];
			foreach ($method->getParameters() as $parameter) {
				$types[] = $parameter->getType();
			}

			foreach ($types as $type) {
				foreach (self::classNames($type) as $name) {
					if (str_starts_with($name, 'OCA\\OpenRegister\\') === false) {
						continue;
					}

					$this->assertSame(
						'OCA\\OpenRegister\\Contract',
						(new ReflectionClass($name))->getNamespaceName(),
						$method->getName() . '() names ' . $name . ', which a consuming app cannot load from the published contract'
					);
				}
			}
		}
	}//end testTheContractNamesOnlyPublishedClasses()

	/**
	 * The contract takes each value under its published name.
	 *
	 * @return void
	 */
	public function testTheContractTakesThePublishedValues(): void {
		foreach (self::PUBLISHED_PARAMETERS as $methodName => [$parameterName, $published]) {
			$parameter = $this->parameter(ObjectServiceInterface::class, $methodName, $parameterName);
			$this->assertSame([$published], self::classNames($parameter->getType()), $methodName . '() takes the published value');
		}

		$this->assertSame(
			[Contract\ObjectValidationVerdict::class],
			self::classNames((new ReflectionMethod(ObjectServiceInterface::class, 'validateObject'))->getReturnType())
		);
	}//end testTheContractTakesThePublishedValues()

	/**
	 * ObjectService accepts the published value wherever the contract does.
	 *
	 * A narrower parameter (the old Kernel name) would be a fatal at class load;
	 * this pins that the implementation is typed with the same parent.
	 *
	 * @return void
	 */
	public function testObjectServiceTakesThePublishedValues(): void {
		foreach (self::PUBLISHED_PARAMETERS as $methodName => [$parameterName, $published]) {
			$parameter = $this->parameter(ObjectService::class, $methodName, $parameterName);
			$this->assertSame([$published], self::classNames($parameter->getType()), 'ObjectService::' . $methodName . '()');
		}
	}//end testObjectServiceTakesThePublishedValues()

	/**
	 * A caller still passing the old name is accepted by the widened parameters.
	 *
	 * @return void
	 */
	public function testTheOldNamesStillFitThePublishedParameters(): void {
		foreach (self::PUBLISHED_PARAMETERS as [, $published]) {
			$old = 'OCA\\OpenRegister\\Kernel\\Contracts\\Value\\' . (new ReflectionClass($published))->getShortName();
			$this->assertTrue(is_subclass_of($old, $published), $old . ' extends ' . $published);
		}

		$this->assertSame(
			Value\ObjectValidationVerdict::class,
			self::classNames((new ReflectionMethod(ObjectService::class, 'validateObject'))->getReturnType())[0],
			'the implementation keeps returning the old subclass, which satisfies both names'
		);
	}//end testTheOldNamesStillFitThePublishedParameters()

	/**
	 * The contract's defaults still build the deprecated names.
	 *
	 * A consumer that omits the options, and every generated mock of the
	 * interface, receives exactly the value it received before the values were
	 * published; a test callback typed with the old name keeps working.
	 *
	 * @return void
	 */
	public function testTheDefaultsStillBuildTheDeprecatedNames(): void {
		$defaults = [
			'saveObject'  => Value\ObjectSaveOptions::class,
			'saveObjects' => Value\BulkSaveOptions::class,
		];
		foreach ($defaults as $methodName => $old) {
			$this->assertInstanceOf($old, $this->parameter(ObjectServiceInterface::class, $methodName, 'options')->getDefaultValue(), $methodName . '()');
		}

		$received = null;
		$service  = $this->createMock(ObjectServiceInterface::class);
		$service->method('saveObject')->willReturnCallback(
			function (array $object, Value\ObjectSaveOptions $options) use (&$received): Contract\ObjectEntityInterface {
				$received = $options;
				return $this->createMock(Contract\ObjectEntityInterface::class);
			}
		);
		$service->saveObject(['title' => 'x']);

		$this->assertInstanceOf(Value\ObjectSaveOptions::class, $received);
	}//end testTheDefaultsStillBuildTheDeprecatedNames()

	/**
	 * The lock handler's receiving chain takes the published lock values.
	 *
	 * @return void
	 */
	public function testTheLockChainTakesThePublishedValues(): void {
		$chain = [
			[LockHandler::class, 'lock', Contract\LockRequest::class],
			[LockHandler::class, 'unlock', Contract\UnlockRequest::class],
			[AdvisoryLockStore::class, 'acquire', Contract\LockRequest::class],
		];
		foreach ($chain as [$class, $methodName, $published]) {
			$parameter = $this->parameter($class, $methodName, 'request');
			$this->assertSame([$published], self::classNames($parameter->getType()), $class . '::' . $methodName . '()');
		}
	}//end testTheLockChainTakesThePublishedValues()

	/**
	 * A lock built only from published classes is taken and released.
	 *
	 * Runs the real LockHandler and AdvisoryLockStore, so a parameter anywhere
	 * on the path still typed with the old name fails here with a TypeError.
	 *
	 * @return void
	 */
	public function testALockBuiltFromPublishedValuesIsTakenAndReleased(): void {
		$identifier = 'createApp:published-names';
		$key        = 'advisory_lock_' . md5($identifier);
		$stored     = '';

		$this->appConfig->method('getValueString')->with('openregister', $key, '')->willReturnCallback(
			static function () use (&$stored): string {
				return $stored;
			}
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $configKey, string $value) use (&$stored): bool {
				$stored = $value;
				return true;
			}
		);
		$this->appConfig->expects($this->once())->method('deleteKey')->with('openregister', $key);

		$handler = $this->handler();
		$result  = $handler->lock(
			request: new Contract\LockRequest(identifier: $identifier, runStep: new Contract\LockRunStep(runUuid: null, nodeId: null))
		);

		$this->assertSame($identifier, $result['uuid']);
		$this->assertTrue($result['locked']['advisory']);
		$this->assertTrue($handler->unlock(request: new Contract\UnlockRequest(identifier: $identifier)));
	}//end testALockBuiltFromPublishedValuesIsTakenAndReleased()

	/**
	 * Both names take the same lock.
	 *
	 * @return void
	 */
	public function testBothNamesTakeTheSameLock(): void {
		$written = [];
		$this->appConfig->method('getValueString')->willReturn('');
		$this->appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $configKey, string $value) use (&$written): bool {
				$written[] = [$configKey, array_diff_key(json_decode($value, true), ['expiration' => true, 'created' => true])];
				return true;
			}
		);

		$handler = $this->handler();
		$handler->lock(request: new Value\LockRequest(identifier: 'same', process: 'p', duration: 60));
		$handler->lock(request: new Contract\LockRequest(identifier: 'same', process: 'p', duration: 60));

		$this->assertCount(2, $written);
		$this->assertSame($written[0], $written[1]);
	}//end testBothNamesTakeTheSameLock()

	/**
	 * Set up the mocks the real lock handler runs over.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appConfig   = $this->createMock(IAppConfig::class);
		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->magicMapper->method('findAcrossAllSources')->willThrowException(new DoesNotExistException('not found'));
	}//end setUp()

	/**
	 * A real lock handler over mocked storage; unknown ids take the advisory path.
	 *
	 * @return LockHandler The handler.
	 */
	private function handler(): LockHandler {
		$logger      = $this->createMock(LoggerInterface::class);
		$userSession = $this->createMock(IUserSession::class);

		return new LockHandler(
			new LockStores(
				magicMapper: $this->magicMapper,
				auditTrailMapper: $this->createMock(AuditTrailMapper::class),
				advisory: new AdvisoryLockStore($this->appConfig, $userSession, $logger)
			),
			$logger,
			new LockAuthorization($userSession, $this->createMock(IGroupManager::class), $this->createMock(SchemaMapper::class), $this->createMock(PermissionHandlerInterface::class))
		);
	}//end handler()

	/**
	 * One parameter of a method, by name.
	 *
	 * @param string $class      The class or interface.
	 * @param string $methodName The method.
	 * @param string $name       The parameter name.
	 *
	 * @return \ReflectionParameter The parameter.
	 */
	private function parameter(string $class, string $methodName, string $name): \ReflectionParameter {
		foreach ((new ReflectionMethod($class, $methodName))->getParameters() as $parameter) {
			if ($parameter->getName() === $name) {
				return $parameter;
			}
		}

		$this->fail($class . '::' . $methodName . '() has no parameter $' . $name);
	}//end parameter()
}//end class
