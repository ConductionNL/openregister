<?php

/**
 * Unit tests for ExportGate — one refusal shape for every export path.
 *
 * REQ-EXP-001 says every export path checks the verb. The gate exists so that
 * "every" is one object rather than one copy per controller, and the property
 * worth asserting is that the copies would have been identical: the same
 * status, the same verb name, the same audit entry, from whichever path.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/export-as-its-own-right/specs/authorization-rbac/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Export;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Export\ExportAuditRecorder;
use OCA\OpenRegister\Service\Export\ExportGate;
use OCA\OpenRegister\Service\Export\ExportRefusedException;
use OCA\OpenRegister\Service\Export\ExportRightService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExportGateTest extends TestCase {

	/**
	 * The refusals the recorder was handed.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $recorded = [];

	/**
	 * Whether the recorder throws when asked to write.
	 *
	 * @var bool
	 */
	private bool $recorderThrows = false;

	/**
	 * The gate, over a right service that refuses or allows.
	 *
	 * @param ExportRefusedException|null $refusal What the right service answers.
	 *
	 * @return ExportGate The gate under test.
	 */
	private function gate(?ExportRefusedException $refusal): ExportGate {
		$rights = $this->createMock(ExportRightService::class);
		$rights->method('refusalFor')->willReturn($refusal);

		$recorder = $this->createMock(ExportAuditRecorder::class);
		$recorder->method('recordRefused')->willReturnCallback(
			function (
				string $profile,
				string $rule,
				string $reason,
				?int $register = null,
				?int $schema = null,
			): void {
				if ($this->recorderThrows === true) {
					throw new RuntimeException('the trail is unwritable');
				}

				$this->recorded[] = [
					'profile' => $profile,
					'rule' => $rule,
					'reason' => $reason,
					'register' => $register,
					'schema' => $schema,
				];
			}
		);

		return new ExportGate($rights, $recorder);
	}

	/**
	 * A schema double carrying an id.
	 *
	 * @param int $id The schema id.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);

		return $schema;
	}

	/**
	 * A caller the right service allows gets no refusal, and nothing is
	 * recorded: an audit trail that also logs the exports that were allowed to
	 * proceed as refusals cannot be read.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/authorization-rbac/spec.md#requirement-export-is-its-own-permission-verb-req-exp-001
	 *
	 * @return void
	 */
	public function testAnAllowedExportIsNotRefusedAndNotRecorded(): void {
		$this->assertNull($this->gate(null)->refusalFor($this->schema(7), 'tmlo-single', 3));
		$this->assertSame([], $this->recorded);
	}

	/**
	 * A refusal reaches the caller with the right service's own status and
	 * body, naming the verb.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/authorization-rbac/spec.md#requirement-export-is-its-own-permission-verb-req-exp-001
	 *
	 * @return void
	 */
	public function testARefusalCarriesTheStatusAndNamesTheVerb(): void {
		$response = $this->gate(
			new ExportRefusedException(
				rule: 'export-right-missing',
				reason: 'This principal holds read but not the export right.',
				statusCode: 403
			)
		)->refusalFor($this->schema(7), 'relation-graph', 3);

		$this->assertNotNull($response, 'a refused export was allowed through');
		$this->assertSame(403, $response->getStatus());

		$body = $response->getData();
		$this->assertSame('export', $body['verb'], 'the refusal named the record, not the verb');
		$this->assertSame('export-right-missing', $body['rule']);
	}

	/**
	 * The refusal is recorded, naming the export that was refused and where.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md#requirement-every-export-is-recorded-on-the-audit-trail-req-exp-004
	 *
	 * @return void
	 */
	public function testARefusalIsRecordedWithItsProfileRegisterAndSchema(): void {
		$this->gate(
			new ExportRefusedException(
				rule: 'export-right-missing',
				reason: 'This principal holds read but not the export right.',
				statusCode: 403
			)
		)->refusalFor($this->schema(7), 'tmlo-batch', 3);

		$this->assertCount(1, $this->recorded);
		$this->assertSame('tmlo-batch', $this->recorded[0]['profile']);
		$this->assertSame('export-right-missing', $this->recorded[0]['rule']);
		$this->assertSame(3, $this->recorded[0]['register']);
		$this->assertSame(7, $this->recorded[0]['schema']);
	}

	/**
	 * An unwritable trail does not turn a refusal into a crash.
	 *
	 * A 500 and a 403 are acted on very differently by whoever meets them, and
	 * the control here is the refusal, not the record of it.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md#requirement-every-export-is-recorded-on-the-audit-trail-req-exp-004
	 *
	 * @return void
	 */
	public function testAnUnwritableTrailStillRefuses(): void {
		$this->recorderThrows = true;

		$response = $this->gate(
			new ExportRefusedException(
				rule: 'export-right-missing',
				reason: 'This principal holds read but not the export right.',
				statusCode: 403
			)
		)->refusalFor($this->schema(7), 'tmlo-single', 3);

		$this->assertNotNull($response);
		$this->assertSame(403, $response->getStatus());
	}

	/**
	 * A schema that could not be resolved is still put to the right service,
	 * which refuses an unreadable rule. The gate must not answer "allowed"
	 * just because it has nothing to ask about.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/authorization-rbac/spec.md#requirement-export-is-its-own-permission-verb-req-exp-001
	 *
	 * @return void
	 */
	public function testAnUnresolvableSchemaIsPassedToTheRightServiceNotAllowed(): void {
		$rights = $this->createMock(ExportRightService::class);
		$seen = false;
		$rights->method('refusalFor')->willReturnCallback(
			function (?Schema $schema) use (&$seen): ExportRefusedException {
				$seen = true;

				return new ExportRefusedException(
					rule: 'schema-unresolvable',
					reason: 'An unreadable rule refuses.',
					statusCode: 403
				);
			}
		);

		$gate = new ExportGate($rights, $this->createMock(ExportAuditRecorder::class));
		$response = $gate->refusalFor(null, 'relation-graph', null);

		$this->assertTrue($seen, 'the gate decided without asking the right service');
		$this->assertNotNull($response);
		$this->assertSame(403, $response->getStatus());
	}
}
