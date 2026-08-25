<?php

declare(strict_types=1);

/**
 * `resolveRegisterSchemaIds()` must name the thing that actually failed.
 *
 * openregister#2820. `GET /api/objects/{register}/{schema}` answered
 * `404 Register not found: '19'` for a register whose row was intact, whose
 * magic tables existed, and which `occ` resolved without complaint. With the
 * logger from #2822 finally wired, the mapper could be seen SUCCEEDING on that
 * exact lookup while the endpoint still reported the register as missing.
 *
 * The cause is that `setRegister()` does two things: it resolves the register,
 * and then — if a schema ref is still pending from an earlier caller on the
 * shared ObjectService — it re-resolves that ref inside the register. A scoped
 * miss there is a SCHEMA failure, and the controller reported every
 * `DoesNotExistException` out of `setRegister()` as a missing register.
 *
 * The cost of that misattribution was hours: the error named a register that
 * demonstrably existed, so every reasonable first move confirmed the register
 * and explained nothing.
 *
 * These tests pin both directions. Only asserting the schema case would let a
 * fix pass that reports EVERYTHING as a schema problem — the same defect
 * pointing the other way.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Attribution of resolution failures to the register or the schema.
 */
class ObjectsControllerResolutionAttributionTest extends TestCase {
	/**
	 * Invoke the private resolver on a bare controller instance.
	 *
	 * The method touches only its ObjectService argument, so the controller
	 * itself needs no wiring — constructing one through the container would
	 * drag in thirty collaborators to exercise ten lines.
	 *
	 * @param ObjectService $service the service double
	 *
	 * @return array the resolver result
	 */
	private function resolve(ObjectService $service): array {
		$controller = (new \ReflectionClass(ObjectsController::class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod(ObjectsController::class, 'resolveRegisterSchemaIds');
		$method->setAccessible(true);
		return $method->invoke($controller, '19', '9476', $service);
	}

	/**
	 * A register that genuinely does not resolve is reported as the register.
	 *
	 * `setRegister()` throws without ever assigning an entity, so nothing new is
	 * present afterwards.
	 *
	 * @return void
	 */
	public function testAGenuinelyMissingRegisterIsReportedAsTheRegister(): void {
		$service = $this->createMock(ObjectService::class);
		$service->method('getCurrentRegisterEntity')->willReturn(null);
		$service->method('setRegister')->willThrowException(new DoesNotExistException('no such register'));

		$this->expectException(RegisterNotFoundException::class);
		$this->expectExceptionMessage("Register not found: '19'");
		$this->resolve($service);
	}

	/**
	 * A register that RESOLVED, followed by a schema-side failure, is reported
	 * as the schema — this is #2820 itself.
	 *
	 * @return void
	 */
	public function testAResolvedRegisterWithASchemaFailureIsReportedAsTheSchema(): void {
		$resolved = new Register();
		$resolved->setId(19);

		$service = $this->createMock(ObjectService::class);
		// Nothing held before the call; the entity appears during it, which is
		// what proves the register lookup itself succeeded.
		$service->method('getCurrentRegisterEntity')
			->willReturnOnConsecutiveCalls(null, $resolved, $resolved);
		$service->method('setRegister')
			->willThrowException(new DoesNotExistException('schema not carried by register'));

		$this->expectException(SchemaNotFoundException::class);
		$this->expectExceptionMessage("Schema not found: '9476'");
		$this->resolve($service);
	}

	/**
	 * A leftover register from an EARLIER caller does not disguise a genuine
	 * missing register as a schema problem.
	 *
	 * ObjectService is shared, so `currentRegister` can already be populated
	 * when the call starts. Testing it for null alone would swap one
	 * misattribution for another — this is the case that makes the before/after
	 * comparison necessary rather than decorative.
	 *
	 * @return void
	 */
	public function testALeftoverRegisterFromAnEarlierCallerIsNotMistakenForSuccess(): void {
		$someoneElses = new Register();
		$someoneElses->setId(206);

		$service = $this->createMock(ObjectService::class);
		// Same entity before and after: setRegister() assigned nothing.
		$service->method('getCurrentRegisterEntity')->willReturn($someoneElses);
		$service->method('setRegister')->willThrowException(new DoesNotExistException('no such register'));

		$this->expectException(RegisterNotFoundException::class);
		$this->expectExceptionMessage("Register not found: '19'");
		$this->resolve($service);
	}
}
