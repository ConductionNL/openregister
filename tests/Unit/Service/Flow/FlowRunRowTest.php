<?php

/**
 * The run row a queued dispatch writes, field by field.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\Flow\FlowRunRow;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunRow
 */
final class FlowRunRowTest extends TestCase {

	/**
	 * Every field the dispatch names lands on the row, queued and pinned.
	 *
	 * @return void
	 */
	public function testTheRowCarriesTheDispatchTheAttributionAndThePin(): void {
		$run = (new FlowRunRow())->build(
			flowId: 'flow-1',
			subject: ['uuid' => 'obj-1', 'register' => 'reg', 'schema' => 'sch'],
			trigger: 'object.created',
			context: ['nodeId' => 'n1'],
			attribution: ['user' => 'alice', 'organisation' => 'org-1', 'declaredBy' => 'bob'],
			version: 3
		);

		$this->assertSame(1, preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $run->getUuid()));
		$this->assertSame('flow-1', $run->getFlowId());
		$this->assertSame(FlowRun::STATUS_QUEUED, $run->getStatus());
		$this->assertSame('object.created', $run->getTrigger());
		$this->assertSame(['nodeId' => 'n1'], $run->getContext());
		$this->assertSame([], $run->getLog());
		$this->assertSame('obj-1', $run->getSubjectUuid());
		$this->assertSame('reg', $run->getSubjectRegister());
		$this->assertSame('sch', $run->getSubjectSchema());
		$this->assertSame('alice', $run->getTriggeredBy());
		$this->assertSame('alice', $run->getRunAs());
		$this->assertSame('org-1', $run->getOrganisation());
		$this->assertSame(3, $run->getFlowVersion());
		$this->assertNotNull($run->getCreated());
		$this->assertNotNull($run->getUpdated());
	}//end testTheRowCarriesTheDispatchTheAttributionAndThePin()

	/**
	 * A dispatch without a subject, an organisation or a pin leaves those fields empty.
	 *
	 * @return void
	 */
	public function testAnAbsentSubjectOrganisationAndPinStayNull(): void {
		$run = (new FlowRunRow())->build(
			flowId: 'flow-2',
			subject: [],
			trigger: 'manual',
			context: [],
			attribution: ['user' => 'carol', 'organisation' => null],
			version: null
		);

		$this->assertNull($run->getSubjectUuid());
		$this->assertNull($run->getSubjectRegister());
		$this->assertNull($run->getSubjectSchema());
		$this->assertSame('carol', $run->getRunAs());
		$this->assertNull($run->getOrganisation());
		$this->assertNull($run->getFlowVersion());
		$this->assertSame([], $run->getContext());
	}//end testAnAbsentSubjectOrganisationAndPinStayNull()

	/**
	 * Two rows never share a uuid.
	 *
	 * @return void
	 */
	public function testEachRowGetsItsOwnUuid(): void {
		$row = new FlowRunRow();
		$a = $row->build(flowId: 'f', subject: [], trigger: 'manual', context: [], attribution: ['user' => 'u', 'organisation' => null], version: null);
		$b = $row->build(flowId: 'f', subject: [], trigger: 'manual', context: [], attribution: ['user' => 'u', 'organisation' => null], version: null);

		$this->assertNotSame($a->getUuid(), $b->getUuid());
	}//end testEachRowGetsItsOwnUuid()
}//end class
