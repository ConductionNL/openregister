<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Handoff\HandoffMappingEvaluator}.
 *
 * Covers the five expression kinds (`from`, `const`, `template`,
 * `semanticRef`, `provenance`), HTML-escaping in templates, the
 * carried-not-copied semantic reference guarantee, `from` + `default`, and
 * null-value omission (optional contract fields).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Handoff;

use OCA\OpenRegister\Service\Handoff\HandoffMappingEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * HandoffMappingEvaluatorTest.
 */
class HandoffMappingEvaluatorTest extends TestCase {

	private HandoffMappingEvaluator $evaluator;

	private const PROVENANCE = [
		'app' => 'pipelinq',
		'register' => 7,
		'schema' => 12,
		'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
	];

	protected function setUp(): void {
		$this->evaluator = new HandoffMappingEvaluator();

	}//end setUp()

	/**
	 * All five expression kinds evaluate per the dialect contract.
	 *
	 * @return void
	 */
	public function testFiveExpressionKinds(): void {
		$result = $this->evaluator->evaluate(
			mapping: [
				'title' => ['from' => 'subject'],
				'channel' => ['const' => 'web'],
				'summary' => ['template' => '{{subject}} — {{details}}'],
				'requester' => ['semanticRef' => 'client'],
				'source' => ['provenance' => true],
			],
			sourceData: [
				'subject' => 'Kapotte lantaarnpaal',
				'details' => 'Voor de deur',
				'client' => '11111111-2222-3333-4444-555555555555',
			],
			provenance: self::PROVENANCE
		);

		$this->assertSame('Kapotte lantaarnpaal', $result['title']);
		$this->assertSame('web', $result['channel']);
		$this->assertSame('Kapotte lantaarnpaal — Voor de deur', $result['summary']);
		$this->assertSame('11111111-2222-3333-4444-555555555555', $result['requester']);
		$this->assertSame(self::PROVENANCE, $result['source']);

	}//end testFiveExpressionKinds()

	/**
	 * Template interpolation HTML-escapes source values (existing OR
	 * convention) and renders unknown / non-scalar properties as ''.
	 *
	 * @return void
	 */
	public function testTemplateIsHtmlEscaped(): void {
		$result = $this->evaluator->evaluate(
			mapping: ['summary' => ['template' => '{{subject}}: {{ghost}} {{nested}}']],
			sourceData: [
				'subject' => '<script>alert("x")</script>',
				'nested' => ['not' => 'scalar'],
			],
			provenance: self::PROVENANCE
		);

		$this->assertSame(
			'&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;:  ',
			$result['summary']
		);

	}//end testTemplateIsHtmlEscaped()

	/**
	 * semanticRef carries the reference UUID only — from a plain UUID value
	 * or from a reference envelope — and NEVER copies referenced data.
	 *
	 * @return void
	 */
	public function testSemanticRefCarriesUuidOnly(): void {
		// Envelope shape: only the identifying UUID crosses.
		$result = $this->evaluator->evaluate(
			mapping: ['requester' => ['semanticRef' => 'client']],
			sourceData: [
				'client' => [
					'uuid' => '99999999-8888-7777-6666-555555555555',
					'name' => 'Acme Gemeente BV',
					'kvk' => '12345678',
				],
			],
			provenance: self::PROVENANCE
		);

		$this->assertSame('99999999-8888-7777-6666-555555555555', $result['requester']);
		// No referenced-object data was duplicated into the result.
		$this->assertStringNotContainsString('Acme', (string)json_encode($result));

		// Absent reference (anonymous walk-in) → field omitted entirely.
		$result = $this->evaluator->evaluate(
			mapping: ['requester' => ['semanticRef' => 'client']],
			sourceData: ['subject' => 'Anoniem'],
			provenance: self::PROVENANCE
		);
		$this->assertArrayNotHasKey('requester', $result);

	}//end testSemanticRefCarriesUuidOnly()

	/**
	 * `from` + `default`: the default applies only when the source value is
	 * absent.
	 *
	 * @return void
	 */
	public function testFromWithDefault(): void {
		$mapping = [
			'priority' => [
				'from' => 'priority',
				'default' => 'normal',
			],
		];

		$withValue = $this->evaluator->evaluate(mapping: $mapping, sourceData: ['priority' => 'hoog'], provenance: self::PROVENANCE);
		$this->assertSame('hoog', $withValue['priority']);

		$withoutValue = $this->evaluator->evaluate(mapping: $mapping, sourceData: [], provenance: self::PROVENANCE);
		$this->assertSame('normal', $withoutValue['priority']);

	}//end testFromWithDefault()

	/**
	 * Absent source values without a default are omitted (optional contract
	 * fields stay absent, per the anonymous-requester contract scenario).
	 *
	 * @return void
	 */
	public function testAbsentValuesAreOmitted(): void {
		$result = $this->evaluator->evaluate(
			mapping: ['priority' => ['from' => 'priority']],
			sourceData: [],
			provenance: self::PROVENANCE
		);

		$this->assertSame([], $result);

	}//end testAbsentValuesAreOmitted()
}//end class
