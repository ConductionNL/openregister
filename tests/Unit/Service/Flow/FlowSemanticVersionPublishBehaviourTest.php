<?php

/**
 * FlowSemanticVersion: the version a publish gets from the published and candidate graphs, and the generic refusal when a removal has no summary.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowGraphDiff;
use OCA\OpenRegister\Service\Flow\FlowGraphFinding;
use OCA\OpenRegister\Service\Flow\FlowSemanticVersion;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowSemanticVersion
 * @uses \OCA\OpenRegister\Service\Flow\FlowGraphDiff
 */
class FlowSemanticVersionPublishBehaviourTest extends TestCase {

	private FlowSemanticVersion $versions;

	protected function setUp(): void {
		parent::setUp();
		$this->versions = new FlowSemanticVersion();
	}//end setUp()

	/**
	 * @return array<string, mixed>
	 */
	private function graph(array $ids): array {
		return [
			'nodes' => array_map(static fn (string $id): array => ['id' => $id, 'type' => 'openregister.set-fields', 'config' => []], $ids),
			'edges' => [],
		];
	}//end graph()

	public function testNothingPublishedIsTheFirstVersion(): void {
		self::assertSame('1.0.0', $this->versions->forPublish(publishedGraph: null, candidateGraph: $this->graph(['a']), previousSemver: '4.2.0', requested: 'major'));
	}//end testNothingPublishedIsTheFirstVersion()

	public function testAnAdditionIsAMinorBumpOfThePreviousVersion(): void {
		self::assertSame('1.3.0', $this->versions->forPublish(publishedGraph: $this->graph(['a']), candidateGraph: $this->graph(['a', 'b']), previousSemver: '1.2.0', requested: null));
	}//end testAnAdditionIsAMinorBumpOfThePreviousVersion()

	public function testARemovalIsAMajorBumpAndCannotBeTalkedDown(): void {
		self::assertSame('2.0.0', $this->versions->forPublish(publishedGraph: $this->graph(['a', 'b']), candidateGraph: $this->graph(['a']), previousSemver: '1.2.0', requested: null));

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('This publish cannot be minor.');
		$this->versions->forPublish(publishedGraph: $this->graph(['a', 'b']), candidateGraph: $this->graph(['a']), previousSemver: '1.2.0', requested: 'minor');
	}//end testARemovalIsAMajorBumpAndCannotBeTalkedDown()

	public function testARefusalWithoutASummaryStillSaysWhy(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('This publish cannot be minor. Something a consumer could depend on was removed.');

		$this->versions->reconcile(found: new FlowGraphFinding(verdict: FlowGraphDiff::MAJOR, removed: ''), requested: ' Minor ');
	}//end testARefusalWithoutASummaryStillSaysWhy()
	public function testAMinorRequestOnAMinorChangeStaysMinor(): void {
		self::assertSame(FlowGraphDiff::MINOR, $this->versions->reconcile(found: new FlowGraphFinding(verdict: FlowGraphDiff::MINOR), requested: 'minor'));
		self::assertSame('1.5.0', $this->versions->forPublish(publishedGraph: $this->graph(['a']), candidateGraph: $this->graph(['a', 'c']), previousSemver: '1.4.0', requested: 'minor'));
	}//end testAMinorRequestOnAMinorChangeStaysMinor()
}//end class
