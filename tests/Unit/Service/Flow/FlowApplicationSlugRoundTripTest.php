<?php

/**
 * A flow's OpenBuild virtual-app association survives the database.
 *
 * `applicationSlug` narrows `app`: one Nextcloud app can host several
 * independent OpenBuild virtual apps, each with its own flows, and `app`
 * alone cannot distinguish between them. The tests below pin the claims that
 * matter for a purely additive, optional field: it is stored and serialised,
 * a partial update that omits the key leaves it alone, and an explicit
 * `null` clears it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-application-slug/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancer;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndex;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Storage of the `applicationSlug` field on a flow.
 */
final class FlowApplicationSlugRoundTripTest extends TestCase {

	/**
	 * The organisation an update-path flow is scoped to.
	 *
	 * `find()` refuses any flow that does not belong to the ACTIVE
	 * organisation, and `belongsTo()` is false when either side is empty — so
	 * without a resolvable one, every update would throw before reaching the
	 * field logic and the tests would pass for the wrong reason.
	 */
	private const ORGANISATION = 'org-under-test';

	/**
	 * The flow the mapper was handed on insert.
	 *
	 * @var Flow|null
	 */
	private ?Flow $inserted = null;

	/**
	 * A container that resolves an organisation service naming ORGANISATION.
	 *
	 * @return ContainerInterface The configured double.
	 */
	private function organisationContainer(): ContainerInterface {
		$organisation = new class {

			/**
			 * The active organisation's uuid.
			 *
			 * @return string The uuid.
			 */
			public function getUuid(): string {
				return 'org-under-test';
			}

		};

		$organisationService = new class($organisation) {

			/**
			 * @param object $organisation The active organisation stub.
			 */
			public function __construct(
				private readonly object $organisation,
			) {

			}

			/**
			 * The active organisation.
			 *
			 * @return object The organisation stub.
			 */
			public function getActiveOrganisation(): object {
				return $this->organisation;
			}

		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($organisationService);

		return $container;
	}//end organisationContainer()

	/**
	 * Build a FlowService whose collaborators are all doubles.
	 *
	 * @param FlowMapper $mapper The flow mapper double.
	 * @param ContainerInterface|null $container The container double, or null for a bare one.
	 *
	 * @return FlowService The service under test.
	 */
	private function serviceWith(FlowMapper $mapper, ?ContainerInterface $container = null): FlowService {
		return new FlowService(
			$mapper,
			$this->createMock(FlowTriggerIndex::class),
			$this->createMock(FlowRunService::class),
			$this->createMock(FlowRunAdvancer::class),
			$this->createMock(FlowRunMapper::class),
			$this->createMock(FlowRunStepMapper::class),
			$this->createMock(FlowStateMapper::class),
			$this->signedInSession(),
			$this->createMock(LoggerInterface::class),
			($container ?? $this->createMock(ContainerInterface::class))
		);

	}//end serviceWith()

	/**
	 * A session with somebody actually signed in.
	 *
	 * A bare IUserSession double returns no user, and creating a flow that way
	 * used to succeed: `save()` stamped a null owner and a null organisation.
	 * `Flow::belongsTo()` is fail-closed on both sides, so such a flow belongs
	 * to nobody — never listed, never found, never runnable again. The fixture
	 * described a caller the API is not supposed to serve.
	 *
	 * @return IUserSession The session double.
	 */
	private function signedInSession(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('author');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;

	}//end signedInSession()

	/**
	 * Save a payload through a real FlowService and return the stored flow.
	 *
	 * The mapper double returns whatever it was given, so the assertion reads
	 * the entity the service actually built rather than a fixture.
	 *
	 * @param array<string, mixed> $data The payload to save.
	 *
	 * @return Flow The flow as it reached the mapper.
	 */
	private function saved(array $data): Flow {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('insert')->willReturnCallback(
			function (Flow $flow): Flow {
				$this->inserted = $flow;
				return $flow;
			}
		);

		$this->serviceWith($mapper, $this->organisationContainer())->save(data: $data);

		$this->assertInstanceOf(Flow::class, $this->inserted, 'the service should have inserted a flow');

		return $this->inserted;
	}//end saved()

	/**
	 * The API field is stored and serialised back.
	 *
	 * @return void
	 */
	public function testApplicationSlugIsStoredAndSerialised(): void {
		$flow = $this->saved(['name' => 'hydra pipeline', 'applicationSlug' => 'hydra']);

		$this->assertSame('hydra', $flow->getApplicationSlug());
		$this->assertSame('hydra', $flow->jsonSerialize()['applicationSlug']);

	}//end testApplicationSlugIsStoredAndSerialised()

	/**
	 * A flow created with no applicationSlug in the payload stays null.
	 *
	 * The default, unassociated case — most flows on the shared instance have
	 * no virtual-app association at all, and this must remain a fully valid
	 * flow, not an error.
	 *
	 * @return void
	 */
	public function testAFlowWithNoApplicationSlugStaysNull(): void {
		$flow = $this->saved(['name' => 'ghsync']);

		$this->assertNull($flow->getApplicationSlug());
		$this->assertNull($flow->jsonSerialize()['applicationSlug']);

	}//end testAFlowWithNoApplicationSlugStaysNull()

	/**
	 * A partial update that omits the key leaves the stored value alone.
	 *
	 * `applyEditableFields` only touches keys that are present, and a partial
	 * update — enabling a flow, say — must not clear its virtual-app
	 * association as a side effect. This goes through the UPDATE path
	 * deliberately: on a create the field starts null, so the same assertion
	 * there would hold no matter what the code did.
	 *
	 * @return void
	 */
	public function testAPartialUpdateDoesNotClearTheStoredApplicationSlug(): void {
		$existing = new Flow();
		$existing->setUuid('flow-uuid-1');
		$existing->setOrganisation(self::ORGANISATION);
		$existing->setApplicationSlug('hydra');

		$updated = null;

		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findByUuid')->willReturn($existing);
		$mapper->method('update')->willReturnCallback(
			function (Flow $flow) use (&$updated): Flow {
				$updated = $flow;
				return $flow;
			}
		);

		$this->serviceWith($mapper, $this->organisationContainer())
			->save(data: ['enabled' => true], uuid: 'flow-uuid-1');

		$this->assertInstanceOf(Flow::class, $updated, 'the service should have updated a flow');
		$this->assertSame('hydra', $updated->getApplicationSlug());

	}//end testAPartialUpdateDoesNotClearTheStoredApplicationSlug()

	/**
	 * An explicit null DOES clear it.
	 *
	 * The counterpart to the test above, and the reason that one is not
	 * vacuous: "absent" and "explicitly cleared" are different payloads and
	 * must land differently, or the field could never be emptied once set.
	 *
	 * @return void
	 */
	public function testAnExplicitNullClearsTheApplicationSlug(): void {
		$existing = new Flow();
		$existing->setUuid('flow-uuid-1');
		$existing->setOrganisation(self::ORGANISATION);
		$existing->setApplicationSlug('hydra');

		$updated = null;

		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findByUuid')->willReturn($existing);
		$mapper->method('update')->willReturnCallback(
			function (Flow $flow) use (&$updated): Flow {
				$updated = $flow;
				return $flow;
			}
		);

		$this->serviceWith($mapper, $this->organisationContainer())
			->save(data: ['applicationSlug' => null], uuid: 'flow-uuid-1');

		$this->assertInstanceOf(Flow::class, $updated, 'the service should have updated a flow');
		$this->assertNull($updated->getApplicationSlug());

	}//end testAnExplicitNullClearsTheApplicationSlug()

	/**
	 * `FlowService::findAll()` forwards `applicationSlug` straight through to
	 * the mapper, alongside the organisation it always applies.
	 *
	 * Every other test in this file drives `save()`; `findAll()`/`count()` are
	 * only otherwise exercised with `FlowService` itself mocked away (in
	 * `FlowControllerTest`), which would leave this pass-through unverified
	 * against the real method body.
	 *
	 * @return void
	 */
	public function testFindAllForwardsTheApplicationSlugFilterToTheMapper(): void {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->expects($this->once())
			->method('findAllFlows')
			->with(null, 'hydra', self::ORGANISATION, null, 100, 0)
			->willReturn([]);

		$result = $this->serviceWith($mapper, $this->organisationContainer())->findAll(app: null, applicationSlug: 'hydra');

		$this->assertSame([], $result);

	}//end testFindAllForwardsTheApplicationSlugFilterToTheMapper()

	/**
	 * `FlowService::count()` forwards `applicationSlug` the same way.
	 *
	 * @return void
	 */
	public function testCountForwardsTheApplicationSlugFilterToTheMapper(): void {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->expects($this->once())
			->method('countFlows')
			->with(null, 'hydra', self::ORGANISATION)
			->willReturn(3);

		$result = $this->serviceWith($mapper, $this->organisationContainer())->count(app: null, applicationSlug: 'hydra');

		$this->assertSame(3, $result);

	}//end testCountForwardsTheApplicationSlugFilterToTheMapper()

}//end class
