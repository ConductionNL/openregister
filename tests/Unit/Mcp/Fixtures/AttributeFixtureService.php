<?php

/**
 * Test fixture for the `#[McpTool]` attribute + AttributeToolScanner
 * (ADR-063 chain 3/3). NOT shipped behaviour — see design.md's "Seed Data"
 * section: a fixture service is the intended test input for this change,
 * the real first consumers are leaf apps annotating their own services in
 * their own migration changes.
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

/**
 * AttributeFixtureService
 *
 * Three attributed methods: one fully typed + docblocked (exercises schema
 * inference), one minimal/defaulted (exercises attribute defaults), and one
 * non-public (exercises the "ignored with a warning" rule).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\Fixtures
 */
class AttributeFixtureService
{

    /**
     * Create a sales lead from a contact moment.
     *
     * @param string      $email   The contact's email address.
     * @param string|null $company Optional company name.
     * @param int         $score   Lead score (defaults to zero).
     *
     * @return array{id: string}
     */
    #[McpTool(name: 'createLead', description: 'Create a sales lead from a contact moment.')]
    public function createLead(string $email, ?string $company = null, int $score = 0): array
    {
        return [
            'id'      => 'lead-1',
            'email'   => $email,
            'company' => $company,
            'score'   => $score,
        ];
    }//end createLead()

    /**
     * Log a contact moment against a lead.
     *
     * @param string $subject The moment's subject line.
     *
     * @return array{subject: string}
     */
    #[McpTool]
    public function logContactmoment(string $subject): array
    {
        return ['subject' => $subject];
    }//end logContactmoment()

    /**
     * Attributed but non-public — the scanner MUST ignore this with a
     * logged warning (REQ-ATTR-001: "honoured only on public methods").
     *
     * @return array{internal: bool}
     */
    #[McpTool]
    protected function internalOnly(): array
    {
        return ['internal' => true];
    }//end internalOnly()

    /**
     * Scalar-typed return — exercises non-omitted outputSchema inference
     * (as opposed to the untyped-`array` returns above, which are
     * deliberately omitted per design.md's "best-effort" contract).
     *
     * @param int $leadId The lead id to score.
     *
     * @return int The computed score.
     */
    #[McpTool]
    public function computeScore(int $leadId): int
    {
        return ($leadId * 2);
    }//end computeScore()
}//end class
