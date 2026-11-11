<?php

/**
 * Golden: which flows FlowService::findAll() lists, and what reaches the mapper.
 *
 * Pins the tenancy scope of the flow listing before it moves onto a ListQuery:
 * without a resolvable active organisation nothing is listed and the mapper is
 * not asked; with one, the listing is scoped to it and the filters and page
 * reach the mapper unchanged.
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
use OCA\OpenRegister\Db\Flow\FlowSelection;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Flow\FlowDefinitionStore;
use OCA\OpenRegister\Service\Flow\FlowDeletionSweep;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancer;
use OCA\OpenRegister\Service\Flow\FlowRunAuthorization;
use OCA\OpenRegister\Service\Flow\FlowRunDispatch;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowServicePlatform;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndex;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden for the flow listing's tenancy scope.
 */
final class FlowServiceListingScopeGoldenTest extends TestCase {

	/**
	 * Every findAllFlows() call, as its named arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * A service whose active organisation is the given uuid, or none.
	 *
	 * @param string|null $organisation The active organisation's uuid, or null for none.
	 *
	 * @return FlowService
	 */
	private function service(?string $organisation): FlowService {
		$flow = new Flow();
		$flow->setUuid('flow-1');

		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findAllFlows')->willReturnCallback(
			function (FlowSelection $selection, ListQuery $query) use ($flow): array {
				$this->calls[] = [
					'app'             => $selection->app,
					'applicationSlug' => $selection->applicationSlug,
					'organisation'    => $selection->organisation,
					'enabled'         => $selection->enabled,
					'limit'           => $query->limit,
					'offset'          => $query->offset,
				];

				return [$flow];
			}
		);

		$organisations = new class ($organisation) {

			public function __construct(private ?string $uuid) {
			}

			public function getActiveOrganisation(): ?object {
				if ($this->uuid === null) {
					return null;
				}

				return new class ($this->uuid) {

					public function __construct(private string $uuid) {
					}

					public function getUuid(): string {
						return $this->uuid;
					}
				};
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($organisations);

		return new FlowService(
			new FlowDefinitionStore($mapper, $this->createMock(FlowTriggerIndex::class)),
			new FlowRunDispatch(
				$this->createMock(FlowRunService::class),
				$this->createMock(FlowRunAdvancer::class),
				new FlowRunAuthorization(access: $this->createMock(FlowAccess::class))
			),
			new FlowDeletionSweep(
				$this->createMock(FlowRunMapper::class),
				$this->createMock(FlowRunStepMapper::class),
				$this->createMock(FlowStateMapper::class)
			),
			new FlowServicePlatform($this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class), $container)
		);
	}//end service()

	/**
	 * Without an active organisation nothing is listed and the mapper is not asked.
	 *
	 * @return void
	 */
	public function testNoActiveOrganisationListsNothing(): void {
		$this->assertSame([], $this->service(organisation: null)->findAll(app: 'a', enabled: true));
		$this->assertSame([], $this->calls, 'A listing without a tenant reached the mapper.');
	}//end testNoActiveOrganisationListsNothing()

	/**
	 * With an active organisation the listing is scoped to it; filters and page are forwarded.
	 *
	 * @return void
	 */
	public function testTheListingIsScopedToTheActiveOrganisation(): void {
		$flows = $this->service(organisation: 'org-a')->findAll(app: 'a', applicationSlug: 's', enabled: false, limit: 20, offset: 40);

		$this->assertCount(1, $flows);
		$this->assertSame(
			[['app' => 'a', 'applicationSlug' => 's', 'organisation' => 'org-a', 'enabled' => false, 'limit' => 20, 'offset' => 40]],
			$this->calls
		);
	}//end testTheListingIsScopedToTheActiveOrganisation()

	/**
	 * The defaults list every app's flows, enabled or not, a hundred at a time.
	 *
	 * @return void
	 */
	public function testTheDefaultsListAHundredFromTheStart(): void {
		$this->service(organisation: 'org-b')->findAll();

		$this->assertSame(
			[['app' => null, 'applicationSlug' => null, 'organisation' => 'org-b', 'enabled' => null, 'limit' => 100, 'offset' => 0]],
			$this->calls
		);
	}//end testTheDefaultsListAHundredFromTheStart()
}//end class
