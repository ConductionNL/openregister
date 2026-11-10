<?php

declare(strict_types=1);

/**
 * Golden tests pinning the anonymisation outcomes: what is refused, what is changed, what is kept.
 *
 * Written against the code before a maintainability refactor, so the refactor
 * can prove that no refusal, treatment, report or marker moved. Clock-dependent
 * moments are checked for format and then masked.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Archival\AnonymisedPayload;
use OCA\OpenRegister\Service\Archival\AnonymisationPlan;
use OCA\OpenRegister\Service\Archival\AnonymisationPlanner;
use OCA\OpenRegister\Service\Archival\AnonymisationRefusedException;
use OCA\OpenRegister\Service\Archival\AnonymisationRun;
use OCA\OpenRegister\Service\Archival\AnonymisationSubject;
use OCA\OpenRegister\Service\Archival\AnonymisationService;
use OCA\OpenRegister\Service\Archival\AnonymisationSweep;
use OCA\OpenRegister\Service\Archival\AnonymisationTarget;
use OCA\OpenRegister\Service\Archival\SaltedProfile;
use OCA\OpenRegister\Service\Archival\PayloadChange;
use OCA\OpenRegister\Service\Archival\AnonymisationClearance;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden outcomes of anonymisation.
 */
class AnonymisationGoldenTest extends TestCase {

	/**
	 * The annotation used throughout: one rule per treatment.
	 *
	 * @return array<string, mixed> The annotation.
	 */
	private function annotation(): array {
		return [
			'anonymisation' => [
				'name' => 'remove',
				'city' => ['treatment' => 'fixed', 'value' => 'X'],
				'bsn' => 'pseudonym',
				'born' => ['treatment' => 'generalise', 'grain' => 'year'],
				'moved' => ['treatment' => 'generalise', 'grain' => 'month'],
				'postcode' => ['treatment' => 'generalise', 'grain' => 'postcode_district'],
			],
		];
	}//end annotation()

	/**
	 * A payload carrying every property in the annotation, plus kept ones.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function payload(): array {
		return [
			'zaak' => 'Z-1',
			'name' => 'Jan',
			'city' => 'Utrecht',
			'bsn' => ' 123456782 ',
			'born' => '1980-05-17',
			'moved' => '2020-11-03T10:00:00+00:00',
			'postcode' => '3511 AB',
			'note' => 'kept',
		];
	}//end payload()

	public function testTheServiceTreatsEveryPropertyAndReportsBothLists(): void {
		$service = new AnonymisationService();
		$planner = new AnonymisationPlanner();
		$after = $service->apply(payload: $this->payload(), profile: new SaltedProfile(rules: $planner->profileOf($this->annotation()), salt: 'salt'));

		$pseudonym = 'anon-' . substr(hash('sha256', 'salt::123456782'), 0, 16);
		$this->assertSame(
			['zaak' => 'Z-1', 'city' => 'X', 'bsn' => $pseudonym, 'born' => '1980', 'moved' => '2020-11', 'postcode' => '3511', 'note' => 'kept'],
			$after
		);

		$report = $service->report(change: new PayloadChange(before: $this->payload(), after: $after), profileName: 'p');
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $report['anonymisedAt']);
		unset($report['anonymisedAt']);
		$this->assertSame(
			[
				'profile' => 'p',
				'changed' => ['born' => '1980', 'bsn' => $pseudonym, 'city' => 'X', 'moved' => '2020-11', 'name' => '__removed__', 'postcode' => '3511'],
				'kept' => ['note', 'zaak'],
				'keptCount' => 2,
			],
			$report
		);
	}//end testTheServiceTreatsEveryPropertyAndReportsBothLists()

	public function testUnparseableOrOddValuesBecomeNullOrPassThrough(): void {
		$service = new AnonymisationService();
		$profile = [
			'a' => ['treatment' => 'generalise', 'grain' => 'year'],
			'b' => ['treatment' => 'generalise', 'grain' => 'year'],
			'c' => ['treatment' => 'generalise', 'grain' => 'postcode_district'],
			'd' => ['treatment' => 'generalise', 'grain' => 'month'],
			'e' => ['treatment' => 'pseudonym'],
			'f' => ['treatment' => 'fixed'],
			'g' => ['treatment' => 'unknown'],
			'h' => [],
			'i' => ['treatment' => 'generalise'],
			'missing' => ['treatment' => 'remove'],
		];
		$payload = ['a' => ['x'], 'b' => '  ', 'c' => 'no digits', 'd' => 'not a date', 'e' => ['arr'], 'f' => 'v', 'g' => 'same', 'h' => 'same', 'i' => '2001-02-03'];

		$this->assertSame(
			[
				'a' => null, 'b' => null, 'c' => null, 'd' => null,
				'e' => 'anon-' . substr(hash('sha256', 's::'), 0, 16),
				'f' => null, 'g' => 'same', 'h' => 'same', 'i' => '2001',
			],
			$service->apply(payload: $payload, profile: new SaltedProfile(rules: $profile, salt: 's'))
		);
		$this->assertSame(['c' => '1234'], $service->apply(payload: ['c' => 1234567], profile: new SaltedProfile(rules: ['c' => $profile['c']], salt: 's')));
	}//end testUnparseableOrOddValuesBecomeNullOrPassThrough()

	public function testThePlannerReadsTheProfileAndRefusesWhatCannotBeCarriedOut(): void {
		$planner = new AnonymisationPlanner();
		$this->assertSame([], $planner->profileOf(['anonymisation' => 'no']));
		$this->assertSame(['a' => ['treatment' => 'remove'], 'b' => ['treatment' => null]], $planner->profileOf(['anonymisation' => ['a' => 'remove', 0 => 'x', '' => 'y', 'b' => null]]));

		$annotation = [
			'anonymisation' => [
				'ghost' => 'remove',
				'a' => 'shred',
				'b' => ['treatment' => ['x']],
				'c' => ['treatment' => 'fixed'],
				'd' => ['treatment' => 'fixed', 'value' => null],
				'e' => ['treatment' => 'generalise', 'grain' => 'week'],
				'f' => 'generalise',
				'g' => ['treatment' => 'generalise', 'grain' => 'month'],
				'h' => 'fixed',
			],
		];
		$refusals = [
			'The anonymisation profile names "ghost", which this schema does not declare. It would be skipped and the run would report success over a record that still holds it.',
			'The anonymisation profile gives "a" the treatment "shred", which is not one of: remove, fixed, pseudonym, generalise.',
			'The anonymisation profile gives "b" the treatment "array", which is not one of: remove, fixed, pseudonym, generalise.',
			'The anonymisation profile gives "c" the treatment "fixed" without saying which value to write.',
			'The anonymisation profile generalises "e" to "week", which is not one of: year, month, postcode_district.',
			'The anonymisation profile generalises "f" to "NULL", which is not one of: year, month, postcode_district.',
			'The anonymisation profile gives "h" the treatment "fixed" without saying which value to write.',
		];
		$this->assertSame($refusals, $planner->refusals($annotation, ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']));
		$this->assertSame(['changed' => ['a', 'g'], 'kept' => ['x', 'z']], $planner->plan($annotation, ['z' => 1, 'g' => 1, 'a' => 1, 'x' => 1]));

		$planner->assertSound($this->annotation(), array_keys($this->payload()));
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(implode(' ', $refusals));
		$planner->assertSound($annotation, ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']);
	}//end testThePlannerReadsTheProfileAndRefusesWhatCannotBeCarriedOut()

	public function testTheRunRefusesHeldFinishedAndResaltedRecords(): void {
		$run = new AnonymisationRun();
		$fp = $run->fingerprint('salt');
		$this->assertSame(substr(hash('sha256', 'anonymisation-salt::salt'), 0, 12), $fp);

		$this->assertSame(
			'This record is under a legal hold (no reason was recorded with the hold), so it is not anonymised. A hold says somebody may still need it as it is, and as it is includes the name.',
			$run->refuse(new AnonymisationClearance(marker: ['state' => 'complete'], hasLegalHold: true, holdReason: '  ', saltFingerprint: $fp))
		);
		$this->assertSame(
			'This record is under a legal hold (WOO-1), so it is not anonymised. A hold says somebody may still need it as it is, and as it is includes the name.',
			$run->refuse(new AnonymisationClearance(marker: [], hasLegalHold: true, holdReason: ' WOO-1 ', saltFingerprint: $fp))
		);
		$this->assertSame(
			'This record was already anonymised on an unrecorded date under the profile "unnamed". Running again would change nothing it has not changed, and under a rotated salt it would give its pseudonyms new values, so rows that used to join would stop joining with nothing on screen to say so.',
			$run->refuse(new AnonymisationClearance(marker: ['state' => 'complete'], hasLegalHold: false, holdReason: '', saltFingerprint: $fp))
		);
		$this->assertStringContainsString('on 2026-01-01 under the profile "p"', (string)$run->refuse(new AnonymisationClearance(marker: ['state' => 'complete', 'anonymisedAt' => '2026-01-01', 'profile' => 'p'], hasLegalHold: false, holdReason: '', saltFingerprint: $fp)));
		$this->assertSame(
			'This record was left half anonymised under a different salt. Finishing it now would leave one record carrying two families of pseudonym, so it needs the original salt or a decision to start again.',
			$run->refuse(new AnonymisationClearance(marker: ['state' => 'in_progress', 'saltFingerprint' => 'other'], hasLegalHold: false, holdReason: '', saltFingerprint: $fp))
		);
		$this->assertNull($run->refuse(new AnonymisationClearance(marker: ['state' => 'in_progress', 'saltFingerprint' => $fp], hasLegalHold: false, holdReason: '', saltFingerprint: $fp)));
		$this->assertNull($run->refuse(new AnonymisationClearance(marker: [], hasLegalHold: false, holdReason: '', saltFingerprint: $fp)));
	}//end testTheRunRefusesHeldFinishedAndResaltedRecords()

	public function testThePlanCarriesBothListsAndRefusesANoOp(): void {
		$run = new AnonymisationRun();
		$plan = $run->plan(new AnonymisationSubject('u1', $this->payload(), $this->annotation(), 'p'), 'salt');
		$this->assertSame('u1', $plan->objectUuid);
		$this->assertSame('p', $plan->profileName);
		$this->assertSame($this->payload(), $plan->before);
		$this->assertSame(['born', 'bsn', 'city', 'moved', 'name', 'postcode'], $plan->changedProperties);
		$this->assertSame(['note', 'zaak'], $plan->keptProperties);
		$this->assertSame($run->fingerprint('salt'), $plan->saltFingerprint);

		try {
			$run->plan(new AnonymisationSubject('u2', ['zaak' => 'Z'], ['anonymisation' => ['zaak' => ['treatment' => 'fixed', 'value' => 'Z']]]), 'salt');
			$this->fail('expected a refusal');
		} catch (AnonymisationRefusedException $e) {
			$this->assertSame('Anonymising u2 would change nothing: the declared profile touches no property this record carries.', $e->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);
		$run->plan(new AnonymisationSubject('u3', ['zaak' => 'Z'], ['anonymisation' => ['ghost' => 'remove']]), 'salt');
	}//end testThePlanCarriesBothListsAndRefusesANoOp()

	/**
	 * A recording target.
	 *
	 * @param string            $name    Its name.
	 * @param array<int,string> $log     Where calls are recorded.
	 * @param string|null       $failOn  'prepare' or 'apply' to throw there.
	 *
	 * @return AnonymisationTarget The target.
	 */
	private function target(string $name, array &$log, ?string $failOn = null): AnonymisationTarget {
		$target = $this->createMock(AnonymisationTarget::class);
		$target->method('name')->willReturn($name);
		foreach (['prepare', 'apply'] as $step) {
			$target->method($step)->willReturnCallback(
				function () use ($name, $step, $failOn, &$log): void {
					$log[] = $step . ':' . $name;
					if ($failOn === $step) {
						throw new RuntimeException($name . ' down');
					}
				}
			);
		}

		return $target;
	}//end target()

	public function testApplyPreparesEverythingBeforeWritingAndSaysHowFarItGot(): void {
		$run = new AnonymisationRun();
		$plan = new AnonymisationPlan('u', 'p', new AnonymisedPayload([], [], ['a'], ['b']), 'fp');

		$log = [];
		$marker = $run->apply($plan, [$this->target('db', $log), $this->target('index', $log)]);
		$this->assertSame(['prepare:db', 'prepare:index', 'apply:db', 'apply:index'], $log);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $marker['anonymisedAt']);
		$marker['anonymisedAt'] = '<now>';
		$this->assertSame(['state' => 'complete', 'profile' => 'p', 'saltFingerprint' => 'fp', 'anonymisedAt' => '<now>', 'changed' => ['a'], 'kept' => ['b']], $marker);

		$messages = [];
		$cases = [
			[[], []],
			[['db' => null, 'index' => 'prepare'], ['prepare:db', 'prepare:index']],
			[['db' => 'apply', 'index' => null], ['prepare:db', 'prepare:index', 'apply:db']],
			[['db' => null, 'index' => null, 'files' => 'apply'], ['prepare:db', 'prepare:index', 'prepare:files', 'apply:db', 'apply:index', 'apply:files']],
		];
		foreach ($cases as [$spec, $expectedLog]) {
			$log = [];
			$targets = [];
			foreach ($spec as $name => $failOn) {
				$targets[] = $this->target($name, $log, $failOn);
			}

			try {
				$run->apply($plan, $targets);
				$this->fail('expected a refusal');
			} catch (AnonymisationRefusedException $e) {
				$messages[] = $e->getMessage();
				$this->assertSame($expectedLog, $log);
			}
		}

		$this->assertSame(
			[
				'Anonymising u was asked for with no stores to reach.',
				'Anonymising u was stopped before anything was written: index could not be reached (index down). The record is unchanged.',
				'Anonymising u was interrupted after no store was written. The record is marked in progress and can be finished by running it again with the same salt; it must not be treated as anonymised until it is. db failed: db down',
				'Anonymising u was interrupted after writing db, index. The record is marked in progress and can be finished by running it again with the same salt; it must not be treated as anonymised until it is. files failed: files down',
			],
			$messages
		);
	}//end testApplyPreparesEverythingBeforeWritingAndSaysHowFarItGot()

	public function testTheSweepAnonymisesWhatMayBeAndCountsTheRest(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logged = [];
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level, &$logged): void {
					$logged[] = [$level, $message, $context];
				}
			);
		}

		$sweep = new AnonymisationSweep(run: new AnonymisationRun(), logger: $logger);
		$log = [];
		$candidates = [
			['uuid' => ''],
			['uuid' => 'held', 'hasLegalHold' => true, 'holdReason' => 'case'],
			['uuid' => 'ok', 'payload' => $this->payload(), 'annotation' => $this->annotation(), 'profileName' => 'p'],
			['uuid' => 'noop', 'payload' => ['zaak' => 'Z'], 'annotation' => ['anonymisation' => []]],
			['uuid' => 'down', 'payload' => $this->payload(), 'annotation' => $this->annotation()],
		];
		$targetsFor = function (array $candidate, AnonymisationPlan $plan) use (&$log): array {
			if ($candidate['uuid'] === 'down') {
				return [$this->target('db', $log, 'prepare')];
			}

			return [$this->target('db', $log)];
		};

		$result = $sweep->run($candidates, $targetsFor, 'salt');

		$this->assertSame(['ok'], array_column($result['anonymised'], 'uuid'));
		$this->assertSame('complete', $result['anonymised'][0]['marker']['state']);
		$this->assertSame(['born', 'bsn', 'city', 'moved', 'name', 'postcode'], $result['anonymised'][0]['marker']['changed']);
		$this->assertSame(
			[
				['uuid' => '', 'reason' => 'This candidate carries no uuid, so the act could not be recorded against it.'],
				['uuid' => 'held', 'reason' => 'This record is under a legal hold (case), so it is not anonymised. A hold says somebody may still need it as it is, and as it is includes the name.'],
				['uuid' => 'noop', 'reason' => 'Anonymising noop would change nothing: the declared profile touches no property this record carries.'],
				['uuid' => 'down', 'reason' => 'Anonymising down was stopped before anything was written: db could not be reached (db down). The record is unchanged.'],
			],
			$result['refused']
		);
		$this->assertSame(1, $result['anonymisedCount']);
		$this->assertSame(4, $result['refusedCount']);
		$this->assertSame(['anonymised', 'refused', 'anonymisedCount', 'refusedCount'], array_keys($result));
		$this->assertSame(['prepare:db', 'apply:db', 'prepare:db'], $log);
		$this->assertSame(
			[
				['info', '[AnonymisationSweep] Refused', ['object' => 'held', 'reason' => $result['refused'][1]['reason']]],
				['warning', '[AnonymisationSweep] Stopped on one record', ['object' => 'noop', 'reason' => $result['refused'][2]['reason']]],
				['warning', '[AnonymisationSweep] Stopped on one record', ['object' => 'down', 'reason' => $result['refused'][3]['reason']]],
			],
			$logged
		);
	}//end testTheSweepAnonymisesWhatMayBeAndCountsTheRest()
}//end class
