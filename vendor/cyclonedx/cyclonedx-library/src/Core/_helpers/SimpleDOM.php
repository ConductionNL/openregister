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
use DOMElement;
use DOMNode;

/**
 * Namespace of functions related to DOM manipulation.
 *
 * @internal as this class may be affected by breaking changes without notice
 *
 * @author jkowalleck
 */
abstract class SimpleDOM
{
    /**
     * @psalm-param iterable<string, scalar|null> $attributes
     *
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    public static function setAttributes(DOMElement $element, iterable $attributes): DOMElement
    {
        foreach ($attributes as $attName => $attValue) {
            if (null === $attValue) {
                $element->removeAttribute($attName);
            } else {
                $element->setAttribute($attName, (string) $attValue);
            }
        }

        return $element;
    }

    /**
     * @psalm-param iterable<?DOMNode> $children
     */
    public static function appendChildren(DOMElement $element, iterable $children): DOMElement
    {
        foreach ($children as $child) {
            if (null !== $child) {
                $element->appendChild($child);
            }
        }

        return $element;
    }

    /**
     * @param string|int|float|object|null $data either (something that can be cast to `string`) or `null`
     * @param bool                         $null whether to return `null` when `$data` is `null`
     *
     * @return DOMElement|null `($null is true && $data is null ? null : DOMElement)`
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    public static function makeSafeTextElement(DOMDocument $document, string $name, string|int|float|object|null $data, bool $null = true): ?DOMElement
    {
        $element = $document->createElement($name);
        if (null !== $data) {
            \assert(!\is_object($data) || \is_callable([$data, '__toString']));
            // On runtime, could throw `Object of class C could not be converted to string`.
            // This is actually expected.
            // However, let's put an assert as a backup, so that envs with enabled `assert`s get more error output for debugging.
            // (And the static code analysers are happy when we formulate expectations and assertions)
            $element->appendChild($document->createCDATASection((string) $data));
        } elseif ($null) {
            return null;
        }

        return $element;
    }
}
