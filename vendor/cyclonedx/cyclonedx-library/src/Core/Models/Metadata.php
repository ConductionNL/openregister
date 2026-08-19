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

use CycloneDX\Core\Collections\PropertyRepository;
use CycloneDX\Core\Collections\ToolRepository;
use DateTimeInterface;

/**
 * @author jkowalleck
 */
class Metadata
{
    /**
     * The date and time (timestamp) when the BOM was created.
     */
    private ?DateTimeInterface $timestamp = null;

    /**
     * The tool(s) used in the creation of the BOM.
     */
    private ToolRepository $tools;

    /**
     * The component that the BOM describes.
     */
    private ?Component $component = null;

    /**
     * Provides the ability to document properties in a name-value store. This provides flexibility to include data not
     * officially supported in the standard without having to use additional namespaces or create extensions.
     * Unlike key-value stores, properties support duplicate names, each potentially having different values.
     *
     * Property names of interest to the general public are encouraged to be registered in the
     * {@link https://github.com/CycloneDX/cyclonedx-property-taxonomy CycloneDX Property Taxonomy}.
     * Formal registration is OPTIONAL.
     */
    private PropertyRepository $properties;

    public function getTimestamp(): ?DateTimeInterface
    {
        return $this->timestamp;
    }

    /**
     * @return $this
     */
    public function setTimestamp(?DateTimeInterface $timestamp): static
    {
        $this->timestamp = $timestamp;

        return $this;
    }

    public function getTools(): ToolRepository
    {
        return $this->tools;
    }

    /**
     * @return $this
     */
    public function setTools(ToolRepository $tools): static
    {
        $this->tools = $tools;

        return $this;
    }

    public function getComponent(): ?Component
    {
        return $this->component;
    }

    /**
     * @return $this
     */
    public function setComponent(?Component $component): static
    {
        $this->component = $component;

        return $this;
    }

    public function getProperties(): PropertyRepository
    {
        return $this->properties;
    }

    /**
     * @return $this
     */
    public function setProperties(PropertyRepository $properties): static
    {
        $this->properties = $properties;

        return $this;
    }

    public function __construct()
    {
        $this->tools = new ToolRepository();
        $this->properties = new PropertyRepository();
    }
}
