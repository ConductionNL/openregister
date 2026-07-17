<?php

/**
 * Test fixture only — a `#[McpTool]` service method that raises an
 * authorization failure, used to prove AttributeToolProvider performs NO
 * bypass/impersonation: the owning method's rejection propagates unchanged
 * and is still audited (REQ-ATTR-003 / REQ-ATTR-004).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\Fixtures
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction BV
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-mcp-tool-attribute/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\Fixtures;

use OCA\OpenRegister\Mcp\Attribute\McpTool;
use RuntimeException;

/**
 * ThrowingFixtureService
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\Fixtures
 */
class ThrowingFixtureService
{

    /**
     * Always rejects — simulates the owning method's own authorization
     * check failing.
     *
     * @param string $id The target id.
     *
     * @return array<string, mixed> Never returns.
     *
     * @throws RuntimeException Always.
     */
    #[McpTool]
    public function privilegedAction(string $id): array
    {
        throw new RuntimeException('Not authorized');
    }//end privilegedAction()
}//end class
