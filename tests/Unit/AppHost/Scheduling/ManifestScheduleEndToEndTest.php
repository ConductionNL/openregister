<?php

/**
 * AppHost scheduling: from a published manifest to a queued run.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Scheduling;

use OCA\OpenRegister\AppHost\Scheduling\ScheduleAuditTrail;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;

/**
 * Runs listener, reader, sync, store, locator, scheduler and queue for real.
 *
 * @covers \OCA\OpenRegister\AppHost\Listener\ApplicationPublishListener
 * @covers \OCA\OpenRegister\AppHost\Scheduling\ScheduleReleaseReader
 * @covers \OCA\OpenRegister\AppHost\Scheduling\ApplicationRegister
 * @covers \OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowSync
 * @covers \OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowStore
 * @covers \OCA\OpenRegister\AppHost\Scheduling\CompiledScheduleFlow
 * @covers \OCA\OpenRegister\AppHost\Scheduling\ScheduleRelease
 * @covers \OCA\OpenRegister\AppHost\Scheduling\ScheduleAuditTrail
 * @covers \OCA\OpenRegister\AppHost\Scheduling\ScheduleRefusals
 * @covers \OCA\OpenRegister\Service\Flow\FlowTenantMembership
 * @covers \OCA\OpenRegister\Db\Flow
 * @uses   \OCA\OpenRegister\AppHost\Scheduling\CronScheduleEvaluator
 * @uses   \OCA\OpenRegister\AppHost\Scheduling\IntervalCron
 * @uses   \OCA\OpenRegister\AppHost\Scheduling\ScheduleActionNodes
 * @uses   \OCA\OpenRegister\AppHost\Scheduling\ScheduleDescriptor
 * @uses   \OCA\OpenRegister\AppHost\Scheduling\ScheduleManifest
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Db\FlowRun
 * @uses   \OCA\OpenRegister\Db\FlowVersion
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Organisation
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Event\ObjectDeletedEvent
 * @uses   \OCA\OpenRegister\Event\ObjectUpdatedEvent
 * @uses   \OCA\OpenRegister\Service\Flow\FlowDelegationCheck
 * @uses   \OCA\OpenRegister\Service\Flow\FlowLocator
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunAttribution
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunRow
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunService
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunVersionPin
 * @uses   \OCA\OpenRegister\Service\Flow\FlowStateBinding
 * @uses   \OCA\OpenRegister\Service\Flow\FlowScheduleService
 * @uses   \OCA\OpenRegister\Service\Flow\FlowTriggerValidator
 * @uses   \OCA\OpenRegister\Service\Flow\FlowUnattributed
 * @uses   \OCA\OpenRegister\Service\WriteCause
 */
class ManifestScheduleEndToEndTest extends ManifestScheduleHarness {
	public function testACronScheduleInAPublishedManifestFiresAsAFlowRun(): void {
		$this->publish([$this->schedule()]);

		$flow = $this->flow();
		$this->assertSame(Flow::TRIGGER_SCHEDULE, $flow->getTrigger());
		$this->assertSame('0 3 * * *', $flow->getCron());
		$this->assertSame('app-1', $flow->getApplicationSlug());
		$this->assertSame([[$flow->getUuid(), 'pieter']], $this->published);

		$this->assertSame([$flow->getUuid()], $this->tick());
		$this->assertCount(1, $this->runs);
		$this->assertSame('schedule', $this->runs[0]->getTrigger());
		$this->assertSame('pieter', $this->runs[0]->getRunAs());
		$this->assertSame(self::ORGANISATION, $this->runs[0]->getOrganisation());
	}

	public function testTheRunsStepIsTheSynchronizationBuildiqNamed(): void {
		$this->publish([$this->schedule()]);

		$this->assertSame(
			['type' => 'openconnector.synchronization-run', 'config' => ['synchronization' => 'sync-1']],
			array_intersect_key($this->flow()->getNodes()[1], ['type' => 1, 'config' => 1])
		);
	}

	public function testAnIntervalScheduleFiresOnItsCron(): void {
		$schedule = $this->schedule(['interval' => 86400]);
		unset($schedule['cron']);

		$this->publish([$schedule]);

		$this->assertSame('0 0 * * *', $this->flow()->getCron());
	}

	public function testPublishingTheSameManifestAgainChangesNothing(): void {
		$this->publish([$this->schedule()]);
		$writes = $this->flowWrites;
		$published = count($this->published);
		$audited = count($this->audit);

		$this->publish([$this->schedule()]);

		$this->assertSame($writes, $this->flowWrites);
		$this->assertCount($published, $this->published);
		$this->assertCount($audited, $this->audit);
	}

	public function testAChangedScheduleUpdatesTheSameFlowInANewVersion(): void {
		$this->publish([$this->schedule()]);
		$uuid = $this->flow()->getUuid();

		$this->publish([$this->schedule(['cron' => '0 4 * * *'])]);

		$this->assertSame($uuid, $this->flow()->getUuid());
		$this->assertSame('0 4 * * *', $this->flow()->getCron());
		$this->assertSame('0 4 * * *', $this->flow()->getNodes()[0]['config']['cron']);
		$this->assertCount(2, $this->published);
	}

	public function testADisabledScheduleKeepsItsFlowAndDoesNotFire(): void {
		$this->publish([$this->schedule(['enabled' => false])]);

		$this->assertFalse($this->flow()->getEnabled());
		$this->assertSame([], $this->tick());
	}

	public function testDisablingAScheduleWritesNoNewVersion(): void {
		$this->publish([$this->schedule()]);

		$this->publish([$this->schedule(['enabled' => false])]);

		$this->assertFalse($this->flow()->getEnabled());
		$this->assertCount(1, $this->published);
		$this->assertCount(1, $this->audit);
	}

	public function testPublishingClearsAnEarlierErrorOnTheFlow(): void {
		$this->publish([$this->schedule()]);
		$this->flow()->setStatus(Flow::STATUS_ERROR);

		$this->publish([$this->schedule()]);

		$this->assertNull($this->flow()->getStatus());
	}

	public function testARemovedScheduleLosesItsFlowWithAnyoneSignedIn(): void {
		$this->publish([$this->schedule()]);
		$this->session = null;

		$this->publish([]);

		$this->assertSame([], $this->flows);
	}

	public function testOnlyTheRemovedScheduleOfTwoLosesItsFlow(): void {
		$this->publish([$this->schedule(), $this->schedule(['id' => 'weekly'])]);
		$this->assertCount(2, $this->flows);

		$this->publish([$this->schedule(['id' => 'weekly'])]);

		$this->assertStringEndsWith('weekly', $this->flow()->getName());
	}

	public function testAFlowAnAuthorDrewForTheApplicationIsNotRemoved(): void {
		$drawn = new Flow();
		$drawn->setUuid('drawn');
		$drawn->setApp('openregister');
		$drawn->setApplicationSlug('app-1');
		$this->flows['drawn'] = $drawn;

		$this->publish([]);

		$this->assertArrayHasKey('drawn', $this->flows);
	}

	public function testDeletingTheApplicationRemovesItsFlows(): void {
		$this->publish([$this->schedule()]);

		$application = $this->object(['slug' => 'crm', 'productionVersion' => self::VERSION], 'app-1');
		$this->listener->handle(new ObjectDeletedEvent($application));

		$this->assertSame([], $this->flows);
	}

	public function testAnUnrelatedSaveOfTheApplicationAppliesNothing(): void {
		$this->publish([$this->schedule()]);
		$this->session = 'quinten';

		$same = ['slug' => 'crm', 'productionVersion' => self::VERSION];
		$after = $this->object($same + ['title' => 'New'], 'app-1');
		$after->setOrganisation(self::ORGANISATION);
		$this->listener->handle(new ObjectUpdatedEvent($after, $this->object($same, 'app-1')));

		$this->assertSame('pieter', $this->flow()->scheduledRunAs());
	}

	public function testChangedSchedulesOnTheProductionVersionApply(): void {
		$this->publish([$this->schedule()]);
		$application = $this->object(['slug' => 'crm', 'productionVersion' => self::VERSION], 'app-1');
		$application->setOrganisation(self::ORGANISATION);
		$prior = $this->version;
		$this->version = $application;

		$data = ['application' => 'app-1', 'manifest' => ['schedules' => [$this->schedule(['cron' => '0 5 * * *'])]]];
		$this->listener->handle(new ObjectUpdatedEvent($this->object($data, self::VERSION), $prior));

		$this->assertSame('0 5 * * *', $this->flow()->getCron());
	}

	public function testChangedSchedulesOnAVersionNotInProductionApplyNothing(): void {
		$this->publish([$this->schedule()]);
		$application = $this->object(['slug' => 'crm', 'productionVersion' => self::VERSION], 'app-1');
		$application->setOrganisation(self::ORGANISATION);
		$prior = $this->object(['manifest' => []], 'version-2');
		$this->version = $application;

		$data = ['application' => 'app-1', 'manifest' => ['schedules' => [$this->schedule(['cron' => '0 5 * * *'])]]];
		$this->listener->handle(new ObjectUpdatedEvent($this->object($data, 'version-2'), $prior));

		$this->assertSame('0 3 * * *', $this->flow()->getCron());
	}

	public function testASameNamedSchemaInAnotherRegisterIsNoPublish(): void {
		$this->slugsAnswer = 'someone-elses-register';

		$this->publish([$this->schedule()]);

		$this->assertSame([], $this->flows);
	}

	public function testAnotherSchemaInTheRegisterIsNoPublish(): void {
		$this->version = $this->object(['manifest' => ['schedules' => [$this->schedule()]]], self::VERSION);
		$after = $this->object(['slug' => 'crm', 'productionVersion' => self::VERSION], 'app-1');
		$after->setSchema('9');
		$after->setOrganisation(self::ORGANISATION);

		$this->listener->handle(new ObjectUpdatedEvent($after, $this->object(['slug' => 'crm'], 'app-1')));

		$this->assertSame([], $this->flows);
	}

	public function testTheRunActsAsThePublisherNotTheManifestOrTheApplicationOwner(): void {
		$this->session = 'quinten';

		$this->publish([$this->schedule(['runAs' => 'pieter', 'owner' => 'pieter'])]);

		$this->assertSame('quinten', $this->flow()->scheduledRunAs());
		$this->assertSame('quinten', $this->flow()->getOwner());
		$this->tick();
		$this->assertSame('quinten', $this->runs[0]->getRunAs());
	}

	public function testPublishingAsSomeoneElseChangesTheIdentityAndAuditsWhoAndWhen(): void {
		$this->publish([$this->schedule()]);
		$this->session = 'quinten';

		$this->publish([$this->schedule()]);

		$this->assertSame('quinten', $this->flow()->scheduledRunAs());
		$this->assertSame([$this->flow()->getUuid(), 'quinten'], $this->published[1]);

		$entry = $this->audit[1];
		$this->assertSame(ScheduleAuditTrail::ACTION, $entry->getAction());
		$this->assertSame('quinten', $entry->getUser());
		$this->assertSame('app-1', $entry->getObjectUuid());
		$this->assertSame(['schedule' => 'nightly', 'from' => 'pieter', 'to' => 'quinten'], $entry->getChanged());
		$this->assertNotNull($entry->getCreated());
	}

	public function testTheFirstPublishIsAuditedToo(): void {
		$this->publish([$this->schedule()]);

		$this->assertSame(['schedule' => 'nightly', 'from' => null, 'to' => 'pieter'], $this->audit[0]->getChanged());
	}

	public function testNoPublisherIsRefusedAndLogged(): void {
		$this->session = null;

		$this->publish([$this->schedule()]);

		$this->assertSame([], $this->flows);
		$this->assertStringContainsString('Nobody signed in', $this->warnings[0]);
	}

	public function testARefusedPublishLeavesEarlierFlowsAlone(): void {
		$this->publish([$this->schedule()]);
		$this->session = null;

		$this->publish([$this->schedule(['cron' => '0 9 * * *'])]);

		$this->assertSame('0 3 * * *', $this->flow()->getCron());
		$this->assertSame('pieter', $this->flow()->scheduledRunAs());
	}

	public function testTheFlowTakesTheApplicationsOrganisation(): void {
		$this->publish([$this->schedule()]);

		$this->assertSame(self::ORGANISATION, $this->flow()->getOrganisation());
	}

	public function testAnApplicationWithNoOrganisationIsRefusedAndLogged(): void {
		$this->publish([$this->schedule()], null);

		$this->assertSame([], $this->flows);
		$this->assertStringContainsString('no organisation', $this->warnings[0]);
	}

	public function testAPublisherOutsideTheOrganisationIsRefusedAndLogged(): void {
		$this->session = 'outsider';

		$this->publish([$this->schedule()]);

		$this->assertSame([], $this->flows);
		$this->assertStringContainsString('not a member', $this->warnings[0]);
	}

	public function testAnActionOffTheMapIsRefusedAndLogged(): void {
		$this->publish([$this->schedule(['action' => 'OCA\\Evil\\Backdoor'])]);

		$this->assertSame([], $this->flows);
		$this->assertStringContainsString('"nightly"', $this->warnings[0]);
	}

	public function testAnIntervalWithNoExactCronIsRefusedAndLogged(): void {
		$schedule = $this->schedule(['interval' => 5400]);
		unset($schedule['cron']);

		$this->publish([$schedule]);

		$this->assertSame([], $this->flows);
		$this->assertStringContainsString('"nightly"', $this->warnings[0]);
	}

	public function testARefusedScheduleKeepsItsEarlierFlow(): void {
		$this->publish([$this->schedule()]);

		$this->publish([$this->schedule(['action' => 'nope'])]);

		$this->assertSame('0 3 * * *', $this->flow()->getCron());
	}

	/**
	 * Publish, as quinten in org-b, an application with the same slug as app-1.
	 *
	 * @param array<int, array<string, mixed>> $schedules The manifest's schedules.
	 *
	 * @return void
	 */
	private function publishSameSlugInOtherOrganisation(array $schedules): void {
		$this->version = $this->object(['application' => 'app-2', 'manifest' => ['schedules' => $schedules]], self::VERSION);
		$after = $this->object(['slug' => 'crm', 'productionVersion' => self::VERSION], 'app-2');
		$after->setOrganisation('org-b');
		$this->session = 'quinten';

		$this->listener->handle(new ObjectUpdatedEvent($after, $this->object(['slug' => 'crm'], 'app-2')));
	}

	/**
	 * The flow of one application.
	 *
	 * @param string $application The application uuid.
	 *
	 * @return array<int, Flow> Its flows.
	 */
	private function flowsOf(string $application): array {
		return array_values(
			array_filter($this->flows, static fn (Flow $flow) => $flow->getApplicationSlug() === $application)
		);
	}

	public function testTheSameSlugInAnotherOrganisationGetsItsOwnFlow(): void {
		$this->publish([$this->schedule()]);

		$this->publishSameSlugInOtherOrganisation([$this->schedule()]);

		$this->assertCount(2, $this->flows);
		$this->assertSame(self::ORGANISATION, $this->flowsOf('app-1')[0]->getOrganisation());
		$this->assertSame('pieter', $this->flowsOf('app-1')[0]->scheduledRunAs());
		$this->assertSame('org-b', $this->flowsOf('app-2')[0]->getOrganisation());
	}

	public function testTheSameSlugInAnotherOrganisationCannotRemoveAFlow(): void {
		$this->publish([$this->schedule()]);

		$this->publishSameSlugInOtherOrganisation([$this->schedule(['id' => 'other'])]);

		$this->assertCount(1, $this->flowsOf('app-1'));
	}

	public function testDeletingTheSameSlugInAnotherOrganisationLeavesAFlow(): void {
		$this->publish([$this->schedule()]);

		$other = $this->object(['slug' => 'crm', 'productionVersion' => self::VERSION], 'app-2');
		$this->listener->handle(new ObjectDeletedEvent($other));

		$this->assertCount(1, $this->flowsOf('app-1'));
	}

	public function testAVersionOfAnotherApplicationIsRefusedAndRemovesNothing(): void {
		$this->publish([$this->schedule()]);

		$foreign = ['application' => 'app-9', 'manifest' => ['schedules' => [$this->schedule(['cron' => '0 9 * * *'])]]];
		$this->version = $this->object($foreign, 'version-2');
		$after = $this->object(['slug' => 'crm', 'productionVersion' => 'version-2'], 'app-1');
		$after->setOrganisation(self::ORGANISATION);
		$before = $this->object(['slug' => 'crm', 'productionVersion' => self::VERSION], 'app-1');
		$this->listener->handle(new ObjectUpdatedEvent($after, $before));

		$this->assertSame('0 3 * * *', $this->flow()->getCron());
		$this->assertStringContainsString('another application', $this->refusals->all()[0]);
	}

	public function testAVersionThatCannotBeReadRemovesNothing(): void {
		$this->publish([$this->schedule()]);

		$this->version = null;
		$after = $this->object(['slug' => 'crm', 'productionVersion' => 'version-2'], 'app-1');
		$before = $this->object(['slug' => 'crm', 'productionVersion' => self::VERSION], 'app-1');
		$this->listener->handle(new ObjectUpdatedEvent($after, $before));

		$this->assertCount(1, $this->flows);
		$this->assertCount(1, $this->refusals->all());
	}

	public function testAnApplicationMovedToAnotherOrganisationTakesItsFlowAlong(): void {
		$this->members = ['pieter'];
		$this->publish([$this->schedule()]);

		$this->publish([$this->schedule()], 'org-b');

		$this->assertSame('org-b', $this->flow()->getOrganisation());
		$this->assertCount(1, $this->published);
	}

	public function testAnAuditFailureChangesNoIdentityAndLeavesTheOtherSchedules(): void {
		$this->publish([$this->schedule()]);
		$this->auditFails = true;
		$this->session = 'quinten';

		$this->publish([$this->schedule()]);

		$this->assertSame('pieter', $this->flow()->scheduledRunAs());
		$this->assertStringContainsString('audit down', $this->refusals->all()[0]);
	}

	public function testARefusalIsKeptForTheResponse(): void {
		$this->session = null;

		$this->publish([$this->schedule()]);

		$this->assertSame(['Nobody signed in published it.'], $this->refusals->all());
	}

	public function testAMemberWhoLeftStopsTheScheduleWithTheReasonOnTheFlow(): void {
		$this->publish([$this->schedule()]);
		$this->members = ['quinten'];

		$this->assertSame([], $this->tick());

		$this->assertSame([], $this->runs);
		$this->assertFalse($this->flow()->getEnabled());
		$this->assertSame(Flow::STATUS_ERROR, $this->flow()->getStatus());
		$this->assertStringContainsString('"pieter"', (string)$this->flow()->getStatusMessage());
	}

	public function testPublishingAgainRestoresAScheduleStoppedForItsTenant(): void {
		$this->publish([$this->schedule()]);
		$this->members = ['quinten'];
		$this->tick();
		$this->session = 'quinten';

		$this->publish([$this->schedule()]);

		$this->assertTrue($this->flow()->getEnabled());
		$this->assertNull($this->flow()->getStatus());
		$this->tick();
		$this->assertSame('quinten', $this->runs[0]->getRunAs());
	}

	public function testAFlowAnAuthorDrewIsNotAskedForMembership(): void {
		$this->publish([$this->schedule()]);
		$this->flow()->setApp('openregister');
		$this->members = [];

		$this->assertCount(1, $this->tick());
	}
}
