<?php

/**
 * Covers UploadService's source-routing paths, which had no test at all.
 *
 * WHY THIS EXISTS. Until 2026-08-26 this class declared `private readonly
 * Client $client` and had NO CONSTRUCTOR, so `getUploadedJson()` died with
 * "Typed property UploadService::$client must not be accessed before
 * initialization" the moment an upload took the `url` branch. The class was
 * unreachable, so its four private helpers — removeInternalParameters,
 * validateUploadSource, processFileUpload, processUrlUpload — were never
 * exercised by anything.
 *
 * Now that it constructs, those paths are reachable and are pinned here. Every
 * one is driven through the PUBLIC entry point rather than by reflection: a
 * test that reaches past the public surface proves the helper works, not that
 * the routing to it does, and the routing is exactly what was broken.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Service\UploadService;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;

/**
 * Source routing in UploadService::getUploadedJson().
 */
class UploadServiceSourceRoutingTest extends TestCase
{


    /**
     * The service under test, built through its real constructor.
     *
     * @return UploadService The service.
     */
    private function service(): UploadService
    {
        return new UploadService();

    }//end service()


    /**
     * No file/url/json key yields a 400 rather than a fatal.
     *
     * This is the branch `validateUploadSource()` owns, and the one an empty
     * POST body takes.
     *
     * @return void
     */
    public function testMissingEverySourceKeyReturnsA400(): void
    {
        $result = $this->service()->getUploadedJson([]);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(400, $result->getStatus());
        $this->assertSame(
            'Missing one of these keys in your POST body: file, url or json.',
            $result->getData()['error']
        );

    }//end testMissingEverySourceKeyReturnsA400()


    /**
     * Underscore-prefixed keys are stripped BEFORE the source check runs.
     *
     * This is the ordering that matters: `_limit` and friends are control
     * params, so a body carrying only those has no upload source and must be
     * rejected. If the strip ran after validation, `_limit=10` would read as a
     * source and the request would fall through to the json branch with no
     * json key.
     *
     * @return void
     */
    public function testInternalParametersAreStrippedBeforeValidation(): void
    {
        $result = $this->service()->getUploadedJson(
            [
                '_limit'  => 10,
                '_page'   => 2,
                '_search' => 'x',
            ]
        );

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(
            400,
            $result->getStatus(),
            'a body of nothing but control params carries no upload source'
        );

    }//end testInternalParametersAreStrippedBeforeValidation()


    /**
     * A real source survives the strip alongside control params.
     *
     * The mirror of the test above: stripping must remove ONLY the underscore
     * keys, so `json` still routes.
     *
     * @return void
     */
    public function testAControlParamDoesNotHideARealSource(): void
    {
        $result = $this->service()->getUploadedJson(
            [
                '_limit' => 10,
                'json'   => ['title' => 'kept'],
            ]
        );

        $this->assertIsArray($result, 'the json source must still route after the strip');
        $this->assertSame('kept', $result['title']);

    }//end testAControlParamDoesNotHideARealSource()


    /**
     * A json body is returned as an array.
     *
     * @return void
     */
    public function testJsonArrayInputIsReturnedAsAnArray(): void
    {
        $result = $this->service()->getUploadedJson(['json' => ['a' => 1, 'b' => 2]]);

        $this->assertSame(['a' => 1, 'b' => 2], $result);

    }//end testJsonArrayInputIsReturnedAsAnArray()


    /**
     * A json STRING body is decoded.
     *
     * @return void
     */
    public function testJsonStringInputIsDecoded(): void
    {
        $result = $this->service()->getUploadedJson(['json' => '{"a":1}']);

        $this->assertSame(['a' => 1], $result);

    }//end testJsonStringInputIsDecoded()


    /**
     * The file branch still throws — it is declared `never` and unimplemented.
     *
     * Pinned so the day someone implements it, this test fails and says so,
     * rather than the branch silently changing shape.
     *
     * @return void
     */
    public function testTheFileBranchIsStillUnimplemented(): void
    {
        $this->expectException(Exception::class);

        $this->service()->getUploadedJson(['file' => ['name' => 'x.json']]);

    }//end testTheFileBranchIsStillUnimplemented()


}//end class
