<?php

/**
 * The upgrade that starts enforcing agent_use and llm_use refuses nobody.
 *
 * Driven from the registration: the step is found by the class name that
 * appinfo/info.xml lists under post-migration, built the way Nextcloud builds
 * it, and run against organisations shaped like the ones on a live instance
 * (`[]` from a new organisation, `["admin"]` from the admin-group backfill, a
 * narrowed list somebody saved through the editor). After it runs, a user in
 * no listed group may still chat, read through the real reader.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Repair\OpenSpecialRightsToEveryone;
use OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Repair\OpenSpecialRightsToEveryone
 * @uses   \OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights
 * @uses   \OCA\OpenRegister\Db\Organisation
 *
 * @spec openspec/changes/enforce-organisation-special-rights/specs/organisation-special-rights/spec.md
 */
class OpenSpecialRightsToEveryoneTest extends TestCase {

	/**
	 * Organisations by uuid, as stored.
	 *
	 * @var array<string, Organisation>
	 */
	private array $stored = [];

	/**
	 * App config values set during the test.
	 *
	 * @var array<string, bool>
	 */
	private array $config = [];

	private OrganisationMapper&MockObject $organisations;

	private OrganisationSpecialRights $rights;

	protected function setUp(): void {
		parent::setUp();

		foreach ([
			'new' => ['object_publish' => [], 'agent_use' => [], 'dashboard_view' => [], 'llm_use' => [], 'grant_manage' => []],
			'backfilled' => ['object_publish' => ['admin'], 'agent_use' => ['admin'], 'dashboard_view' => ['admin'], 'llm_use' => ['admin']],
			'narrowed' => ['agent_use' => ['ai-users'], 'llm_use' => ['ai-users'], 'grant_manage' => ['records-service']],
		] as $uuid => $block) {
			$organisation = new Organisation();
			$organisation->setUuid($uuid);
			$organisation->setAuthorization($block);
			$this->stored[$uuid] = $organisation;
		}

		$this->organisations = $this->createMock(OrganisationMapper::class);
		$this->organisations->method('findAll')->willReturnCallback(fn (): array => array_values($this->stored));
		$this->organisations->method('findByUuid')->willReturnCallback(fn (string $uuid): Organisation => $this->stored[$uuid]);
		$this->organisations->method('update')->willReturnArgument(0);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => $group === 'staff');

		$this->rights = new OrganisationSpecialRights(
			$this->organisations,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			new NullLogger(),
			$groupManager
		);
	}//end setUp()

	/**
	 * The class info.xml names, built with its constructor's dependencies.
	 *
	 * @return IRepairStep The step.
	 */
	private function registeredStep(): IRepairStep {
		// Read the file with PHP, not libxml: under Nextcloud the libxml external
		// entity loader refuses file paths, and simplexml_load_file() answers false.
		$info = simplexml_load_string((string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/info.xml'));
		$this->assertNotFalse($info);

		$registered = array_map('strval', $info->xpath('//repair-steps/post-migration/step'));
		$name = OpenSpecialRightsToEveryone::class;
		$this->assertContains($name, $registered, 'info.xml must run the step on upgrade');
		$this->assertNotContains($name, array_map('strval', $info->xpath('//repair-steps/install/step')), 'a fresh install already defaults to *');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false): bool => $this->config[$key] ?? $default
		);
		$appConfig->method('setValueBool')->willReturnCallback(
			function (string $app, string $key, bool $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$step = new $name($this->rights, $appConfig, new NullLogger());
		$this->assertInstanceOf(IRepairStep::class, $step);

		return $step;
	}//end registeredStep()

	public function testBeforeTheStepTheStoredListsWouldRefuseAnOrdinaryUser(): void {
		// The control: without the step, enforcing the lists as stored refuses.
		foreach (array_keys($this->stored) as $uuid) {
			$this->assertFalse($this->rights->mayUse(OrganisationSpecialRights::LLM_USE, 'bob', $uuid), $uuid);
		}
	}//end testBeforeTheStepTheStoredListsWouldRefuseAnOrdinaryUser()

	public function testAfterTheUpgradeEveryUserWhoCouldChatStillCan(): void {
		$this->registeredStep()->run($this->createMock(IOutput::class));

		foreach (array_keys($this->stored) as $uuid) {
			$this->assertTrue($this->rights->mayUse(OrganisationSpecialRights::LLM_USE, 'bob', $uuid), $uuid);
			$this->assertTrue($this->rights->mayUse(OrganisationSpecialRights::AGENT_USE, 'bob', $uuid), $uuid);
		}

		$this->assertSame(['ai-users', '*'], $this->stored['narrowed']->getAuthorization()['llm_use'], 'groups are kept');
		// The retired rights (decisions 247, 248) are left exactly as stored, and not added where absent.
		$this->assertSame([], $this->stored['new']->getAuthorization()['object_publish']);
		$this->assertSame(['admin'], $this->stored['backfilled']->getAuthorization()['dashboard_view']);
		$this->assertArrayNotHasKey('object_publish', $this->stored['narrowed']->getAuthorization());
		$this->assertSame(['records-service'], $this->stored['narrowed']->getAuthorization()['grant_manage'], 'grant_manage is never opened');
		$this->assertSame([], $this->stored['new']->getAuthorization()['grant_manage']);
		$this->assertTrue($this->config[OpenSpecialRightsToEveryone::DONE_KEY]);
	}//end testAfterTheUpgradeEveryUserWhoCouldChatStillCan()

	public function testALaterUpgradeDoesNotReopenARightAnAdministratorNarrowed(): void {
		$step = $this->registeredStep();
		$step->run($this->createMock(IOutput::class));

		$block = $this->stored['narrowed']->getAuthorization();
		$block['llm_use'] = ['ai-users'];
		$this->stored['narrowed']->setAuthorization($block);

		$this->organisations->expects($this->never())->method('update');
		$step->run($this->createMock(IOutput::class));

		$this->assertFalse($this->rights->mayUse(OrganisationSpecialRights::LLM_USE, 'bob', 'narrowed'));
	}//end testALaterUpgradeDoesNotReopenARightAnAdministratorNarrowed()

	public function testAFailureDoesNotAbortTheUpgradeAndIsFinishedNextTime(): void {
		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('findAll')->willThrowException(new RuntimeException('database away'));
		$failing = new OrganisationSpecialRights(
			$organisations,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			new NullLogger(),
			$this->createMock(IGroupManager::class)
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);
		$appConfig->expects($this->never())->method('setValueBool');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		(new OpenSpecialRightsToEveryone($failing, $appConfig, new NullLogger()))->run($output);
	}//end testAFailureDoesNotAbortTheUpgradeAndIsFinishedNextTime()
}//end class
