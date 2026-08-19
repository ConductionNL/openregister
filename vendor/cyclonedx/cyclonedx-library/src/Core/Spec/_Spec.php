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

namespace CycloneDX\Core\Spec;

use CycloneDX\Core\Enums\ComponentType;
use CycloneDX\Core\Enums\ExternalReferenceType;
use CycloneDX\Core\Enums\HashAlgorithm;
use CycloneDX\Core\Spdx\LicenseIdentifiers;
use Override;

/**
 * This class is not intended to be public API.
 *
 * This is a helper to get the exact spec-versions implemented according to {@see _SpecProtocol Specification}.
 *
 * @internal as this class may be affected by breaking changes without notice
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.CamelCaseClassName)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @author jkowalleck
 */
class _Spec implements _SpecProtocol
{
    /** @psalm-var list<string> */
    private array $lLicenseIdentifiers;

    /**
     * @psalm-param list<Format> $lFormats
     * @psalm-param list<ComponentType> $lComponentTypes
     * @psalm-param list<HashAlgorithm> $lHashAlgorithms
     * @psalm-param non-empty-string $sHashContentRegex
     * @psalm-param list<ExternalReferenceType> $lExternalReferenceTypes
     * @psalm-param list<Format> $lFormatsSupportingBomProperties
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        private readonly Version $version,
        private readonly array $lFormats,
        private readonly array $lComponentTypes,
        private readonly array $lHashAlgorithms,
        private readonly string $sHashContentRegex,
        private readonly array $lExternalReferenceTypes,
        private readonly bool $bLicenseExpression,
        private readonly bool $bMetadata,
        private readonly bool $bBomRef,
        private readonly bool $bDependencies,
        private readonly bool $bExternalReferenceHashes,
        private readonly bool $bComponentVersionMandatory,
        private readonly bool $bToolExternalReferences,
        private readonly bool $bMetadataProperties,
        private readonly bool $bComponentAuthor,
        private readonly bool $bComponentProperties,
        private readonly bool $bComponentEvidence,
        private readonly array $lFormatsSupportingBomProperties,
        private readonly bool $bLicenseAcknowledgement,
        LicenseIdentifiers $licenseIdentifiers = new LicenseIdentifiers(),
    ) {
        $this->lLicenseIdentifiers = $licenseIdentifiers->getKnownLicenses();
    }

    #[Override]
    public function getVersion(): Version
    {
        return $this->version;
    }

    #[Override]
    public function isSupportedFormat(Format $format): bool
    {
        return \in_array($format, $this->lFormats, true);
    }

    #[Override]
    public function isSupportedComponentType(ComponentType $componentType): bool
    {
        return \in_array($componentType, $this->lComponentTypes, true);
    }

    #[Override]
    public function isSupportedHashAlgorithm(HashAlgorithm $alg): bool
    {
        return \in_array($alg, $this->lHashAlgorithms, true);
    }

    #[Override]
    public function isSupportedHashContent(string $content): bool
    {
        return 1 === preg_match($this->sHashContentRegex, $content);
    }

    #[Override]
    public function isSupportedExternalReferenceType(ExternalReferenceType $referenceType): bool
    {
        return \in_array($referenceType, $this->lExternalReferenceTypes, true);
    }

    #[Override]
    public function isSupportedLicenseIdentifier(string $licenseIdentifier): bool
    {
        return \in_array($licenseIdentifier, $this->lLicenseIdentifiers, true);
    }

    #[Override]
    public function supportsLicenseExpression(): bool
    {
        return $this->bLicenseExpression;
    }

    #[Override]
    public function supportsMetadata(): bool
    {
        return $this->bMetadata;
    }

    #[Override]
    public function supportsBomRef(): bool
    {
        return $this->bBomRef;
    }

    #[Override]
    public function supportsDependencies(): bool
    {
        return $this->bDependencies;
    }

    #[Override]
    public function supportsExternalReferenceHashes(): bool
    {
        return $this->bExternalReferenceHashes;
    }

    #[Override]
    public function requiresComponentVersion(): bool
    {
        return $this->bComponentVersionMandatory;
    }

    #[Override]
    public function supportsToolExternalReferences(): bool
    {
        return $this->bToolExternalReferences;
    }

    #[Override]
    public function supportsMetadataProperties(): bool
    {
        return $this->bMetadataProperties;
    }

    #[Override]
    public function supportsComponentAuthor(): bool
    {
        return $this->bComponentAuthor;
    }

    #[Override]
    public function supportsComponentProperties(): bool
    {
        return $this->bComponentProperties;
    }

    #[Override]
    public function supportsComponentEvidence(): bool
    {
        return $this->bComponentEvidence;
    }

    #[Override]
    public function supportsBomProperties(Format $format): bool
    {
        return \in_array($format, $this->lFormatsSupportingBomProperties, true);
    }

    #[Override]
    public function supportsLicenseAcknowledgement(): bool
    {
        return $this->bLicenseAcknowledgement;
    }
}
