<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Flow\FlowRunAttribution;
use OCA\OpenRegister\Service\Flow\FlowTenantMembership;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Pins every allowed and refused attribution FlowRunAttribution::resolve() answers.
 *
 * Covers the caller-and-tenant shortcut, the flow fallback for a missing tenant,
 * the trigger-declared identity with and without a delegation stamp, an
 * identity that left its tenant (refused, naming it), one that is still a member
 * (allowed), and an unloadable flow.
 */
class FlowRunAttributionOutcomesGoldenTest extends TestCase {

	/**
	 * A flow whose schedule trigger declares an identity.
	 *
	 * @param string      $app        The flow's app.
	 * @param string|null $declaredBy The delegation stamp, if any.
	 *
	 * @return Flow The flow.
	 */
	private function scheduledFlow(string $app, ?string $declaredBy = null): Flow {
		$config = ['runAs' => 'bob'];
		if ($declaredBy !== null) {
			$config['runAsDeclaredBy'] = $declaredBy;
		}

		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setOrganisation('org-flow');
		$flow->setApp($app);
		$flow->setNodes([['id' => 't', 'type' => 'openregister.trigger-schedule', 'config' => $config]]);

		return $flow;
	}//end scheduledFlow()

	/**
	 * The attribution under test, over a container that answers the given collaborators.
	 *
	 * @param Flow|null   $flow      The flow the mapper returns, or null when it cannot be loaded.
	 * @param string|null $activeOrg The session's active organisation, if any.
	 * @param bool        $member    Whether the declared identity is still a member of the flow's tenant.
	 *
	 * @return FlowRunAttribution The attribution.
	 */
	private function attribution(?Flow $flow, ?string $activeOrg, bool $member = true): FlowRunAttribution {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($flow, $activeOrg, $member): object {
				if ($id === 'OCA\OpenRegister\Db\FlowMapper') {
					return new class($flow) {
						public function __construct(private readonly ?Flow $flow) {
						}

						public function findByUuid(string $uuid): Flow {
							if ($this->flow === null) {
								throw new RuntimeException('no such flow');
							}

							return $this->flow;
						}
					};
				}

				if ($id === 'OCA\OpenRegister\Service\OrganisationServiceInterface' && $activeOrg !== null) {
					return new class($activeOrg) {
						public function __construct(private readonly string $uuid) {
						}

						public function getActiveOrganisation(): object {
							return new class($this->uuid) {
								public function __construct(private readonly string $uuid) {
								}

								public function getUuid(): string {
									return $this->uuid;
								}
							};
						}
					};
				}

				if ($id === FlowTenantMembership::class) {
					$membership = $this->createMock(FlowTenantMembership::class);
					$membership->method('isMember')->willReturn($member);
					return $membership;
				}

				throw new RuntimeException('not available: ' . $id);
			}
		);

		return new FlowRunAttribution($container, new NullLogger());
	}//end attribution()

	/**
	 * Every allowed and refused outcome, as recorded on the code before the ADR-011 change.
	 *
	 * @return void
	 */
	public function testEveryAttributionOutcome(): void {
		$manifest = Flow::APP_MANIFEST_SCHEDULE;
		$cases = [
			'caller-and-tenant' => [
				$this->attribution($this->scheduledFlow($manifest), 'org-session')->resolve('flow-1', 'alice', 'schedule'),
				['user' => 'alice', 'organisation' => 'org-session', 'declaredBy' => null],
			],
			'caller-tenant-from-flow' => [
				$this->attribution($this->scheduledFlow($manifest, 'carol'), null)->resolve('flow-1', 'alice', 'schedule'),
				['user' => 'alice', 'organisation' => 'org-flow', 'declaredBy' => null],
			],
			'declared-identity-delegated' => [
				$this->attribution($this->scheduledFlow('openregister', 'carol'), null)->resolve('flow-1', null, 'schedule'),
				['user' => 'bob', 'organisation' => 'org-flow', 'declaredBy' => 'carol'],
			],
			'manual-run-declares-nothing' => [
				$this->attribution($this->scheduledFlow($manifest), null)->resolve('flow-1', '  ', 'manual'),
				['user' => null, 'organisation' => 'org-flow', 'declaredBy' => null],
			],
			'left-its-tenant-refused' => [
				$this->attribution($this->scheduledFlow($manifest), null, false)->resolve('flow-1', null, 'schedule'),
				[
					'user' => null,
					'refusal' => 'Its acting identity "bob" is not a member of the flow\'s organisation. Publishing the application again sets a new one.',
					'organisation' => 'org-flow',
					'declaredBy' => null,
				],
			],
			'still-a-member-allowed' => [
				$this->attribution($this->scheduledFlow($manifest), 'org-session', true)->resolve('flow-1', null, 'case'),
				['user' => 'bob', 'organisation' => 'org-session', 'declaredBy' => null],
			],
			'unloadable-flow' => [
				$this->attribution(null, null)->resolve('flow-1', null, 'schedule'),
				['user' => null, 'organisation' => null, 'declaredBy' => null],
			],
		];

		foreach ($cases as $name => [$actual, $expected]) {
			$this->assertSame($expected, $actual, $name);
		}
	}//end testEveryAttributionOutcome()
}//end class
