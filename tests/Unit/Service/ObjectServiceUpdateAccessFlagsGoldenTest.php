<?php

/**
 * `updateObject()` applies the access flags its caller passes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pins the allowed and the refused outcome of an update for each value of `_rbac` and `_multitenancy`.
 *
 * The save is stood in by a gate that refuses exactly what the real save refuses for this caller: the
 * schema's RBAC denies the write, and the object belongs to another organisation. A flag that is
 * applied lets the update through when it is false; a flag that is dropped leaves it refused.
 */
class ObjectServiceUpdateAccessFlagsGoldenTest extends TestCase {

	/**
	 * The service with only saveObject() replaced.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $service;

	/**
	 * The flags each save received, by name.
	 *
	 * @var list<array{_rbac: bool, _multitenancy: bool, object: mixed}>
	 */
	private array $saves = [];

	/**
	 * Wire the service and the refusing gate.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->service = $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['saveObject'])
			->getMock();

		// The mock hands its arguments over positionally; resolve the flags by parameter name so the
		// test does not depend on where saveObject() declares them.
		$positions = [];
		foreach ((new ReflectionMethod(ObjectService::class, 'saveObject'))->getParameters() as $parameter) {
			$positions[$parameter->getName()] = $parameter->getPosition();
		}

		$this->service->method('saveObject')->willReturnCallback(
			function (mixed ...$arguments) use ($positions): ObjectEntity {
				$save = [
					'object'        => $arguments[$positions['object']],
					'_rbac'         => $arguments[$positions['_rbac']],
					'_multitenancy' => $arguments[$positions['_multitenancy']],
				];
				$this->saves[] = $save;

				if ($save['_rbac'] === true) {
					throw new NotAuthorizedException('The schema does not grant this user update.');
				}

				if ($save['_multitenancy'] === true) {
					throw new NotAuthorizedException('The object belongs to another organisation.');
				}

				$saved = new ObjectEntity();
				$saved->setUuid('object-1');
				$saved->setObject($save['object']);

				return $saved;
			}
		);

	}//end setUp()

	/**
	 * The flag combinations and whether the update goes through.
	 *
	 * @return array<string, array{0: bool, 1: bool, 2: bool}>
	 */
	public static function flagOutcomes(): array {
		return [
			'both applied: refused'            => [true, true, false],
			'rbac applied: refused'            => [true, false, false],
			'multitenancy applied: refused'    => [false, true, false],
			'both lifted: allowed'             => [false, false, true],
		];

	}//end flagOutcomes()

	/**
	 * Each flag reaches the save unchanged, and the outcome follows it.
	 *
	 * @param bool $rbac          The `_rbac` the caller passes.
	 * @param bool $multitenancy  The `_multitenancy` the caller passes.
	 * @param bool $allowed       Whether the update must go through.
	 *
	 * @return void
	 */
	#[DataProvider('flagOutcomes')]
	public function testTheUpdateOutcomeFollowsTheFlagsTheCallerPasses(bool $rbac, bool $multitenancy, bool $allowed): void {
		$refused = null;
		$result  = null;
		try {
			$result = $this->service->updateObject('object-1', ['title' => 'Nieuw'], $rbac, $multitenancy);
		} catch (NotAuthorizedException $exception) {
			$refused = $exception;
		}

		$this->assertCount(1, $this->saves);
		$this->assertSame($rbac, $this->saves[0]['_rbac']);
		$this->assertSame($multitenancy, $this->saves[0]['_multitenancy']);
		$this->assertSame(['title' => 'Nieuw', 'id' => 'object-1'], $this->saves[0]['object']);

		if ($allowed === true) {
			$this->assertNull($refused);
			$this->assertSame('object-1', $result?->getUuid());
			return;
		}

		$this->assertInstanceOf(NotAuthorizedException::class, $refused);

	}//end testTheUpdateOutcomeFollowsTheFlagsTheCallerPasses()

	/**
	 * A caller that names no flag keeps both checks.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutFlagsIsChecked(): void {
		$this->expectException(NotAuthorizedException::class);

		try {
			$this->service->updateObject('object-1', ['title' => 'Nieuw']);
		} finally {
			$this->assertTrue($this->saves[0]['_rbac']);
			$this->assertTrue($this->saves[0]['_multitenancy']);
		}

	}//end testAnUpdateWithoutFlagsIsChecked()
}//end class
