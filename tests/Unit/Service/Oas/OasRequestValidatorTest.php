<?php

/**
 * Unit tests for OasRequestValidator.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Oas;

use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use PHPUnit\Framework\TestCase;

class OasRequestValidatorTest extends TestCase
{

    private OasRequestValidator $validator;


    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new OasRequestValidator();

    }//end setUp()


    public function testValidBodyAgainstObjectSchemaReturnsEmptyErrors(): void
    {
        $schema = [
            'type'       => 'object',
            'required'   => ['title'],
            'properties' => [
                'title' => ['type' => 'string'],
                'age'   => ['type' => 'integer', 'minimum' => 0],
            ],
        ];
        $body = ['title' => 'Hello', 'age' => 30];

        $this->assertSame([], $this->validator->validate(body: $body, schema: $schema));
        $this->assertTrue($this->validator->isValid(body: $body, schema: $schema));

    }//end testValidBodyAgainstObjectSchemaReturnsEmptyErrors()


    public function testMissingRequiredFieldYieldsError(): void
    {
        $schema = [
            'type'       => 'object',
            'required'   => ['title'],
            'properties' => ['title' => ['type' => 'string']],
        ];

        $errors = $this->validator->validate(body: [], schema: $schema);
        $this->assertNotEmpty($errors);
        $this->assertFalse($this->validator->isValid(body: [], schema: $schema));

    }//end testMissingRequiredFieldYieldsError()


    public function testWrongTypeYieldsError(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => ['age' => ['type' => 'integer']],
        ];

        $errors = $this->validator->validate(body: ['age' => 'not-a-number'], schema: $schema);
        $this->assertNotEmpty($errors);

    }//end testWrongTypeYieldsError()


    public function testEnumViolationYieldsError(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['open', 'closed'],
                ],
            ],
        ];

        $errors = $this->validator->validate(body: ['status' => 'archived'], schema: $schema);
        $this->assertNotEmpty($errors);

    }//end testEnumViolationYieldsError()


    public function testEachErrorHasPathAndMessage(): void
    {
        $schema = [
            'type'       => 'object',
            'required'   => ['title'],
            'properties' => ['title' => ['type' => 'string']],
        ];

        $errors = $this->validator->validate(body: [], schema: $schema);
        foreach ($errors as $err) {
            $this->assertArrayHasKey('path', $err);
            $this->assertArrayHasKey('message', $err);
            $this->assertIsString($err['path']);
            $this->assertIsString($err['message']);
        }

    }//end testEachErrorHasPathAndMessage()


}//end class
