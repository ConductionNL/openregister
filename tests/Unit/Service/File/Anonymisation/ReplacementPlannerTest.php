<?php

/**
 * ReplacementPlannerTest
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File\Anonymisation;

use OCA\OpenRegister\Service\File\Anonymisation\PlanApplier;
use OCA\OpenRegister\Service\File\Anonymisation\ReplacementPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Covers the planner's decision rules.
 *
 * Every test here is a spec scenario, not an implementation detail — the point
 * of the planner is that these outcomes are guaranteed rather than emergent from
 * whatever order recognition happened to produce.
 *
 * @category Test
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/entity-replacement-planner/specs/entity-replacement-planner/spec.md
 */
final class ReplacementPlannerTest extends TestCase
{
    /**
     * Plan and apply in one step, which is how every caller uses it.
     *
     * @param array<string, string> $substitutions Needle => placeholder.
     * @param array<string, string> $types         Needle => entity type.
     * @param string                $text          The original text.
     *
     * @return string
     */
    private function redact(string $text, array $substitutions, array $types=[]): string
    {
        $planner = new ReplacementPlanner();
        $plan    = $planner->plan(text: $text, substitutions: $substitutions, entityTypes: $types);

        return (new PlanApplier())->applyToString(text: $text, plan: $plan);

    }//end redact()

    /**
     * The containment case that started this: an EMAIL containing a PERSON.
     *
     * Under sequential replacement without ordering, the PERSON needle turns
     * `robert@rjzondervan.nl` into `robert@[PERSOON: 1].nl`, leaving a local
     * part and a domain that are themselves identifying.
     *
     * @return void
     */
    public function testContainingEntityBeatsTheEntityNestedInsideIt(): void
    {
        $output = $this->redact(
            text: 'Mail robert@rjzondervan.nl voor vragen',
            substitutions: [
                'rjzondervan'           => '[PERSOON: 1]',
                'robert@rjzondervan.nl' => '[EMAIL: 2]',
            ],
            types: [
                'rjzondervan'           => 'PERSON',
                'robert@rjzondervan.nl' => 'EMAIL',
            ]
        );

        $this->assertSame('Mail [EMAIL: 2] voor vragen', $output);
        $this->assertStringNotContainsString('rjzondervan', $output);
        $this->assertStringNotContainsString('[PERSOON: 1]', $output);

    }//end testContainingEntityBeatsTheEntityNestedInsideIt()

    /**
     * The same result regardless of which order the entities were recognised in.
     *
     * @return void
     */
    public function testContainmentResultIsIndependentOfMapOrder(): void
    {
        $forward = $this->redact(
            text: 'Mail robert@rjzondervan.nl voor vragen',
            substitutions: [
                'robert@rjzondervan.nl' => '[EMAIL: 2]',
                'rjzondervan'           => '[PERSOON: 1]',
            ],
            types: ['robert@rjzondervan.nl' => 'EMAIL', 'rjzondervan' => 'PERSON']
        );

        $reverse = $this->redact(
            text: 'Mail robert@rjzondervan.nl voor vragen',
            substitutions: [
                'rjzondervan'           => '[PERSOON: 1]',
                'robert@rjzondervan.nl' => '[EMAIL: 2]',
            ],
            types: ['rjzondervan' => 'PERSON', 'robert@rjzondervan.nl' => 'EMAIL']
        );

        $this->assertSame($forward, $reverse);

    }//end testContainmentResultIsIndependentOfMapOrder()

    /**
     * A contained needle is NOT reported as unmatched — its text is gone.
     *
     * Reporting it would tell the operator PII remains when it does not, which
     * is the whole reason `subsumed` exists as a distinct outcome.
     *
     * @return void
     */
    public function testSubsumedNeedleIsNotReportedAsUnmatched(): void
    {
        $planner = new ReplacementPlanner();
        $plan    = $planner->plan(
            text: 'Mail robert@rjzondervan.nl voor vragen',
            substitutions: [
                'rjzondervan'           => '[PERSOON: 1]',
                'robert@rjzondervan.nl' => '[EMAIL: 2]',
            ],
            entityTypes: ['rjzondervan' => 'PERSON', 'robert@rjzondervan.nl' => 'EMAIL']
        );

        $this->assertSame([], $plan->getUnmatchedNeedles());
        $this->assertSame([], $plan->getPartialNeedles());
        $this->assertTrue($plan->isComplete());

    }//end testSubsumedNeedleIsNotReportedAsUnmatched()

    /**
     * Partial overlap: neither needle contains the other, they compete for
     * `Vries`. Longest-first cannot express competition and leaks `Bakker`.
     *
     * @return void
     */
    public function testPartiallyOverlappingSurnameDoesNotLeakItsTail(): void
    {
        $planner = new ReplacementPlanner();
        $text    = 'Betreft: Jan de Vries-Bakker';
        $plan    = $planner->plan(
            text: $text,
            substitutions: [
                'Jan de Vries' => '[PERSOON: 1]',
                'Vries-Bakker' => '[PERSOON: 2]',
            ],
            entityTypes: ['Jan de Vries' => 'PERSON', 'Vries-Bakker' => 'PERSON']
        );

        $output = (new PlanApplier())->applyToString(text: $text, plan: $plan);

        $this->assertStringNotContainsString('Vries', $output);
        $this->assertStringNotContainsString('Bakker', $output);
        $this->assertSame(['Vries-Bakker'], $plan->getPartialNeedles());
        $this->assertSame([], $plan->getUnmatchedNeedles());
        $this->assertSame(0, $plan->residualCount(), 'residual_count counts unmatched only');
        $this->assertFalse($plan->isComplete(), 'a split match is reported incomplete');

    }//end testPartiallyOverlappingSurnameDoesNotLeakItsTail()

    /**
     * Two short needles that together cover more than one long overlapping
     * needle are preferred. Greedy longest-first gets this wrong.
     *
     * @return void
     */
    public function testTwoShortEntitiesBeatOneLongOverlappingEntity(): void
    {
        // "Jan Jansen de Vries Bakker": the two outer names cover 10 + 12 = 22
        // codepoints; the middle needle "Jansen de Vries" covers 15 and overlaps
        // both, so it cannot be combined with either. All three sit on word
        // boundaries, so this is decided purely by coverage.
        //
        // Greedy longest-first would take the 15-codepoint needle first and then
        // reject both others as overlapping, covering 15 and LEAKING "Jan " and
        // " Bakker". Maximum coverage takes the pair.
        $planner = new ReplacementPlanner();
        $plan    = $planner->plan(
            text: 'Jan Jansen de Vries Bakker',
            substitutions: [
                'Jan Jansen'      => '[PERSOON: 1]',
                'Vries Bakker'    => '[PERSOON: 2]',
                'Jansen de Vries' => '[PERSOON: 3]',
            ],
            entityTypes: [
                'Jan Jansen'      => 'PERSON',
                'Vries Bakker'    => 'PERSON',
                'Jansen de Vries' => 'PERSON',
            ]
        );

        $direct  = array_filter(
            $plan->getRanges(),
            static function ($range): bool {
                return ($range->isResidue === false);
            }
        );
        $needles = array_map(
            static function ($range): string {
                return $range->needle;
            },
            array_values($direct)
        );
        sort($needles);

        $this->assertSame(['Jan Jansen', 'Vries Bakker'], $needles);

    }//end testTwoShortEntitiesBeatOneLongOverlappingEntity()

    /**
     * A short free-text name must not match inside an ordinary word.
     *
     * `Januari` is the Dutch case that makes this concrete.
     *
     * @return void
     */
    public function testShortFreeTextNameDoesNotMatchInsideAnOrdinaryWord(): void
    {
        $output = $this->redact(
            text: 'In Januari sprak Jan met Bas in het Bassin',
            substitutions: ['Jan' => '[PERSOON: 1]', 'Bas' => '[PERSOON: 2]'],
            types: ['Jan' => 'PERSON', 'Bas' => 'PERSON']
        );

        $this->assertSame('In Januari sprak [PERSOON: 1] met [PERSOON: 2] in het Bassin', $output);

    }//end testShortFreeTextNameDoesNotMatchInsideAnOrdinaryWord()

    /**
     * An IP address must not be matched inside a longer address.
     *
     * This is the case literal matching gets actively wrong: it emits
     * `[IP-ADRES: 6]0`, corrupting a DIFFERENT address and leaking a digit.
     *
     * @return void
     */
    public function testIpAddressIsNotMatchedInsideALongerAddress(): void
    {
        $output = $this->redact(
            text: 'verbinding van 192.168.1.1 naar 192.168.1.10',
            substitutions: ['192.168.1.1' => '[IP-ADRES: 6]'],
            types: ['192.168.1.1' => 'IP_ADDRESS']
        );

        $this->assertSame('verbinding van [IP-ADRES: 6] naar 192.168.1.10', $output);
        $this->assertStringNotContainsString('[IP-ADRES: 6]0', $output);

    }//end testIpAddressIsNotMatchedInsideALongerAddress()

    /**
     * A BSN must not be matched inside a longer digit run.
     *
     * @return void
     */
    public function testBsnIsNotMatchedInsideALongerDigitRun(): void
    {
        $output = $this->redact(
            text: 'dossier 1234567890 betreft bsn 123456789',
            substitutions: ['123456789' => '[BSN: 7]'],
            types: ['123456789' => 'SSN']
        );

        $this->assertSame('dossier 1234567890 betreft bsn [BSN: 7]', $output);

    }//end testBsnIsNotMatchedInsideALongerDigitRun()

    /**
     * A date matches before a sentence-final period but not inside a case
     * number. The separator only extends the numeric token when a digit
     * follows it, which is what keeps ordinary prose matchable.
     *
     * @return void
     */
    public function testDateIsNotMatchedInsideALongerNumberButIsBeforePunctuation(): void
    {
        $output = $this->redact(
            text: 'Zaaknummer 2026-0012, besloten op 2026.',
            substitutions: ['2026' => '[DATUM: 4]'],
            types: ['2026' => 'DATE']
        );

        $this->assertSame('Zaaknummer 2026-0012, besloten op [DATUM: 4].', $output);

    }//end testDateIsNotMatchedInsideALongerNumberButIsBeforePunctuation()

    /**
     * An internally concatenated or separated date matches as a whole.
     *
     * @return void
     */
    public function testInternallyJoinedDateMatchesAsAWhole(): void
    {
        $this->assertSame(
            'Datum [DATUM: 4] vastgesteld',
            $this->redact(
                text: 'Datum 20260803 vastgesteld',
                substitutions: ['20260803' => '[DATUM: 4]'],
                types: ['20260803' => 'DATE']
            )
        );

        $this->assertSame(
            'Datum [DATUM: 4] vastgesteld',
            $this->redact(
                text: 'Datum 03-08-2026 vastgesteld',
                substitutions: ['03-08-2026' => '[DATUM: 4]'],
                types: ['03-08-2026' => 'DATE']
            )
        );

    }//end testInternallyJoinedDateMatchesAsAWhole()

    /**
     * An IBAN stays literal, so it matches even when flanked by word
     * characters — the concatenated-label form.
     *
     * @return void
     */
    public function testIbanMatchesWhenFlankedByWordCharacters(): void
    {
        $output = $this->redact(
            text: 'IBANNL91ABNA0417164300x',
            substitutions: ['NL91ABNA0417164300' => '[IBAN: 3]'],
            types: ['NL91ABNA0417164300' => 'IBAN']
        );

        $this->assertSame('IBAN[IBAN: 3]x', $output);

    }//end testIbanMatchesWhenFlankedByWordCharacters()

    /**
     * An unenumerated type is word-bounded, NOT literal.
     *
     * @return void
     */
    public function testUnknownEntityTypeIsWordBoundedNotLiteral(): void
    {
        $output = $this->redact(
            text: 'polis 1234567 en referentie 12345678',
            substitutions: ['1234567' => '[POLISNUMMER: 5]'],
            types: ['1234567' => 'POLISNUMMER']
        );

        $this->assertSame('polis [POLISNUMMER: 5] en referentie 12345678', $output);

    }//end testUnknownEntityTypeIsWordBoundedNotLiteral()

    /**
     * Matching is case-insensitive.
     *
     * @return void
     */
    public function testMatchingIsCaseInsensitive(): void
    {
        $output = $this->redact(
            text: 'JAN JANSEN was hier',
            substitutions: ['Jan Jansen' => '[PERSOON: 1]'],
            types: ['Jan Jansen' => 'PERSON']
        );

        $this->assertSame('[PERSOON: 1] was hier', $output);

    }//end testMatchingIsCaseInsensitive()

    /**
     * Accented names fold correctly and offsets stay aligned to the original.
     *
     * A byte-oriented case-insensitive compare mangles this.
     *
     * @return void
     */
    public function testAccentedNeedleFoldsAndOffsetsStayAligned(): void
    {
        $output = $this->redact(
            text: 'Betreft mevrouw ANIÉLA Ötzü en verder niets',
            substitutions: ['aniéla ötzü' => '[PERSOON: 9]'],
            types: ['aniéla ötzü' => 'PERSON']
        );

        $this->assertSame('Betreft mevrouw [PERSOON: 9] en verder niets', $output);

    }//end testAccentedNeedleFoldsAndOffsetsStayAligned()

    /**
     * An emitted placeholder is never rescanned, even by a needle whose text
     * collides with the placeholder's own content.
     *
     * @return void
     */
    public function testEmittedPlaceholderIsNeverRescanned(): void
    {
        $output = $this->redact(
            text: 'Dossier 1 van Jan Jansen',
            substitutions: [
                'Jan Jansen' => '[PERSOON: 1]',
                '1'          => '[NUMMER: 2]',
            ],
            types: ['Jan Jansen' => 'PERSON', '1' => 'POLISNUMMER']
        );

        // The standalone 1 is replaced; the 1 inside the emitted placeholder is
        // not, because the output is built rather than mutated.
        $this->assertSame('Dossier [NUMMER: 2] van [PERSOON: 1]', $output);
        $this->assertSame(1, substr_count($output, '[PERSOON: 1]'));

    }//end testEmittedPlaceholderIsNeverRescanned()

    /**
     * Re-anonymising an already-anonymised document does not nest placeholders.
     *
     * @return void
     */
    public function testReanonymisingDoesNotNestPlaceholders(): void
    {
        $once = $this->redact(
            text: 'Jan Jansen belde',
            substitutions: ['Jan Jansen' => '[PERSOON: 1]'],
            types: ['Jan Jansen' => 'PERSON']
        );

        $twice = $this->redact(
            text: $once,
            substitutions: ['Jan Jansen' => '[PERSOON: 1]'],
            types: ['Jan Jansen' => 'PERSON']
        );

        $this->assertSame($once, $twice, 'anonymisation is idempotent');
        $this->assertStringNotContainsString('[PERSOON: [', $twice);

    }//end testReanonymisingDoesNotNestPlaceholders()

    /**
     * A purely numeric needle survives PHP coercing it to an int array key.
     *
     * @return void
     */
    public function testNumericNeedleSurvivesArrayKeyCoercion(): void
    {
        $substitutions = ['061234567' => '[TELEFOON: 3]'];
        $types         = ['061234567' => 'PHONE'];

        $output = $this->redact(text: 'bel 061234567 vandaag', substitutions: $substitutions, types: $types);

        $this->assertSame('bel [TELEFOON: 3] vandaag', $output);

    }//end testNumericNeedleSurvivesArrayKeyCoercion()

    /**
     * A needle that matches nowhere is reported as unmatched, and an
     * unseparated numeric identifier is exactly such a case.
     *
     * @return void
     */
    public function testUnseparatedNumericIdentifierIsRejectedAndReported(): void
    {
        $planner = new ReplacementPlanner();
        $plan    = $planner->plan(
            text: 'BSN123456789',
            substitutions: ['123456789' => '[BSN: 7]'],
            entityTypes: ['123456789' => 'SSN']
        );

        $this->assertSame([], $plan->getRanges());
        $this->assertSame(['123456789'], $plan->getUnmatchedNeedles());
        $this->assertSame(1, $plan->residualCount());
        $this->assertFalse($plan->isComplete());

    }//end testUnseparatedNumericIdentifierIsRejectedAndReported()

    /**
     * Accepted ranges never overlap and are ascending by start.
     *
     * @return void
     */
    public function testAcceptedRangesAreAscendingAndNonOverlapping(): void
    {
        $planner = new ReplacementPlanner();
        $plan    = $planner->plan(
            text: 'Jan de Vries-Bakker mailt robert@rjzondervan.nl over 192.168.1.1 en 192.168.1.10',
            substitutions: [
                'Jan de Vries'          => '[PERSOON: 1]',
                'Vries-Bakker'          => '[PERSOON: 2]',
                'rjzondervan'           => '[PERSOON: 3]',
                'robert@rjzondervan.nl' => '[EMAIL: 4]',
                '192.168.1.1'           => '[IP-ADRES: 5]',
            ],
            entityTypes: [
                'Jan de Vries'          => 'PERSON',
                'Vries-Bakker'          => 'PERSON',
                'rjzondervan'           => 'PERSON',
                'robert@rjzondervan.nl' => 'EMAIL',
                '192.168.1.1'           => 'IP_ADDRESS',
            ]
        );

        $previousEnd = -1;
        foreach ($plan->getRanges() as $range) {
            $this->assertGreaterThanOrEqual($previousEnd, $range->start, 'ranges must not overlap');
            $this->assertGreaterThan($range->start, $range->end, 'ranges must be non-empty');
            $previousEnd = $range->end;
        }

        $this->assertNotEmpty($plan->getRanges());

    }//end testAcceptedRangesAreAscendingAndNonOverlapping()

    /**
     * Selection is stable when the map's insertion order is shuffled.
     *
     * @return void
     */
    public function testSelectionIsStableAcrossShuffledInsertionOrder(): void
    {
        $text  = 'Jan de Vries-Bakker mailt robert@rjzondervan.nl op 2026-0012 en 2026.';
        $map   = [
            'Jan de Vries'          => '[PERSOON: 1]',
            'Vries-Bakker'          => '[PERSOON: 2]',
            'rjzondervan'           => '[PERSOON: 3]',
            'robert@rjzondervan.nl' => '[EMAIL: 4]',
            '2026'                  => '[DATUM: 5]',
        ];
        $types = [
            'Jan de Vries'          => 'PERSON',
            'Vries-Bakker'          => 'PERSON',
            'rjzondervan'           => 'PERSON',
            'robert@rjzondervan.nl' => 'EMAIL',
            '2026'                  => 'DATE',
        ];

        $baseline = $this->redact(text: $text, substitutions: $map, types: $types);

        foreach ([array_reverse($map, true), array_slice($map, 2, null, true) + $map] as $variant) {
            $this->assertSame(
                $baseline,
                $this->redact(text: $text, substitutions: $variant, types: $types),
                'output must not depend on recognition order'
            );
        }

    }//end testSelectionIsStableAcrossShuffledInsertionOrder()

    /**
     * Empty inputs are handled without error.
     *
     * @return void
     */
    public function testEmptyInputsAreSafe(): void
    {
        $planner = new ReplacementPlanner();

        $this->assertSame([], $planner->plan(text: '', substitutions: [])->getRanges());
        $this->assertSame([], $planner->plan(text: 'iets', substitutions: [])->getRanges());
        $this->assertSame(
            ['Jan'],
            $planner->plan(text: '', substitutions: ['Jan' => '[P: 1]'])->getUnmatchedNeedles()
        );

    }//end testEmptyInputsAreSafe()

    /**
     * Whitespace-only residue is dropped rather than redacted.
     *
     * @return void
     */
    public function testWhitespaceOnlyResidueIsDroppedAndNotReported(): void
    {
        // Literal types (IBAN) so boundary rules do not decide this — the point
        // is the residue rule alone. "AAAA" (2..6) and "BBBB" (7..11) cover 8
        // codepoints and win over "AAA BB" (3..9, 6 codepoints), which overlaps
        // both. The rejected needle's only uncovered gap is the single space at
        // offset 6, which carries no information and is therefore DROPPED
        // rather than redacted.
        $planner = new ReplacementPlanner();
        $text    = 'x AAAA BBBB y';
        $plan    = $planner->plan(
            text: $text,
            substitutions: [
                'AAAA'   => '[A]',
                'BBBB'   => '[B]',
                'AAA BB' => '[LONG]',
            ],
            entityTypes: ['AAAA' => 'IBAN', 'BBBB' => 'IBAN', 'AAA BB' => 'IBAN']
        );

        $output = (new PlanApplier())->applyToString(text: $text, plan: $plan);

        $this->assertSame('x [A] [B] y', $output, 'the space survives; no placeholder is emitted for it');
        $this->assertStringNotContainsString('[LONG]', $output);

        // And because that gap is information-free, the rejected needle must NOT
        // be reported as unmatched — telling the operator PII remains when only
        // a space does would be a false alarm.
        $this->assertSame([], $plan->getUnmatchedNeedles());
        $this->assertSame([], $plan->getPartialNeedles());
        $this->assertTrue($plan->isComplete());

    }//end testWhitespaceOnlyResidueIsDroppedAndNotReported()
}//end class
