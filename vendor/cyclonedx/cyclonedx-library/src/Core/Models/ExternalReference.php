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

namespace CycloneDX\Core\Models;

use CycloneDX\Core\Collections\HashDictionary;
use CycloneDX\Core\Enums\ExternalReferenceType;

/**
 * External references provide a way to document systems, sites, and information that may be relevant
 * but which are not included with the BOM.
 *
 * @author jkowalleck
 */
class ExternalReference
{
    /**
     * Specifies the type of external reference. There are built-in types to describe common
     * references. If a type does not exist for the reference being referred to, use the "other" type.
     *
     * @psalm-suppress PropertyNotSetInConstructor
     */
    private ExternalReferenceType $type;

    /**
     * The URL to the external reference.
     *
     * @psalm-suppress PropertyNotSetInConstructor
     */
    private string $url;

    /**
     * An optional comment describing the external reference.
     */
    private ?string $comment = null;

    private HashDictionary $hashes;

    public function getType(): ExternalReferenceType
    {
        return $this->type;
    }

    /**
     * @return $this
     */
    public function setType(ExternalReferenceType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return $this
     */
    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * @return $this
     */
    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getHashes(): HashDictionary
    {
        return $this->hashes;
    }

    /**
     * @return $this
     */
    public function setHashes(HashDictionary $hashes): static
    {
        $this->hashes = $hashes;

        return $this;
    }

    public function __construct(ExternalReferenceType $type, string $url)
    {
        $this->setType($type);
        $this->setUrl($url);
        $this->hashes = new HashDictionary();
    }
}
