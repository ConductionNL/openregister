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

namespace CycloneDX\Core\_helpers;

use DOMDocument;

/**
 * Namespace of functions related to XML.
 *
 * @internal as this class may be affected by breaking changes without notice
 *
 * @author jkowalleck
 */
abstract class XML
{
    use UriTrait;

    /**
     * Make a string valid to XML::anyURI spec - best-effort.
     *
     * Complete and failsafe implementation is pretty context-dependent.
     * Best-effort solution: replacement & drop every URI that is not well-formed already.
     *
     * @see UriTrait::fixUriBE
     * @see filterAnyUri
     *
     * @return string|null string on success; null if encoding failed, or input was null
     */
    public static function encodeAnyUriBE(?string $uri): ?string
    {
        $uri = self::fixUriBE($uri);

        return null === $uri || self::filterAnyUri($uri)
            ? $uri
            : null; // @codeCoverageIgnore
    }

    /**
     * @SuppressWarnings(PHPMD.ErrorControlOperator) as there is no easy way to control libxml warning output
     */
    public static function filterAnyUri(string $uri): bool
    {
        $doc = new DOMDocument();
        $doc->appendChild($doc->createElement('t'))
            ->appendChild($doc->createCDATASection($uri));

        return @$doc->schemaValidateSource(
            <<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">
                    <xs:element name="t" type="xs:anyURI" />
                </xs:schema>
                XML
        );
    }
}
