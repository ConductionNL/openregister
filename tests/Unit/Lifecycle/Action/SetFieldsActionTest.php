<?php

/**
 * SetFieldsAction built-in lifecycle action tests (lifecycle-action-executor).
 *
 * Exercises the two authoring shapes the fleet declares — `set-fields`
 * (parameters ARE the field map) and `set-field` (field map under `set`) —
 * the `@now` token, and the fail-loud contract when no fields are declared.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Lifecycle\Action;

use OCA\OpenRegister\Lifecycle\Action\SetFieldsAction;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @coversDefaultClass \OCA\OpenRegister\Lifecycle\Action\SetFieldsAction
 */
class SetFieldsActionTest extends TestCase {
	private SetFieldsAction $action;

	protected function setUp(): void {
		$this->action = new SetFieldsAction();
	}//end setUp()

	/**
	 * `set-fields` shape: the actionParameters map IS the field map. A declared
	 * static value is stamped verbatim onto the payload.
	 */
	public function testSetFieldsShapeStampsStaticValue(): void {
		$result = $this->action->execute(
			objectData: ['status' => 'submitted'],
			previousData: ['status' => 'draft'],
			parameters: ['reviewedBy' => 'system'],
			actionName: 'set-fields'
		);

		$this->assertSame('submitted', $result['status']);
		$this->assertSame('system', $result['reviewedBy']);
	}//end testSetFieldsShapeStampsStaticValue()

	/**
	 * `set-field` shape: the field map lives under a `set` key.
	 */
	public function testSetFieldShapeReadsSetKey(): void {
		$result = $this->action->execute(
			objectData: ['status' => 'submitted'],
			previousData: [],
			parameters: ['set' => ['approved' => true]],
			actionName: 'set-field'
		);

		$this->assertTrue($result['approved']);
	}//end testSetFieldShapeReadsSetKey()

	/**
	 * The `@now` token resolves to an ISO-8601 UTC timestamp at execution time.
	 */
	public function testNowTokenResolvesToTimestamp(): void {
		$result = $this->action->execute(
			objectData: [],
			previousData: [],
			parameters: ['submittedAt' => '@now'],
			actionName: 'set-fields'
		);

		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			(string)$result['submittedAt']
		);
	}//end testNowTokenResolvesToTimestamp()

	/**
	 * Fail loud: a declared action that resolves to no fields to set throws
	 * rather than silently no-oping.
	 */
	public function testEmptyFieldMapThrows(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('declares no fields to set');

		$this->action->execute(
			objectData: ['status' => 'submitted'],
			previousData: [],
			parameters: [],
			actionName: 'set-fields'
		);
	}//end testEmptyFieldMapThrows()
}//end class
