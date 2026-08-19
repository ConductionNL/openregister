<?php

declare(strict_types=1);

/*
 * This file is part of CycloneDX PHP Library.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * SPDX-License-Identifier: Apache-2.0
 * Copyright (c) OWASP Foundation. All Rights Reserved.
 */

namespace CycloneDX\Core\Validation\Validators;

use CycloneDX\Core\Resources;
use CycloneDX\Core\Spec\Version;
use CycloneDX\Core\Validation\BaseValidator;
use CycloneDX\Core\Validation\Errors\JsonValidationError;
use CycloneDX\Core\Validation\Exceptions\FailedLoadingSchemaException;
use Exception;
use JsonException;
use Opis\JsonSchema;
use Override;
use stdClass;
use Throwable;

/**
 * @author jkowalleck
 */
class JsonValidator extends BaseValidator
{
    /**
     * @internal as this function may be affected by breaking changes without notice
     */
    #[Override]
    protected static function listSchemaFiles(): array
    {
        return [
            Version::v1dot1->value => null, // unsupported version
            Version::v1dot2->value => Resources::FILE_CDX_JSON_SCHEMA_1_2,
            Version::v1dot3->value => Resources::FILE_CDX_JSON_SCHEMA_1_3,
            Version::v1dot4->value => Resources::FILE_CDX_JSON_SCHEMA_1_4,
            Version::v1dot5->value => Resources::FILE_CDX_JSON_SCHEMA_1_5,
            Version::v1dot6->value => Resources::FILE_CDX_JSON_SCHEMA_1_6,
            Version::v1dot7->value => Resources::FILE_CDX_JSON_SCHEMA_1_7,
        ];
    }

    /**
     * @throws FailedLoadingSchemaException if schema file unknown or not readable
     * @throws JsonException                if loading the JSON failed
     */
    #[Override]
    public function validateString(string $string): ?JsonValidationError
    {
        return $this->validateData(
            $this->loadDataFromJson($string)
        );
    }

    /**
     * @throws FailedLoadingSchemaException
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function validateData(stdClass $data): ?JsonValidationError
    {
        $schemaId = uniqid('validate:cdx-php-lib?r=', true);
        $resolver = new JsonSchema\Resolvers\SchemaResolver();
        $resolver->registerFile($schemaId, $this->getSchemaFile());
        $resolver->registerPrefix('http://cyclonedx.org/schema/', Resources::DIR_SCHEMA);
        $validator = new JsonSchema\Validator();
        $validator->setResolver($resolver);
        try {
            $validationError = $validator->validate($data, $schemaId)->error();
            // @codeCoverageIgnoreStart
        } catch (Throwable $error) {
            return JsonValidationError::fromThrowable($error);
        }
        // @codeCoverageIgnoreEnd

        return null === $validationError
            ? null
            : JsonValidationError::fromSchemaValidationError($validationError);
    }

    /**
     * @throws JsonException if loading the JSON failed
     */
    private function loadDataFromJson(string $json): stdClass
    {
        try {
            $data = json_decode($json, false, 1024, \JSON_THROW_ON_ERROR);
        } catch (Exception $exception) {
            throw new JsonException('loading failed', previous: $exception);
        }
        \assert($data instanceof stdClass);

        return $data;
    }
}
