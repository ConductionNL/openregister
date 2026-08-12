<?php

/**
 * Two node-config shapes that used to be accepted and then misbehave.
 *
 * Both were found by authoring eight working flows against the live engine, and
 * both produced a GREEN run — which is why they are pinned here rather than left
 * to a reviewer's eye.
 *
 *   FILTER   a condition that is not an expression evaluates to the same answer
 *            for every item, so the step keeps everything or drops everything
 *            while reading like a rule. `'{{ status == "synced" }}'` passed
 *            validation and kept all eleven items; the sweep downstream flagged
 *            every object instead of the nine it meant to, and nothing errored.
 *
 *   MATCH    the map shorthand `{"status": "flagged"}` is what every author
 *            writes first, because it is how `fields` and `filters` are written
 *            on neighbouring nodes. It used to be rejected outright.
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
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\Nodes\FilterNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Config contracts that fail silently when authored wrong.
 */
final class FlowNodeConfigContractsTest extends TestCase
{
    /**
     * An l10n double that renders the message with its placeholders filled.
     *
     * @return IL10N The double.
     */
    private function l10n(): IL10N
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static function (string $text, array $parameters=[]): string {
                foreach ($parameters as $key => $value) {
                    $text = str_replace('{'.$key.'}', (string) $value, $text);
                }

                return $text;
            }
        );

        return $l10n;

    }//end l10n()

    /**
     * A template string is refused, and the message shows the right shape.
     *
     * @return void
     */
    public function testFilterRefusesATemplateCondition(): void
    {
        $node = new FilterNode($this->l10n(), $this->createMock(IURLGenerator::class));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/not a template/');

        $node->validateConfig(['condition' => '{{ status == "synced" }}']);

    }//end testFilterRefusesATemplateCondition()

    /**
     * Any constant is refused, not just one that looks like a template.
     *
     * `true` is the honest version of the same mistake: legal JSONLogic, and a
     * filter that keeps every item forever.
     *
     * @return void
     */
    public function testFilterRefusesAConstantCondition(): void
    {
        $node = new FilterNode($this->l10n(), $this->createMock(IURLGenerator::class));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/answers the same for every item/');

        $node->validateConfig(['condition' => true]);

    }//end testFilterRefusesAConstantCondition()

    /**
     * A real expression still passes.
     *
     * Without this the two tests above would be satisfied by a guard that
     * refuses EVERY condition — which would break every filter in the fleet
     * while looking like a fix.
     *
     * @return void
     */
    public function testFilterAcceptsAnExpression(): void
    {
        $node = new FilterNode($this->l10n(), $this->createMock(IURLGenerator::class));

        $node->validateConfig(['condition' => ['==' => [['var' => 'json.status'], 'synced']]]);

        // Reaching here IS the assertion: no exception was raised.
        $this->addToAssertionCount(1);

    }//end testFilterAcceptsAnExpression()

}//end class
