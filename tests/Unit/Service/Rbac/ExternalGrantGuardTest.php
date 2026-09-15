<?php

/**
 * Unit tests for the external grant's required end date.
 *
 * Covers the spec delta's "a temporary adviser stays temporary": a grant to an
 * external principal with no end date is refused, naming the requirement. Also
 * covers the second scenario's half that is testable without a live instance,
 * "the work survives the access": when the end arrives the entry stops
 * answering and nothing about the objects changes, because no code path here
 * or in GrantConstraints writes to one.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/authorization-rbac/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Rbac\ExternalGrantGuard;
use OCA\OpenRegister\Service\Rbac\GrantConstraints;
use PHPUnit\Framework\TestCase;

final class ExternalGrantGuardTest extends TestCase {
	private ExternalGrantGuard $guard;

	protected function setUp(): void {
		$this->guard = new ExternalGrantGuard();
	}//end setUp()

	public function testATemporaryAdviserStaysTemporary(): void {
		$block = [
			'read' => [
				['name' => 'adviseur', ExternalGrantGuard::EXTERNAL_KEY => true],
			],
		];

		$refusal = $this->guard->refusalFor($block);

		self::assertNotNull($refusal, 'an external grant with no end must be refused');
		self::assertSame('EXTERNAL_GRANT_REFUSED', $refusal['error']);
		// NAMING THE REQUIREMENT, not just refusing. A refusal that does not say
		// what to add leaves the writer guessing at the grammar.
		self::assertSame(ExternalGrantGuard::RULE, $refusal['rule']);
		self::assertSame('adviseur', $refusal['grants'][0]['principal']);
		self::assertSame('read', $refusal['grants'][0]['action']);
		self::assertStringContainsString(GrantConstraints::UNTIL_KEY, $refusal['grants'][0]['message']);
	}//end testATemporaryAdviserStaysTemporary()

	public function testAnExternalGrantWithAnEndIsAllowed(): void {
		$block = [
			'read' => [
				['name' => 'adviseur', ExternalGrantGuard::EXTERNAL_KEY => true, GrantConstraints::UNTIL_KEY => '2026-12-31'],
			],
		];

		self::assertNull($this->guard->refusalFor($block));
	}//end testAnExternalGrantWithAnEndIsAllowed()

	public function testAnUnreadableEndIsNotAnEnd(): void {
		$block = [
			'read' => [
				['name' => 'adviseur', ExternalGrantGuard::EXTERNAL_KEY => true, GrantConstraints::UNTIL_KEY => 'volgende maand'],
			],
		];

		// Fail closed, exactly as GrantConstraints does. The other reading turns
		// a typo into the permanent access this rule exists to prevent.
		self::assertNotNull($this->guard->refusalFor($block));
	}//end testAnUnreadableEndIsNotAnEnd()

	public function testAnOrdinaryGrantIsUntouchedByThisRule(): void {
		$block = [
			'read' => ['redacteuren', ['name' => 'anja']],
			'public' => true,
		];

		// The grammar is additive: a rule written before this existed keeps
		// meaning what it meant, or this guard is a migration in disguise.
		self::assertNull($this->guard->refusalFor($block));
		self::assertSame([], $this->guard->endlessExternalGrants($block));
	}//end testAnOrdinaryGrantIsUntouchedByThisRule()

	public function testEveryOffendingEntryIsNamedInOnePass(): void {
		$block = [
			'read' => [['name' => 'adviseur', ExternalGrantGuard::EXTERNAL_KEY => true]],
			'update' => [['name' => 'auditor', ExternalGrantGuard::EXTERNAL_KEY => true]],
		];

		$offending = $this->guard->endlessExternalGrants($block);

		// One pass, so a writer fixes all of them at once rather than
		// discovering them one refusal at a time.
		self::assertCount(2, $offending);
		self::assertSame(['adviseur', 'auditor'], array_column($offending, 'principal'));
	}//end testEveryOffendingEntryIsNamedInOnePass()

	public function testTheHolderIsWarnedBeforeItLapses(): void {
		$now = new DateTimeImmutable('2026-09-15T12:00:00+00:00');
		$block = [
			'read' => [
				['name' => 'bijna-weg', ExternalGrantGuard::EXTERNAL_KEY => true, GrantConstraints::UNTIL_KEY => '2026-09-20T12:00:00+00:00'],
				['name' => 'nog-lang', ExternalGrantGuard::EXTERNAL_KEY => true, GrantConstraints::UNTIL_KEY => '2027-01-01T12:00:00+00:00'],
			],
		];

		$lapsing = $this->guard->lapsingWithin($block, 14, $now);

		self::assertCount(1, $lapsing);
		self::assertSame('bijna-weg', $lapsing[0]['principal']);
		self::assertSame(5, $lapsing[0]['daysLeft']);
	}//end testTheHolderIsWarnedBeforeItLapses()

	public function testAnEndAlreadyPastIsALapseNotAWarning(): void {
		$now = new DateTimeImmutable('2026-09-15T12:00:00+00:00');
		$block = [
			'read' => [
				['name' => 'al-weg', ExternalGrantGuard::EXTERNAL_KEY => true, GrantConstraints::UNTIL_KEY => '2026-09-01T12:00:00+00:00'],
			],
		];

		// Reporting it as "about to lapse" would send somebody to renew access
		// that already stopped answering.
		self::assertSame([], $this->guard->lapsingWithin($block, 14, $now));
	}//end testAnEndAlreadyPastIsALapseNotAWarning()

	public function testTheWarningsAreSoonestFirst(): void {
		$now = new DateTimeImmutable('2026-09-15T12:00:00+00:00');
		$block = [
			'read' => [
				['name' => 'later', ExternalGrantGuard::EXTERNAL_KEY => true, GrantConstraints::UNTIL_KEY => '2026-09-25T12:00:00+00:00'],
				['name' => 'eerder', ExternalGrantGuard::EXTERNAL_KEY => true, GrantConstraints::UNTIL_KEY => '2026-09-17T12:00:00+00:00'],
			],
		];

		self::assertSame(
			['eerder', 'later'],
			array_column($this->guard->lapsingWithin($block, 30, $now), 'principal')
		);
	}//end testTheWarningsAreSoonestFirst()

	public function testTheWorkSurvivesTheAccess(): void {
		$now = new DateTimeImmutable('2026-09-15T12:00:00+00:00');
		$lapsed = [
			'read' => [
				['name' => 'adviseur', ExternalGrantGuard::EXTERNAL_KEY => true, GrantConstraints::UNTIL_KEY => '2026-09-01T12:00:00+00:00'],
				'redacteuren',
			],
		];

		$object = new ObjectEntity();
		$object->setUuid('zaak-1');
		$object->setOwner('adviseur');
		$object->setObject(['notitie' => 'geschreven door de adviseur']);
		$before = $object->getObject();

		// The grant has ended: GrantConstraints stops it answering here.
		$narrowed = (new GrantConstraints())->apply($lapsed, ['register' => '1', 'schema' => '2'], $now);
		self::assertSame(['redacteuren'], $narrowed['read']);

		// AND THE WORK IS EXACTLY WHERE IT WAS, still attributed. The two were
		// never the same record, which is why nothing had to be copied out
		// before the access ended.
		self::assertSame('adviseur', $object->getOwner());
		self::assertSame($before, $object->getObject());
		self::assertFalse($object->isSoftDeleted());
	}//end testTheWorkSurvivesTheAccess()
}//end class
