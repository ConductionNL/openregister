<?php

declare(strict_types=1);

/**
 * The declared surface of the #[McpTool] attribute's constructor.
 *
 * Every `#[McpTool(...)]` in the fleet is written against these named
 * parameters, so their names, order, types, nullability and defaults are
 * pinned as one golden list.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\Attribute
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/ai-mcp/spec.md
 *   (Requirement: REQ-ATTR-001 — The #[McpTool] service-method attribute)
 */

namespace OCA\OpenRegister\Tests\Unit\Mcp\Attribute;

use OCA\OpenRegister\Mcp\Attribute\McpTool;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden constructor signature of McpTool.
 *
 * @covers \OCA\OpenRegister\Mcp\Attribute\McpTool
 */
final class McpToolSignatureTest extends TestCase {

	/**
	 * Name, type, nullability, default and promoted-readonly flag of every parameter, in order.
	 *
	 * @return void
	 */
	public function testTheConstructorSurfaceIsUnchanged(): void {
		$actual = [];
		foreach ((new ReflectionClass(McpTool::class))->getConstructor()->getParameters() as $parameter) {
			$property = new \ReflectionProperty(McpTool::class, $parameter->getName());
			$actual[] = [
				$parameter->getName(),
				(string) $parameter->getType(),
				$parameter->allowsNull(),
				$parameter->getDefaultValue(),
				$parameter->isPromoted() && $property->isReadOnly(),
			];
		}

		$this->assertSame(
			[
				['name', '?string', true, null, true],
				['description', '?string', true, null, true],
				['readOnlyHint', '?bool', true, null, true],
				['destructiveHint', '?bool', true, null, true],
				['idempotentHint', '?bool', true, null, true],
				['scope', '?string', true, null, true],
				['subject', '?string', true, null, true],
				['action', '?string', true, null, true],
				['reach', '?string', true, null, true],
				['annotations', 'array', false, [], true],
			],
			$actual
		);
	}//end testTheConstructorSurfaceIsUnchanged()
}//end class
