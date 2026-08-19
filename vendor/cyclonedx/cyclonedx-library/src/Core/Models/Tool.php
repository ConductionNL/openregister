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

use CycloneDX\Core\Collections\ExternalReferenceRepository;
use CycloneDX\Core\Collections\HashDictionary;

/**
 * @author jkowalleck
 */
class Tool
{
    /**
     * The vendor of the tool used to create the BOM.
     */
    private ?string $vendor = null;

    /**
     * The name of the tool used to create the BOM.
     */
    private ?string $name = null;

    /**
     * The version of the tool used to create the BOM.
     */
    private ?string $version = null;

    /**
     * The hashes of the tool (if applicable).
     */
    private HashDictionary $hashes;

    /**
     * Provides the ability to document external references related to the tool.
     */
    private ExternalReferenceRepository $externalReferences;

    public function getVendor(): ?string
    {
        return $this->vendor;
    }

    /**
     * @return $this
     */
    public function setVendor(?string $vendor): static
    {
        $this->vendor = $vendor;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @return $this
     */
    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    /**
     * @return $this
     */
    public function setVersion(?string $version): static
    {
        $this->version = $version;

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

    public function getExternalReferences(): ExternalReferenceRepository
    {
        return $this->externalReferences;
    }

    /**
     * @return $this
     */
    public function setExternalReferences(ExternalReferenceRepository $externalReferences): static
    {
        $this->externalReferences = $externalReferences;

        return $this;
    }

    public function __construct()
    {
        $this->hashes = new HashDictionary();
        $this->externalReferences = new ExternalReferenceRepository();
    }
}
