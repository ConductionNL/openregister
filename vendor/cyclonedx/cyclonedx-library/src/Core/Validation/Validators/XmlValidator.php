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
use CycloneDX\Core\Validation\Errors\XmlValidationError;
use CycloneDX\Core\Validation\Exceptions\FailedLoadingSchemaException;
use DOMDocument;
use DOMException;
use LibXMLError;
use Override;

/**
 * @author jkowalleck
 */
class XmlValidator extends BaseValidator
{
    /**
     * @internal as this function may be affected by breaking changes without notice
     */
    #[Override]
    protected static function listSchemaFiles(): array
    {
        return [
            Version::v1dot1->value => Resources::FILE_CDX_XML_SCHEMA_1_1,
            Version::v1dot2->value => Resources::FILE_CDX_XML_SCHEMA_1_2,
            Version::v1dot3->value => Resources::FILE_CDX_XML_SCHEMA_1_3,
            Version::v1dot4->value => Resources::FILE_CDX_XML_SCHEMA_1_4,
            Version::v1dot5->value => Resources::FILE_CDX_XML_SCHEMA_1_5,
            Version::v1dot6->value => Resources::FILE_CDX_XML_SCHEMA_1_6,
            Version::v1dot7->value => Resources::FILE_CDX_XML_SCHEMA_1_7,
        ];
    }

    /**
     * @throws FailedLoadingSchemaException if schema file unknown or not readable
     * @throws DOMException                 if loading the DOM failed
     */
    #[Override]
    public function validateString(string $string): ?XmlValidationError
    {
        return $this->validateDom(
            $this->loadDomFromXml($string)
        );
    }

    /**
     * @throws FailedLoadingSchemaException
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function validateDom(DOMDocument $doc): ?XmlValidationError
    {
        $error = $this->validateDomWithSchema($doc);

        return $error
            ? XmlValidationError::fromLibXMLError($error)
            : null;
    }

    /**
     * @throws FailedLoadingSchemaException
     */
    private function validateDomWithSchema(DOMDocument $doc): ?LibXMLError
    {
        $schema = $this->getSchemaFile();

        $prevXmlUIE = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $valid = $doc->schemaValidate($schema);
        $error = $valid ? null : (libxml_get_last_error() ?: null);

        libxml_clear_errors();
        libxml_use_internal_errors($prevXmlUIE);

        return $error;
    }

    /**
     * @throws DOMException if loading the DOM failed
     */
    private function loadDomFromXml(string $xml): DOMDocument
    {
        $doc = new DOMDocument();
        $options = \LIBXML_NONET;
        if (\defined('LIBXML_COMPACT')) {
            $options |= \LIBXML_COMPACT;
        }
        if (\defined('LIBXML_PARSEHUGE')) {
            $options |= \LIBXML_PARSEHUGE;
        }

        $prevXmlUIE = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $loaded = $doc->loadXML($xml, $options);
        $error = $loaded ? null : libxml_get_last_error();

        libxml_clear_errors();
        libxml_use_internal_errors($prevXmlUIE);

        if ($error) {
            throw new DOMException('loading failed: '.$error->message);
        }

        return $doc;
    }
}
