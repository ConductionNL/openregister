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

namespace CycloneDX\Core\Models\License;

use CycloneDX\Core\Enums\LicenseAcknowledgement;

/**
 * @internal as this class may be affected by breaking changes without notice
 *
 * @SuppressWarnings(PHPMD.CamelCaseClassName)
 *
 * @author jkowalleck
 */
trait _DisjunctiveLicenseBase
{
    /**
     * The URL to the license file.
     * If specified, a 'license' externalReference SHOULD also be specified for completeness.
     *
     * @psalm-var null|non-empty-string
     */
    private ?string $url = null;

    private ?LicenseAcknowledgement $acknowledgement = null;

    /** @psalm-return null|non-empty-string */
    public function getUrl(): ?string
    {
        return $this->url;
    }

    /** @return $this */
    public function setUrl(?string $url): static
    {
        $this->url = '' === $url
            ? null
            : $url;

        return $this;
    }

    public function getAcknowledgement(): ?LicenseAcknowledgement
    {
        return $this->acknowledgement;
    }

    /** @return $this */
    public function setAcknowledgement(?LicenseAcknowledgement $acknowledgement): static
    {
        $this->acknowledgement = $acknowledgement;

        return $this;
    }
}
