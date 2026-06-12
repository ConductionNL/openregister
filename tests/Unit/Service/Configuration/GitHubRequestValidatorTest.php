<?php

/**
 * GitHubRequestValidator Unit Tests.
 *
 * Covers the pure-function input validators used by `GitHubIssuesController`'s guard
 * pipeline. Each method returns either `null` (continue) or a `JSONResponse` (short-circuit
 * with a 400 + structured error_code).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\GitHubRequestValidator;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for `GitHubRequestValidator`.
 *
 * @package OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @covers \OCA\OpenRegister\Service\Configuration\GitHubRequestValidator
 *
 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-11
 */
class GitHubRequestValidatorTest extends TestCase
{

    private GitHubRequestValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new GitHubRequestValidator();
    }//end setUp()

    /**
     * @return void
     */
    public function testValidRepoSlugPasses(): void
    {
        $this->assertNull($this->validator->validateRepoFormat('ConductionNL/openregister'));
    }//end testValidRepoSlugPasses()

    /**
     * @return void
     */
    public function testInvalidRepoFormatReturns400(): void
    {
        $response = $this->validator->validateRepoFormat('not-a-slug');
        $this->assertEquals(400, $this->extractStatus(response: $response));
        $this->assertEquals('repo_invalid_format', $this->extractErrorCode(response: $response));
    }//end testInvalidRepoFormatReturns400()

    /**
     * @return void
     */
    public function testPerPageWithinRangePasses(): void
    {
        $this->assertNull($this->validator->validatePerPage(1));
        $this->assertNull($this->validator->validatePerPage(30));
        $this->assertNull($this->validator->validatePerPage(100));
    }//end testPerPageWithinRangePasses()

    /**
     * @return void
     */
    public function testPerPageOutOfRangeReturns400(): void
    {
        $response = $this->validator->validatePerPage(0);
        $this->assertEquals('per_page_out_of_range', $this->extractErrorCode(response: $response));

        $response = $this->validator->validatePerPage(500);
        $this->assertEquals(400, $this->extractStatus(response: $response));
    }//end testPerPageOutOfRangeReturns400()

    /**
     * @return void
     */
    public function testTitleInRangePasses(): void
    {
        $this->assertNull($this->validator->validateTitleLength('A title'));
        $this->assertNull($this->validator->validateTitleLength(str_repeat('x', 200)));
    }//end testTitleInRangePasses()

    /**
     * @return void
     */
    public function testTitleTooShortReturns400(): void
    {
        $response = $this->validator->validateTitleLength('Hi');
        $this->assertEquals(400, $this->extractStatus(response: $response));
        $this->assertEquals('title_invalid_length', $this->extractErrorCode(response: $response));
    }//end testTitleTooShortReturns400()

    /**
     * @return void
     */
    public function testTitleTooLongReturns400(): void
    {
        $response = $this->validator->validateTitleLength(str_repeat('x', 201));
        $this->assertEquals('title_invalid_length', $this->extractErrorCode(response: $response));
    }//end testTitleTooLongReturns400()

    /**
     * @return void
     */
    public function testBodyAtMinimumPasses(): void
    {
        $this->assertNull($this->validator->validateBodyLength('1234567890'));
    }//end testBodyAtMinimumPasses()

    /**
     * @return void
     */
    public function testBodyTooShortReturns400(): void
    {
        $response = $this->validator->validateBodyLength('short');
        $this->assertEquals('body_invalid_length', $this->extractErrorCode(response: $response));
    }//end testBodyTooShortReturns400()

    /**
     * @return void
     */
    public function testValidSpecRefPasses(): void
    {
        $this->assertNull($this->validator->validateSpecRef('catalog-management'));
        $this->assertNull($this->validator->validateSpecRef(null));
    }//end testValidSpecRefPasses()

    /**
     * @return void
     */
    public function testSpecRefWithInvalidCharsReturns400(): void
    {
        $response = $this->validator->validateSpecRef('Bad Slug!');
        $this->assertEquals('specref_invalid_format', $this->extractErrorCode(response: $response));
    }//end testSpecRefWithInvalidCharsReturns400()

    /**
     * @return void
     */
    public function testSpecRefWithNewlineReturns400(): void
    {
        $response = $this->validator->validateSpecRef("slug\nnewline-content");
        $this->assertEquals('specref_invalid_format', $this->extractErrorCode(response: $response));
    }//end testSpecRefWithNewlineReturns400()

    /**
     * @return void
     */
    public function testSpecRefTooLongReturns400(): void
    {
        $response = $this->validator->validateSpecRef(str_repeat('a', 81));
        $this->assertEquals('specref_invalid_format', $this->extractErrorCode(response: $response));
    }//end testSpecRefTooLongReturns400()

    /**
     * @return void
     */
    public function testValidSortPasses(): void
    {
        foreach (['reactions-+1', 'created', 'updated', 'comments'] as $sort) {
            $this->assertNull($this->validator->validateSort($sort), $sort.' should pass');
        }
    }//end testValidSortPasses()

    /**
     * @return void
     */
    public function testInvalidSortReturns400(): void
    {
        $response = $this->validator->validateSort('stars');
        $this->assertEquals('sort_invalid_value', $this->extractErrorCode(response: $response));
    }//end testInvalidSortReturns400()

    /**
     * @return void
     */
    public function testNullLabelsPasses(): void
    {
        $this->assertNull($this->validator->validateLabels(null));
        $this->assertNull($this->validator->validateLabels([]));
    }//end testNullLabelsPasses()

    /**
     * @return void
     */
    public function testValidTwoLabelsPasses(): void
    {
        $this->assertNull($this->validator->validateLabels(['enhancement', 'feature']));
    }//end testValidTwoLabelsPasses()

    /**
     * @return void
     */
    public function testLabelsTooManyReturns400(): void
    {
        $response = $this->validator->validateLabels(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i']);
        $this->assertEquals('labels_too_many', $this->extractErrorCode(response: $response));
    }//end testLabelsTooManyReturns400()

    /**
     * @return void
     */
    public function testLabelsWithSqlInjectionShapeReturns400(): void
    {
        $response = $this->validator->validateLabels(['foo;DROP TABLE']);
        $this->assertEquals('labels_invalid_format', $this->extractErrorCode(response: $response));
    }//end testLabelsWithSqlInjectionShapeReturns400()

    /**
     * @return void
     */
    public function testLabelExceedingFiftyCharsReturns400(): void
    {
        $response = $this->validator->validateLabels([str_repeat('x', 51)]);
        $this->assertEquals('labels_invalid_format', $this->extractErrorCode(response: $response));
    }//end testLabelExceedingFiftyCharsReturns400()

    /**
     * Extract the HTTP status code from a JSONResponse.
     *
     * @param JSONResponse|null $response Response to inspect.
     *
     * @return int Status code, or 0 when null.
     */
    private function extractStatus(?JSONResponse $response): int
    {
        if ($response === null) {
            return 0;
        }

        return $response->getStatus();
    }//end extractStatus()

    /**
     * Extract the structured `error` field from a JSONResponse body.
     *
     * @param JSONResponse|null $response Response to inspect.
     *
     * @return string Error code, or empty when missing.
     */
    private function extractErrorCode(?JSONResponse $response): string
    {
        if ($response === null) {
            return '';
        }

        $data = $response->getData();
        if (is_array($data) === false) {
            return '';
        }

        return (string) ($data['error'] ?? '');
    }//end extractErrorCode()
}//end class
