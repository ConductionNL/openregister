<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Service\OperatorEvaluator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class OperatorEvaluatorTest extends TestCase
{
    private OperatorEvaluator $evaluator;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->evaluator = new OperatorEvaluator($this->logger);
    }

    // ── $eq ──

    public function testEqMatchesStrictly(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator(5, ['$eq' => 5]));
    }

    public function testEqRejectsLooseMatch(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator('5', ['$eq' => 5]));
    }

    public function testEqRejectsDifferentValue(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator(4, ['$eq' => 5]));
    }

    // ── $ne ──

    public function testNeRejectsSameValue(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator(5, ['$ne' => 5]));
    }

    public function testNeMatchesDifferentValue(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator(4, ['$ne' => 5]));
    }

    // ── $in ──

    public function testInMatchesValueInArray(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator('a', ['$in' => ['a', 'b', 'c']]));
    }

    public function testInRejectsValueNotInArray(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator('d', ['$in' => ['a', 'b', 'c']]));
    }

    public function testInReturnsFalseForNonArrayOperand(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator('a', ['$in' => 'not-an-array']));
    }

    public function testInUsesStrictComparison(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator('1', ['$in' => [1, 2, 3]]));
    }

    // ── $nin ──

    public function testNinMatchesValueNotInArray(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator('d', ['$nin' => ['a', 'b', 'c']]));
    }

    public function testNinRejectsValueInArray(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator('a', ['$nin' => ['a', 'b', 'c']]));
    }

    public function testNinReturnsTrueForNonArrayOperand(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator('a', ['$nin' => 'not-an-array']));
    }

    // ── $contains ──
    //
    // The mirror image of $in: $in asks "is the object's scalar one of these
    // literals?", $contains asks "is this value one of the object's array
    // members?". Every case below is paired with the SQL the row-level path
    // emits (COALESCE(col, '[]') containment), because a disagreement between
    // the two is a silent access-control bug.

    public function testContainsMatchesMemberOfObjectArray(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator(['alice', 'bob'], ['$contains' => 'alice']));
    }

    public function testContainsRejectsNonMember(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator(['alice', 'bob'], ['$contains' => 'carol']));
    }

    public function testContainsRejectsNullProperty(): void
    {
        // SQL: COALESCE(NULL, '[]') contains nothing.
        $this->assertFalse($this->evaluator->valueMatchesOperator(null, ['$contains' => 'alice']));
    }

    public function testContainsRejectsScalarProperty(): void
    {
        // SQL: a scalar jsonb value never contains a one-element array.
        $this->assertFalse($this->evaluator->valueMatchesOperator('alice', ['$contains' => 'alice']));
    }

    public function testContainsRejectsEmptyObjectArray(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator([], ['$contains' => 'alice']));
    }

    public function testContainsRejectsNullOperand(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator(['alice'], ['$contains' => null]));
    }

    public function testContainsIsStrictAboutType(): void
    {
        // jsonb compares typed members: the string "1" is not the number 1.
        $this->assertFalse($this->evaluator->valueMatchesOperator([1], ['$contains' => '1']));
        $this->assertFalse($this->evaluator->valueMatchesOperator(['1'], ['$contains' => 1]));
    }

    public function testContainsWithArrayOperandIsAnyIntersection(): void
    {
        // $user.groups resolves to an array; the object matches when it lists ANY of them.
        $this->assertTrue($this->evaluator->valueMatchesOperator(['finance', 'hr'], ['$contains' => ['legal', 'hr']]));
    }

    public function testContainsWithArrayOperandRejectsDisjointSets(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator(['finance'], ['$contains' => ['legal', 'hr']]));
    }

    public function testContainsRejectsEmptyArrayOperand(): void
    {
        // No principal to match. Deliberately NOT the '' fallback that
        // MagicSearchHandler::buildArrayPropertyConditionSql uses for filters —
        // for an access-control operator that would admit any object whose list
        // happened to contain the empty string.
        $this->assertFalse($this->evaluator->valueMatchesOperator(['alice'], ['$contains' => []]));
    }

    public function testContainsIgnoresNullMembersOfAnArrayOperand(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator([null], ['$contains' => [null]]));
        $this->assertTrue($this->evaluator->valueMatchesOperator(['hr'], ['$contains' => [null, 'hr']]));
    }

    public function testContainsCombinesWithOtherOperatorsAsAnd(): void
    {
        $this->assertTrue(
            $this->evaluator->valueMatchesOperator(['alice'], ['$contains' => 'alice', '$exists' => true])
        );
        $this->assertFalse(
            $this->evaluator->valueMatchesOperator(['alice'], ['$contains' => 'carol', '$exists' => true])
        );
    }

    // ── $exists ──

    public function testExistsTrueMatchesNonNull(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator('something', ['$exists' => true]));
    }

    public function testExistsTrueRejectsNull(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator(null, ['$exists' => true]));
    }

    public function testExistsFalseMatchesNull(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator(null, ['$exists' => false]));
    }

    public function testExistsFalseRejectsNonNull(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator('something', ['$exists' => false]));
    }

    // ── $gt / $gte / $lt / $lte ──

    public function testGtMatches(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator(10, ['$gt' => 5]));
    }

    public function testGtRejectsEqual(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator(5, ['$gt' => 5]));
    }

    public function testGteMatchesEqual(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator(5, ['$gte' => 5]));
    }

    public function testGteMatchesGreater(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator(6, ['$gte' => 5]));
    }

    public function testLtMatches(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator(3, ['$lt' => 5]));
    }

    public function testLtRejectsEqual(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator(5, ['$lt' => 5]));
    }

    public function testLteMatchesEqual(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator(5, ['$lte' => 5]));
    }

    public function testLteMatchesLess(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator(4, ['$lte' => 5]));
    }

    // ── Combined operators ──

    public function testMultipleOperatorsMustAllPass(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator(7, ['$gt' => 5, '$lt' => 10]));
    }

    public function testMultipleOperatorsFailIfOneDoesNot(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator(12, ['$gt' => 5, '$lt' => 10]));
    }

    // ── Unknown operator ──

    public function testUnknownOperatorReturnsFalseAndLogsWarning(): void
    {
        // Fail-closed: an unknown operator MUST reject the match so malformed
        // rules cannot grant unintended access. This matches the SQL path,
        // which drops unknown-operator conditions and cannot satisfy the rule.
        $this->logger->expects($this->once())->method('warning');
        $this->assertFalse($this->evaluator->valueMatchesOperator(5, ['$unknown' => 1]));
    }

    // ── Empty operators ──

    public function testEmptyOperatorsReturnsTrue(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator('anything', []));
    }

    // ── Null-value semantics: SQL three-valued logic ──
    //
    // These tests encode SQL's rule that `NULL <op> X` evaluates to NULL (UNKNOWN),
    // which WHERE treats as false → the row is filtered out. The PHP evaluator
    // MUST match so the list and find endpoints produce identical verdicts.
    // Exceptions: $exists (explicit null check) and $eq with a null operand
    // (kept for backward-compat with "match missing field" rules).

    public function testGtAgainstNullValueReturnsFalse(): void
    {
        // SQL: NULL > 5 → NULL → filter out
        $this->assertFalse($this->evaluator->valueMatchesOperator(null, ['$gt' => 5]));
    }

    public function testGteAgainstNullValueReturnsFalse(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator(null, ['$gte' => 5]));
    }

    public function testLtAgainstNullValueReturnsFalse(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator(null, ['$lt' => 5]));
    }

    public function testLteAgainstNullValueReturnsFalse(): void
    {
        // The user-reported bug: publishedAt=null with $lte $now was returning true.
        $this->assertFalse($this->evaluator->valueMatchesOperator(null, ['$lte' => 5]));
    }

    public function testLteAgainstNullValueReturnsFalseForStringOperand(): void
    {
        // The exact reported shape: null publishedAt vs $now-resolved datetime string.
        $this->assertFalse(
            $this->evaluator->valueMatchesOperator(null, ['$lte' => '2026-04-24 14:00:00'])
        );
    }

    public function testComparisonOperatorsAgainstNullOperandReturnFalse(): void
    {
        // Symmetric: if the operand is null, the comparison is undefined.
        $this->assertFalse($this->evaluator->valueMatchesOperator(5, ['$gt' => null]));
        $this->assertFalse($this->evaluator->valueMatchesOperator(5, ['$lte' => null]));
    }

    public function testInAgainstNullValueReturnsFalse(): void
    {
        // SQL: NULL IN ('a', 'b') → NULL → filter out
        $this->assertFalse($this->evaluator->valueMatchesOperator(null, ['$in' => ['a', 'b']]));
    }

    public function testInAgainstNullValueWithNullInOperandArrayStillReturnsFalse(): void
    {
        // Regression: PHP's in_array(null, [null, 'x'], true) returns true, but
        // SQL NULL IN (NULL, 'x') evaluates to NULL → filter out. The explicit
        // null guard on operatorIn keeps the PHP verdict aligned.
        $this->assertFalse($this->evaluator->valueMatchesOperator(null, ['$in' => [null, 'x']]));
    }

    public function testNinAgainstNullValueReturnsFalse(): void
    {
        // SQL: NULL NOT IN ('a', 'b') → NULL → filter out. Conservative: deny.
        $this->assertFalse($this->evaluator->valueMatchesOperator(null, ['$nin' => ['a', 'b']]));
    }

    public function testNeAgainstNullValueReturnsFalse(): void
    {
        // SQL: NULL != 'x' → NULL → filter out.
        // Previously PHP returned true (null !== 'x'); now aligned with SQL.
        $this->assertFalse($this->evaluator->valueMatchesOperator(null, ['$ne' => 'x']));
    }

    public function testEqWithNullOperandAndNullValueStillMatchesForBackwardCompat(): void
    {
        // `$eq: null` is the only "match null" escape hatch in the grammar besides
        // $exists:false. Preserved for backward-compat even though SQL would filter
        // this out; rule authors who want strict-SQL behaviour should use $exists:false.
        $this->assertTrue($this->evaluator->valueMatchesOperator(null, ['$eq' => null]));
    }

    public function testExistsStillHonoursExplicitNullCheck(): void
    {
        // Sanity: $exists is the canonical null-aware operator — unchanged.
        $this->assertFalse($this->evaluator->valueMatchesOperator(null, ['$exists' => true]));
        $this->assertTrue($this->evaluator->valueMatchesOperator(null, ['$exists' => false]));
    }
}
