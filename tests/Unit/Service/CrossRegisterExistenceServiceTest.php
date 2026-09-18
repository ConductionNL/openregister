<?php

/**
 * Asking whether a row exists, and learning nothing else.
 *
 * 🔴 THE ASSERTION THAT MATTERS IS WHAT IS **NOT** IN THE ANSWER. A test that
 * checked `exists` and `matches` were PRESENT would pass on an answer that also
 * carried the title, the status and the case number, which is the leak this
 * endpoint exists to prevent. So the keys are asserted EXACTLY against the
 * constant the service publishes, the seeded row carries content that must not
 * travel, and the encoded answer is searched for that content as well — because
 * the key assertion alone would pass on a projection that renamed a leak into
 * one of the allowed keys.
 *
 * 🔴 A REFUSED PROBE IS NOT AN EMPTY ONE. Reporting "you may not ask" as "there
 * is nothing here" would make the endpoint a way to learn that a register holds
 * nothing about a person, which is itself an answer the caller was not entitled
 * to, and it would be indistinguishable from the truth. The test asserts both
 * halves: the refusal is reported AND `exists` is not being read as a claim.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\CrossRegisterExistenceService;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\CrossRegisterExistenceService
 *
 * @spec openspec/changes/cross-register-existence-query/specs/cross-register-existence-query/spec.md
 */
final class CrossRegisterExistenceServiceTest extends TestCase {

	private ObjectService&MockObject $objects;

	private SchemaMapper&MockObject $schemas;

	/**
	 * The row a probe would match, carrying content that must not travel.
	 *
	 * @var array<string, mixed>
	 */
	private const ROW = [
		'id' => 'jw-1',
		'caseNumber' => 'JW-2026-0044',
		'status' => 'support-loopt',
		'handlerId' => 'bram',
		'supportRequest' => 'Vader vraagt om begeleiding bij het gedrag van Sem',
	];

	/**
	 * A schema declaring four properties, one of them behind an authorization block.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(ObjectService::class);
		$this->schemas = $this->createMock(SchemaMapper::class);

		// A REAL entity, not a mock: `Schema` extends Nextcloud's `Entity` and
		// its getters are `__call` magic, so PHPUnit cannot configure them.
		$schema = new Schema();
		$schema->setProperties(
			[
				'caseNumber' => ['type' => 'string'],
				'status' => ['type' => 'string'],
				'handlerId' => ['type' => 'string'],
				'supportRequest' => ['type' => 'string', 'authorization' => ['read' => ['jeugdconsulenten']]],
			]
		);
		$this->schemas->method('find')->willReturn($schema);
	}

	/**
	 * The service under test.
	 *
	 * @return CrossRegisterExistenceService The service.
	 */
	private function service(): CrossRegisterExistenceService {
		return new CrossRegisterExistenceService($this->objects, $this->schemas, new NullLogger());
	}

	/**
	 * Make the search answer these rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return void
	 */
	private function answers(array $rows): void {
		$this->objects->method('searchObjects')->willReturn($rows);
	}

	/**
	 * One probe against the Jeugdwet register.
	 *
	 * @param array<int, string> $reveal What to reveal.
	 *
	 * @return array<string, mixed> The probe.
	 */
	private function probe(array $reveal = []): array {
		return [
			'register' => 'dossiq',
			'schema' => 'jeugdwetZaak',
			'filters' => ['jeugdigeBsn' => '123456782'],
			'reveal' => $reveal,
		];
	}

	/**
	 * 🔴 A match answers that one exists, and carries nothing of the row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cross-register-existence-query/specs/cross-register-existence-query/spec.md#requirement-a-caller-can-ask-whether-a-row-exists-without-reading-it
	 */
	public function testAMatchAnswersExistenceAndNothingOfTheRow(): void {
		$this->answers([self::ROW]);

		$answer = $this->service()->probe([$this->probe()])['probes'][0];

		self::assertTrue($answer['exists']);
		self::assertSame(1, $answer['matches']);
		self::assertSame([], $answer['revealed'], 'reveal defaults to empty');

		self::assertSame(
			CrossRegisterExistenceService::ANSWER_FIELDS,
			array_keys($answer),
			'the answer is these keys and no others'
		);

		// Said the other way round too, because the key assertion would pass on
		// a projection that renamed a leak into one of the allowed keys.
		$encoded = json_encode($answer);
		self::assertStringNotContainsString('JW-2026-0044', $encoded, 'no case number');
		self::assertStringNotContainsString('support-loopt', $encoded, 'no status');
		self::assertStringNotContainsString('Vader vraagt', $encoded, 'no content');
	}

	/**
	 * No match is an answer, not an error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cross-register-existence-query/specs/cross-register-existence-query/spec.md#requirement-a-caller-can-ask-whether-a-row-exists-without-reading-it
	 */
	public function testNoMatchSaysSo(): void {
		$this->answers([]);

		$answer = $this->service()->probe([$this->probe()])['probes'][0];

		self::assertFalse($answer['exists']);
		self::assertSame(0, $answer['matches']);
		self::assertSame('', $answer['refused'], 'an absence is not a refusal');
	}

	/**
	 * 🔴 More probes than the bound are refused, and NOTHING is queried.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cross-register-existence-query/specs/cross-register-existence-query/spec.md#requirement-a-caller-can-ask-whether-a-row-exists-without-reading-it
	 */
	public function testMoreProbesThanTheBoundAreRefusedAndNothingIsQueried(): void {
		// The assertion that separates "refused" from "truncated": silently
		// dropping the eleventh answers "nothing there" about a register
		// nobody asked, which is a wrong answer rather than a missing one.
		$this->objects->expects(self::never())->method('searchObjects');

		$probes = array_fill(0, (CrossRegisterExistenceService::MAX_PROBES + 1), $this->probe());
		$answer = $this->service()->probe($probes);

		self::assertSame('too-many-probes', $answer['error']);
		self::assertArrayNotHasKey('probes', $answer);
	}

	/**
	 * A declared, non-sensitive field is revealed on request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cross-register-existence-query/specs/cross-register-existence-query/spec.md#requirement-revealed-fields-are-bounded-by-the-schema-not-by-the-caller
	 */
	public function testADeclaredFieldIsRevealedOnRequest(): void {
		$this->answers([self::ROW]);

		$answer = $this->service()->probe([$this->probe(reveal: ['handlerId'])])['probes'][0];

		self::assertSame(['handlerId' => 'bram'], $answer['revealed']);
		self::assertSame([], $answer['refusedFields']);
		// And still nothing else from the row.
		self::assertStringNotContainsString('JW-2026-0044', json_encode($answer));
	}

	/**
	 * 🔴 A field behind an authorization block is refused BY NAME.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cross-register-existence-query/specs/cross-register-existence-query/spec.md#requirement-revealed-fields-are-bounded-by-the-schema-not-by-the-caller
	 */
	public function testASensitiveFieldIsRefusedByName(): void {
		$this->answers([self::ROW]);

		$answer = $this->service()->probe(
			[$this->probe(reveal: ['handlerId', 'supportRequest'])]
		)['probes'][0];

		self::assertSame(['handlerId' => 'bram'], $answer['revealed']);
		self::assertSame(
			['supportRequest'],
			$answer['refusedFields'],
			'a caller silently receiving less goes looking for a bug in its own code'
		);
		self::assertStringNotContainsString('Vader vraagt', json_encode($answer));
	}

	/**
	 * A field no schema declares is refused too.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cross-register-existence-query/specs/cross-register-existence-query/spec.md#requirement-revealed-fields-are-bounded-by-the-schema-not-by-the-caller
	 */
	public function testAnUndeclaredFieldIsRefused(): void {
		$this->answers([self::ROW]);

		$answer = $this->service()->probe([$this->probe(reveal: ['bsn'])])['probes'][0];

		self::assertSame([], $answer['revealed']);
		self::assertSame(['bsn'], $answer['refusedFields']);
	}

	/**
	 * 🔴 A refused register reports REFUSED, never "nothing exists".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cross-register-existence-query/specs/cross-register-existence-query/spec.md#requirement-a-probe-is-authorised-as-the-read-it-replaces
	 */
	public function testARefusedRegisterDoesNotReportAnAbsence(): void {
		$this->objects->method('searchObjects')->willThrowException(
			new RuntimeException('You do not have permission to read this register')
		);

		$answer = $this->service()->probe([$this->probe()])['probes'][0];

		self::assertNotSame('', $answer['refused'], 'the caller is told the probe was refused');
		self::assertStringContainsString('not an answer', $answer['refused']);
		self::assertSame([], $answer['revealed']);
	}

	/**
	 * A refusal and a genuine absence are distinguishable.
	 *
	 * Each test above passes on an implementation that answers ITS shape for
	 * both cases; only comparing them catches that.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cross-register-existence-query/specs/cross-register-existence-query/spec.md#requirement-a-probe-is-authorised-as-the-read-it-replaces
	 */
	public function testARefusalAndAnAbsenceAreNotTheSameAnswer(): void {
		$empty = new self('empty');
		$empty->setUp();
		$empty->answers([]);
		$absence = $empty->service()->probe([$empty->probe()])['probes'][0];

		$denied = new self('denied');
		$denied->setUp();
		$denied->objects->method('searchObjects')->willThrowException(new RuntimeException('nope'));
		$refusal = $denied->service()->probe([$denied->probe()])['probes'][0];

		self::assertNotSame(
			$absence['refused'],
			$refusal['refused'],
			'"you may not ask" and "there is nothing here" must not read alike'
		);
	}

	/**
	 * A probe naming no filter is refused rather than matching everything.
	 *
	 * An unfiltered probe would answer "yes, rows exist" about every register
	 * that holds anything at all, which is a true sentence and a useless one,
	 * and it invites a caller to use it as a register census.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cross-register-existence-query/specs/cross-register-existence-query/spec.md#requirement-a-caller-can-ask-whether-a-row-exists-without-reading-it
	 */
	public function testAProbeWithNoFilterIsRefused(): void {
		$this->objects->expects(self::never())->method('searchObjects');

		$answer = $this->service()->probe(
			[['register' => 'dossiq', 'schema' => 'jeugdwetZaak', 'filters' => []]]
		)['probes'][0];

		self::assertNotSame('', $answer['refused']);
		self::assertFalse($answer['exists']);
	}
}//end class
