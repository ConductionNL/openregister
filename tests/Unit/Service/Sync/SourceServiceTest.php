<?php

/**
 * SourceService: the validation of a source write and the flow that follows it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sync
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Sync;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapperInterface;
use OCA\OpenRegister\Service\Sync\SourceFetcherInterface;
use OCA\OpenRegister\Service\Sync\SourceFetcherLookupInterface;
use OCA\OpenRegister\Service\Sync\SourceHarvestFlow;
use OCA\OpenRegister\Service\Sync\SourceRefused;
use OCA\OpenRegister\Service\Sync\SourceService;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Sync\SourceService
 * @covers \OCA\OpenRegister\Service\Sync\SourceRefused
 * @uses   \OCA\OpenRegister\Db\Source
 * @uses   \OCA\OpenRegister\Service\Dmn\DecisionTableValidator
 * @uses   \OCA\OpenRegister\Service\Oas\OasRequestValidator
 * @uses   \OCA\OpenRegister\Service\Sync\HarvestTombstonePass
 * @uses   \OCA\OpenRegister\Service\Sync\HarvestPipelineService
 */
class SourceServiceTest extends TestCase {

	/** @var SourceMapperInterface&MockObject */
	private SourceMapperInterface $sources;

	/** @var SourceFetcherLookupInterface&MockObject */
	private SourceFetcherLookupInterface $fetchers;

	/** @var IUserManager&MockObject */
	private IUserManager $users;

	/** @var SourceHarvestFlow&MockObject */
	private SourceHarvestFlow $flows;

	private SourceService $service;

	protected function setUp(): void {
		$this->sources  = $this->createMock(SourceMapperInterface::class);
		$this->fetchers = $this->createMock(SourceFetcherLookupInterface::class);
		$this->users    = $this->createMock(IUserManager::class);
		$this->flows    = $this->getMockBuilder(SourceHarvestFlow::class)->disableOriginalConstructor()->getMock();

		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($admin);

		$this->service = new SourceService(
			sources: $this->sources,
			fetchers: $this->fetchers,
			users: $this->users,
			session: $session,
			flows: $this->flows
		);
	}

	private function refusal(array $fields): SourceRefused {
		try {
			$this->service->validate($fields);
		} catch (SourceRefused $refused) {
			return $refused;
		}

		$this->fail('Expected a refusal');
	}

	private function enabledUser(string $uid, bool $enabled=true): void {
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn($enabled);
		$this->users->method('get')->willReturnCallback(static fn (string $id): ?IUser => ($id === $uid) ? $user : null);
	}

	private function fetcherWithSchema(array $schema): void {
		$fetcher = $this->createMock(SourceFetcherInterface::class);
		$fetcher->method('getConfigSchema')->willReturn($schema);
		$this->fetchers->method('get')->with('app.proto')->willReturn($fetcher);
	}

	public function testAScheduleWithoutRunAsIsRefusedOnRunAs(): void {
		$refused = $this->refusal(['schedule' => '0 * * * *']);

		$this->assertSame('runAs', $refused->field);
		$this->assertSame(['error' => $refused->getMessage(), 'field' => 'runAs'], $refused->toArray());
	}

	public function testANonCronScheduleIsRefused(): void {
		$this->assertSame('schedule', $this->refusal(['schedule' => 'every hour', 'runAs' => 'harvester'])->field);
	}

	public function testARunAsThatIsMissingOrDisabledIsRefused(): void {
		$this->enabledUser('harvester', enabled: false);

		$this->assertSame('runAs', $this->refusal(['schedule' => '0 * * * *', 'runAs' => 'harvester'])->field);
		$this->assertSame('runAs', $this->refusal(['runAs' => 'nobody'])->field);
	}

	public function testAScheduleWithAnEnabledRunAsPasses(): void {
		$this->enabledUser('harvester');

		$this->service->validate(['schedule' => '0 * * * *', 'runAs' => 'harvester']);
		$this->addToAssertionCount(1);
	}

	public function testAConfigFailingTheFetcherSchemaIsRefusedNamingTheField(): void {
		$this->fetcherWithSchema([
			'type' => 'object',
			'properties' => ['url' => ['type' => 'string']],
			'required' => ['url'],
		]);

		$refused = $this->refusal(['type' => 'app.proto', 'config' => ['url' => 42]]);
		$this->assertSame('config.url', $refused->field);

		$this->service->validate(['type' => 'app.proto', 'config' => ['url' => 'https://example.org/feed']]);
		$this->assertSame('config', $this->refusal(['type' => 'app.proto', 'config' => []])->field);
	}

	public function testAConfigThatIsNotAnObjectIsRefused(): void {
		$this->assertSame('config', $this->refusal(['config' => 'url=x'])->field);
	}

	public function testATypeWithoutAFetcherIsNotChecked(): void {
		$this->fetchers->method('get')->willReturn(null);

		$this->service->validate(['type' => 'gone.proto', 'config' => ['anything' => true]]);
		$this->addToAssertionCount(1);
	}

	public function testConflictRulesTheEvaluatorCannotRunAreRefused(): void {
		$refused = $this->refusal(['conflictRules' => ['hitPolicy' => 'FIRST', 'inputs' => [], 'outputs' => [], 'rules' => []]]);

		$this->assertSame('conflictRules', $refused->field);
		$this->assertStringContainsString('no inputs', $refused->getMessage());
		$this->assertSame('conflictRules', $this->refusal(['conflictRules' => 'source-wins'])->field);
	}

	public function testHardDeleteIsRefusedForAnAppsSourceOnly(): void {
		$this->assertSame('deleteStrategy', $this->refusal(['deleteStrategy' => 'hard-delete', 'application' => 'opencatalogi'])->field);
		$this->assertSame('deleteStrategy', $this->refusal(['deleteStrategy' => 'shred'])->field);

		$this->service->validate(['deleteStrategy' => 'hard-delete']);
		$this->addToAssertionCount(1);
	}

	public function testMaxItemsPerRunMustBeWithinTheCeiling(): void {
		$this->assertSame('maxItemsPerRun', $this->refusal(['maxItemsPerRun' => 0])->field);
		$this->assertSame('maxItemsPerRun', $this->refusal(['maxItemsPerRun' => 10001])->field);
		$this->assertSame('maxItemsPerRun', $this->refusal(['maxItemsPerRun' => 2.5])->field);

		$this->service->validate(['maxItemsPerRun' => 10000]);
		$this->addToAssertionCount(1);
	}

	public function testCreateDefaultsToFlagAndStoresTheFlowId(): void {
		$this->enabledUser('harvester');
		$stored = new Source();
		$stored->setId(3);
		$stored->setUuid('src-1');

		$this->sources->expects($this->once())->method('createFromArray')
			->with(['schedule' => '0 * * * *', 'runAs' => 'harvester', 'deleteStrategy' => 'flag'])
			->willReturn($stored);
		$this->flows->expects($this->once())->method('sync')->with($stored, 'admin')->willReturn('src-1');
		$this->sources->expects($this->once())->method('update')->with($stored)->willReturnArgument(0);

		$result = $this->service->create(['schedule' => '0 * * * *', 'runAs' => 'harvester', 'flowId' => 'forged']);

		$this->assertSame('src-1', $result->getFlowId());
	}

	public function testAnUnscheduledCreateWritesNoFlowAndNoSecondUpdate(): void {
		$stored = new Source();
		$this->sources->method('createFromArray')->willReturn($stored);
		$this->flows->method('sync')->willReturn(null);
		$this->sources->expects($this->never())->method('update');

		$this->assertSame($stored, $this->service->create(['deleteStrategy' => 'soft-delete']));
	}

	public function testUpdateValidatesTheMergedSource(): void {
		$current = new Source();
		$current->setSchedule('0 * * * *');
		$current->setRunAs('harvester');
		$this->sources->method('find')->with(5)->willReturn($current);
		$this->sources->expects($this->never())->method('updateFromArray');

		$this->expectException(SourceRefused::class);
		$this->service->update(5, ['runAs' => '']);
	}

	public function testUpdateClearsTheFlowIdWhenTheScheduleGoes(): void {
		$current = new Source();
		$current->setFlowId('src-1');
		$this->sources->method('find')->willReturn($current);
		$this->sources->method('updateFromArray')->with(5, ['schedule' => null])->willReturn($current);
		$this->flows->method('sync')->willReturn(null);
		$this->sources->expects($this->once())->method('update')->with($current)->willReturnArgument(0);

		$this->assertNull($this->service->update(5, ['schedule' => null])->getFlowId());
	}

	public function testATriggerRejectionIsRefusedOnSchedule(): void {
		$this->sources->method('createFromArray')->willReturn(new Source());
		$this->flows->method('sync')->willThrowException(new InvalidArgumentException('cron rejected'));

		try {
			$this->service->create([]);
			$this->fail('Expected a refusal');
		} catch (SourceRefused $refused) {
			$this->assertSame('schedule', $refused->field);
			$this->assertSame('cron rejected', $refused->getMessage());
		}
	}

	public function testDeleteRemovesTheFlowThenTheSource(): void {
		$source = new Source();
		$order  = [];
		$this->sources->method('find')->with(5)->willReturn($source);
		$this->flows->expects($this->once())->method('remove')->with($source)
			->willReturnCallback(static function () use (&$order): void {
				$order[] = 'flow';
			});
		$this->sources->expects($this->once())->method('delete')->with($source)
			->willReturnCallback(static function (Source $s) use (&$order): Source {
				$order[] = 'source';
				return $s;
			});

		$this->assertSame($source, $this->service->delete(5));
		$this->assertSame(['flow', 'source'], $order);
	}
}
