<?php

/**
 * Behaviour of `occ openregister:drafts:expired` over a stubbed draft sweep.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use DateTimeImmutable;
use OCA\OpenRegister\Command\DraftsExpiredCommand;
use OCA\OpenRegister\Service\Object\DraftExpiryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command prints what preview() counted, as text or JSON, and never sweeps.
 */
class DraftsExpiredCommandBehaviourTest extends TestCase {

	/**
	 * A command whose container returns a sweep that previews the given counts.
	 *
	 * @param array<string, mixed> $counts The preview result.
	 */
	private function tester(array $counts): CommandTester {
		$expiry = $this->createMock(DraftExpiryInterface::class);
		$expiry->expects($this->once())
			->method('preview')
			->with($this->isInstanceOf(DateTimeImmutable::class))
			->willReturn($counts);
		$expiry->expects($this->never())->method('sweep');

		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())->method('get')->with(DraftExpiryInterface::class)->willReturn($expiry);

		return new CommandTester(new DraftsExpiredCommand(container: $container));
	}//end tester()

	/**
	 * One preview row.
	 */
	private function row(string $register, string $schema, int $lifetime, int $expired): array {
		return ['register' => $register, 'schema' => $schema, 'lifetimeDays' => $lifetime, 'expired' => $expired, 'removed' => 0, 'refused' => 0, 'deferred' => $expired];
	}//end row()

	/**
	 * The text report lists each schema and caps the next run at MAX_REMOVALS.
	 */
	public function testTextListsEachSchemaAndCapsTheNextRun(): void {
		$tester = $this->tester(
			['schemas' => 2, 'expired' => 1500, 'removed' => 0, 'refused' => 0, 'deferred' => 1500, 'perSchema' => [$this->row('service', 'case', 30, 1400), $this->row('hr', 'leave', 7, 100)]]
		);

		$this->assertSame(0, $tester->execute([]));

		$this->assertSame(
			"Schemas whose drafts expire: 2. Nothing was removed.\n"
			. "  service / case: 1400 drafts unchanged for more than 30 days\n"
			. "  hr / leave: 100 drafts unchanged for more than 7 days\n"
			. "The next run of the draft cleanup removes 1000 drafts, at most 1000 per run.\n"
			. "To keep the drafts of a schema, set \"draftLifetimeDays\": false in its configuration. A number sets another lifetime in days.\n",
			$tester->getDisplay()
		);
	}//end testTextListsEachSchemaAndCapsTheNextRun()

	/**
	 * Below the cap, the next run removes all the expired drafts.
	 */
	public function testTextBelowTheCapNamesTheExpiredCount(): void {
		$tester = $this->tester(
			['schemas' => 1, 'expired' => 3, 'removed' => 0, 'refused' => 0, 'deferred' => 3, 'perSchema' => [$this->row('service', 'case', 30, 3)]]
		);

		$this->assertSame(0, $tester->execute([]));

		$this->assertStringContainsString('The next run of the draft cleanup removes 3 drafts, at most 1000 per run.', $tester->getDisplay());
	}//end testTextBelowTheCapNamesTheExpiredCount()

	/**
	 * Nothing expired: one reassuring line and no per-schema rows or advice.
	 */
	public function testTextWithNothingExpiredStopsAfterTheReassurance(): void {
		$tester = $this->tester(
			['schemas' => 1, 'expired' => 0, 'removed' => 0, 'refused' => 0, 'deferred' => 0, 'perSchema' => [$this->row('service', 'case', 30, 0)]]
		);

		$this->assertSame(0, $tester->execute([]));

		$this->assertSame(
			"Schemas whose drafts expire: 1. Nothing was removed.\nNo draft is past its lifetime.\n",
			$tester->getDisplay()
		);
	}//end testTextWithNothingExpiredStopsAfterTheReassurance()

	/**
	 * --json prints only the four row fields, dropping removed/refused/deferred.
	 */
	public function testJsonKeepsOnlyTheRowFieldsAnAdministratorNeeds(): void {
		$tester = $this->tester(
			['schemas' => 2, 'expired' => 5, 'removed' => 0, 'refused' => 0, 'deferred' => 5, 'perSchema' => [$this->row('service', 'case', 30, 4), $this->row('hr', 'leave', 7, 1)]]
		);

		$this->assertSame(0, $tester->execute(['--json' => true]));

		$this->assertSame(
			'{"schemas":2,"expired":5,"perSchema":[{"register":"service","schema":"case","lifetimeDays":30,"expired":4},{"register":"hr","schema":"leave","lifetimeDays":7,"expired":1}]}' . "\n",
			$tester->getDisplay()
		);
	}//end testJsonKeepsOnlyTheRowFieldsAnAdministratorNeeds()

	/**
	 * --json with nothing to report prints an empty list, not the text reassurance.
	 */
	public function testJsonWithNoSchemasPrintsAnEmptyList(): void {
		$tester = $this->tester(['schemas' => 0, 'expired' => 0, 'removed' => 0, 'refused' => 0, 'deferred' => 0, 'perSchema' => []]);

		$this->assertSame(0, $tester->execute(['--json' => true]));

		$this->assertSame('{"schemas":0,"expired":0,"perSchema":[]}' . "\n", $tester->getDisplay());
	}//end testJsonWithNoSchemasPrintsAnEmptyList()

	/**
	 * Listing the command does not resolve the sweep from the container.
	 */
	public function testConstructingDoesNotResolveTheSweep(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$command = new DraftsExpiredCommand(container: $container);

		$this->assertSame('openregister:drafts:expired', $command->getName());
		$this->assertTrue($command->getDefinition()->hasOption('json'));
	}//end testConstructingDoesNotResolveTheSweep()
}//end class
