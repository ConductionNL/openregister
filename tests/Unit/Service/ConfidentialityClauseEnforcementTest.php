<?php

/**
 * Confidentiality clause: builder output is enforced by the engine.
 *
 * `ZaaktypeAuthorizationService::buildConfidentialityMatch()` is a BUILDER — it
 * emits an `$in` clause that the existing RBAC engine is supposed to enforce.
 * Its own unit test proves the clause has the right SHAPE. Nothing proved the
 * engine actually honours it, and the two live handlers contain no
 * confidentiality logic at all, so the builder-to-engine link was untested.
 *
 * These tests close that link: a clause produced by the builder is fed to
 * `OperatorEvaluator::valueMatchesOperator()` — the same evaluator the RLS/FLS
 * match path uses — and asserted to admit exactly the levels at or below the
 * clearance.
 *
 * This is what makes the capability demonstrably real rather than merely
 * present. It does NOT switch enforcement on: that still requires attaching the
 * clause to a schema's `authorization` block, which is a policy decision.
 * See docs/rbac-confidentiality.md.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\ZaaktypeAuthorizationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The clause the builder emits is honoured by the operator evaluator.
 */
class ConfidentialityClauseEnforcementTest extends TestCase
{
    // PHPUnit assertions take positional arguments; the custom named-parameter
    // sniff targets this app's own code, not the framework.
    // phpcs:disable CustomSniffs.Functions.NamedParameters

    /**
     * The clause builder.
     *
     * @var ZaaktypeAuthorizationService
     */
    private ZaaktypeAuthorizationService $builder;

    /**
     * The evaluator the RBAC match path uses.
     *
     * @var OperatorEvaluator
     */
    private OperatorEvaluator $evaluator;

    /**
     * Build both collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->builder   = new ZaaktypeAuthorizationService();
        $this->evaluator = new OperatorEvaluator($this->createMock(LoggerInterface::class));

    }//end setUp()

    /**
     * Run one object level through a clause built for one clearance.
     *
     * @param string $maxLevel    The clearance granted.
     * @param string $objectLevel The object's own level.
     *
     * @return bool Whether the engine admits the object.
     */
    private function admits(string $maxLevel, string $objectLevel): bool
    {
        $clause = $this->builder->buildConfidentialityMatch(maxLevel: $maxLevel);
        $ops    = $clause['vertrouwelijkheidaanduiding'];

        return $this->evaluator->valueMatchesOperator($objectLevel, $ops);

    }//end admits()

    /**
     * Clearance / object-level combinations and the expected verdict.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function clearanceProvider(): array
    {
        return [
            'openbaar clearance sees openbaar'         => ['openbaar', 'openbaar', true],
            'openbaar clearance is DENIED intern'      => ['openbaar', 'intern', false],
            'openbaar clearance is DENIED zeer geheim' => ['openbaar', 'zeer_geheim', false],
            'intern clearance sees openbaar'           => ['intern', 'openbaar', true],
            'intern clearance sees intern'             => ['intern', 'intern', true],
            'intern clearance is DENIED vertrouwelijk' => ['intern', 'vertrouwelijk', false],
            'geheim clearance is DENIED zeer geheim'   => ['geheim', 'zeer_geheim', false],
            'zeer geheim clearance sees zeer geheim'   => ['zeer_geheim', 'zeer_geheim', true],
            'zeer geheim clearance sees openbaar'      => ['zeer_geheim', 'openbaar', true],
        ];

    }//end clearanceProvider()

    /**
     * The engine admits an object only at or below the granted clearance.
     *
     * @param string $maxLevel    The clearance granted.
     * @param string $objectLevel The object's own level.
     * @param bool   $expected    Whether it should be admitted.
     *
     * @return void
     *
     * @dataProvider clearanceProvider
     *
     * @spec openspec/specs/rbac-zaaktype/spec.md
     */
    public function testEngineHonoursTheBuiltClause(string $maxLevel, string $objectLevel, bool $expected): void
    {
        $this->assertSame(
            $expected,
            $this->admits(maxLevel: $maxLevel, objectLevel: $objectLevel),
            sprintf(
                'clearance %s / object %s was %s',
                $maxLevel,
                $objectLevel,
                $this->admits(maxLevel: $maxLevel, objectLevel: $objectLevel) ? 'ADMITTED' : 'denied'
            )
        );

    }//end testEngineHonoursTheBuiltClause()

    /**
     * An unrecognised object level is denied, not admitted.
     *
     * Fail-closed matters more than the happy path here: a typo in stored data
     * must hide the object rather than expose it.
     *
     * @return void
     *
     * @spec openspec/specs/rbac-zaaktype/spec.md
     */
    public function testUnknownObjectLevelIsDenied(): void
    {
        $this->assertFalse(
            $this->admits(maxLevel: 'intern', objectLevel: 'niet-bestaand-niveau'),
            'An object whose level is not in the ordinal set must be withheld.'
        );

    }//end testUnknownObjectLevelIsDenied()

    /**
     * The clause targets a configurable property name.
     *
     * Three names for this concept exist in the codebase
     * (vertrouwelijkheidaanduiding / confidentialityLevel / confidentiality),
     * and pointing the builder at the wrong one fails silently — the mismatch
     * that caused the federation fail-open in #2438.
     *
     * @return void
     *
     * @spec openspec/specs/rbac-zaaktype/spec.md
     */
    public function testClauseUsesTheRequestedPropertyName(): void
    {
        $clause = $this->builder->buildConfidentialityMatch(
            maxLevel: 'intern',
            property: 'confidentialityLevel'
        );

        $this->assertArrayHasKey('confidentialityLevel', $clause);
        $this->assertArrayNotHasKey('vertrouwelijkheidaanduiding', $clause);

    }//end testClauseUsesTheRequestedPropertyName()
}//end class
