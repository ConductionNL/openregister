<?php

/**
 * An internal run that reveals audited values leaves one entry naming the run.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Lifecycle\StateFieldLists;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRules;
use OCA\OpenRegister\Service\PropertyRbacCaller;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\PropertyRbacRules;
use OCA\OpenRegister\Service\Rbac\ProcessRevealAudit;
use OCA\OpenRegister\Service\Rbac\PropertyReveal;
use OCA\OpenRegister\Service\Rbac\RevealCollector;
use OCA\OpenRegister\Service\Rbac\RevealFlusherInterface;
use OCA\OpenRegister\Service\Rbac\RevealingProcess;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ProcessRevealAuditTest extends TestCase {

	/**
	 * The collector under test.
	 *
	 * @var RevealCollector
	 */
	private RevealCollector $collector;

	protected function setUp(): void {
		parent::setUp();
		$this->collector = new RevealCollector();
	}//end setUp()

	private function processEntries(): array {
		return array_values(array_filter($this->collector->take(), static fn (array $e): bool => isset($e['process']) === true));
	}//end processEntries()

	public function testARunThatRevealedNothingLeavesNoEntry(): void {
		$this->collector->beginProcess(new RevealingProcess('export', 'r1'));
		$this->collector->endProcess();

		$this->assertSame([], $this->processEntries());
	}//end testARunThatRevealedNothingLeavesNoEntry()

	public function testEveryRevealInARunIsCountedOnOneEntry(): void {
		$this->collector->beginProcess(new RevealingProcess('export', 'r1'));
		$this->collector->recordReveal(new PropertyReveal(userId: 'ann', objectUuid: 'o1', property: 'bsn', schemaId: 1));
		$this->collector->recordReveal(new PropertyReveal(userId: '', objectUuid: 'o2', property: 'bsn', schemaId: 1));
		$this->collector->countProcessReveals(3);
		$this->collector->endProcess();

		$entries = $this->processEntries();
		$this->assertCount(1, $entries);
		$this->assertSame('export', $entries[0]['process']);
		$this->assertSame('r1', $entries[0]['run']);
		$this->assertSame(5, $entries[0]['revealed']);
	}//end testEveryRevealInARunIsCountedOnOneEntry()

	public function testANestedRunCountsForItselfOnly(): void {
		$this->collector->beginProcess(new RevealingProcess('flow', 'outer'));
		$this->collector->countProcessReveals(1);
		$this->collector->beginProcess(new RevealingProcess('export', 'inner'));
		$this->collector->countProcessReveals(2);
		$this->collector->endProcess();
		$this->collector->endProcess();

		$byRun = array_column($this->processEntries(), 'revealed', 'run');
		$this->assertSame(['inner' => 2, 'outer' => 1], $byRun);
	}//end testANestedRunCountsForItselfOnly()

	public function testOutsideARunNothingIsCounted(): void {
		$this->collector->countProcessReveals(4);

		$this->assertFalse($this->collector->inProcess());
		$this->assertSame([], $this->processEntries());
	}//end testOutsideARunNothingIsCounted()

	public function testTheRunIsRecordedAndFlushedEvenWhenItFails(): void {
		$flusher = $this->createMock(RevealFlusherInterface::class);
		$flusher->expects($this->exactly(2))->method('flush')->willReturnCallback(fn (): int => count($this->collector->take()));
		$audit = new ProcessRevealAudit($this->collector, $flusher);

		$this->assertSame('done', $audit->run(new RevealingProcess('aggregation', 'a1'), fn (): string => 'done'));

		try {
			$audit->run(
				new RevealingProcess('aggregation', 'a2'),
				function (): void {
					$this->collector->countProcessReveals(1);
					throw new RuntimeException('boom');
				}
			);
			$this->fail('The failure is not swallowed');
		} catch (RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}

		$this->assertFalse($this->collector->inProcess());
	}//end testTheRunIsRecordedAndFlushedEvenWhenItFails()

	public function testAnAdministratorsReadInARunIsCounted(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('hasPropertyAuthorization')->willReturn(true);
		$schema->method('getPropertiesWithAuthorization')->willReturn(
			[
				'bsn' => ['read' => ['hr'], 'audit' => true],
				'iban' => ['read' => ['hr'], 'audit' => true],
				'note' => ['read' => ['hr']],
			]
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('root');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['admin']);

		$states = $this->createMock(StateFieldRuleResolver::class);
		$states->method('resolve')->willReturn(new StateFieldRules(state: null, fields: new StateFieldLists(hidden: [])));

		$handler = new PropertyRbacHandler(
			caller: new PropertyRbacCaller($session, $groups),
			rules: new PropertyRbacRules($this->createMock(ConditionMatcher::class), $states),
			logger: $this->createMock(LoggerInterface::class),
			reveals: $this->collector
		);
		$object = ['id' => 'o1', 'bsn' => '111222333', 'note' => 'x'];

		$handler->filterReadableProperties($schema, $object);
		$this->assertSame([], $this->collector->take(), 'Outside a run an administrator read is unchanged');

		$this->collector->beginProcess(new RevealingProcess('export', 'r1'));
		$this->assertSame($object, $handler->filterReadableProperties($schema, $object));
		$this->collector->endProcess();

		$entries = $this->processEntries();
		$this->assertCount(1, $entries);
		$this->assertSame(1, $entries[0]['revealed']);
	}//end testAnAdministratorsReadInARunIsCounted()
}//end class
