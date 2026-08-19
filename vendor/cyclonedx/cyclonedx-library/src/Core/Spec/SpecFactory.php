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
use DomainException;

/**
 * Factory for {@see _SpecProtocol Specification} objects.
 */
abstract class SpecFactory
{
    /* IDEA
    // have the instances in a static const cache, tht is held by WeakReferences
    // see https://www.php.net/manual/en/class.weakreference.php
    private const $cache = []
    $spec = $cache[Version::v1dot1]?->get();
    if (null === $spec) {
        $spec = new Spec();
        $cache[Version::v1dot1] = WeakReference::create($spec);
    }
    return $spec
    ...
    */

    /**
     * Create the appropriate {@see _SpecProtocol Specification} based on {@see Version}.
     *
     * @throws DomainException when $version was unsupported
     */
    public static function makeForVersion(Version $version): _SpecProtocol
    {
        return match ($version) {
            Version::v1dot1 => self::make1dot1(),
            Version::v1dot2 => self::make1dot2(),
            Version::v1dot3 => self::make1dot3(),
            Version::v1dot4 => self::make1dot4(),
            Version::v1dot5 => self::make1dot5(),
            Version::v1dot6 => self::make1dot6(),
            Version::v1dot7 => self::make1dot7(),
            /* just in case fallback */
            default => throw new DomainException('unsupported version: '.print_r($version, true)),
        };
    }

    /**
     * Create the {@see _SpecProtocol Specification} based on {@see Version::v1dot1}.
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public static function make1dot1(): _SpecProtocol
    {
        return new _Spec(
            Version::v1dot1,
            [
                Format::XML,
            ],
            [
                ComponentType::Application,
                ComponentType::Framework,
                ComponentType::Library,
                ComponentType::OperatingSystem,
                ComponentType::Device,
                ComponentType::File,
            ],
            [
                HashAlgorithm::MD5,
                HashAlgorithm::SHA_1,
                HashAlgorithm::SHA_256,
                HashAlgorithm::SHA_384,
                HashAlgorithm::SHA_512,
                HashAlgorithm::SHA3_256,
                HashAlgorithm::SHA3_512,
            ],
            '/^(?:[a-fA-F0-9]{32}|[a-fA-F0-9]{40}|[a-fA-F0-9]{64}|[a-fA-F0-9]{96}|[a-fA-F0-9]{128})$/',
            [
                ExternalReferenceType::VCS,
                ExternalReferenceType::IssueTracker,
                ExternalReferenceType::Website,
                ExternalReferenceType::Advisories,
                ExternalReferenceType::BOM,
                ExternalReferenceType::MailingList,
                ExternalReferenceType::Social,
                ExternalReferenceType::Chat,
                ExternalReferenceType::Documentation,
                ExternalReferenceType::Support,
                ExternalReferenceType::Distribution,
                ExternalReferenceType::License,
                ExternalReferenceType::BuildMeta,
                ExternalReferenceType::BuildSystem,
                ExternalReferenceType::Other,
            ],
            true,
            false,
            false,
            false,
            false,
            true,
            false,
            false,
            false,
            false,
            false,
            [],
            false,
        );
    }

    /**
     * Create the {@see _SpecProtocol Specification} based on {@see Version::v1dot2}.
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public static function make1dot2(): _SpecProtocol
    {
        return new _Spec(
            Version::v1dot2,
            [
                Format::XML,
                Format::JSON,
            ],
            [
                ComponentType::Application,
                ComponentType::Framework,
                ComponentType::Library,
                ComponentType::OperatingSystem,
                ComponentType::Device,
                ComponentType::File,
                ComponentType::Container,
                ComponentType::Firmware,
            ],
            [
                HashAlgorithm::MD5,
                HashAlgorithm::SHA_1,
                HashAlgorithm::SHA_256,
                HashAlgorithm::SHA_384,
                HashAlgorithm::SHA_512,
                HashAlgorithm::SHA3_256,
                HashAlgorithm::SHA3_384,
                HashAlgorithm::SHA3_512,
                HashAlgorithm::BLAKE2b_256,
                HashAlgorithm::BLAKE2b_384,
                HashAlgorithm::BLAKE2b_512,
                HashAlgorithm::BLAKE3,
            ],
            '/^(?:[a-fA-F0-9]{32}|[a-fA-F0-9]{40}|[a-fA-F0-9]{64}|[a-fA-F0-9]{96}|[a-fA-F0-9]{128})$/',
            [
                ExternalReferenceType::VCS,
                ExternalReferenceType::IssueTracker,
                ExternalReferenceType::Website,
                ExternalReferenceType::Advisories,
                ExternalReferenceType::BOM,
                ExternalReferenceType::MailingList,
                ExternalReferenceType::Social,
                ExternalReferenceType::Chat,
                ExternalReferenceType::Documentation,
                ExternalReferenceType::Support,
                ExternalReferenceType::Distribution,
                ExternalReferenceType::License,
                ExternalReferenceType::BuildMeta,
                ExternalReferenceType::BuildSystem,
                ExternalReferenceType::Other,
            ],
            true,
            true,
            true,
            true,
            false,
            true,
            false,
            false,
            true,
            false,
            false,
            [],
            false,
        );
    }

    /**
     * Create the {@see _SpecProtocol Specification} based on {@see Version::v1dot3}.
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public static function make1dot3(): _SpecProtocol
    {
        return new _Spec(
            Version::v1dot3,
            [
                Format::XML,
                Format::JSON,
                Format::ProtoBuff,
            ],
            [
                ComponentType::Application,
                ComponentType::Framework,
                ComponentType::Library,
                ComponentType::OperatingSystem,
                ComponentType::Device,
                ComponentType::File,
                ComponentType::Container,
                ComponentType::Firmware,
            ],
            [
                HashAlgorithm::MD5,
                HashAlgorithm::SHA_1,
                HashAlgorithm::SHA_256,
                HashAlgorithm::SHA_384,
                HashAlgorithm::SHA_512,
                HashAlgorithm::SHA3_256,
                HashAlgorithm::SHA3_384,
                HashAlgorithm::SHA3_512,
                HashAlgorithm::BLAKE2b_256,
                HashAlgorithm::BLAKE2b_384,
                HashAlgorithm::BLAKE2b_512,
                HashAlgorithm::BLAKE3,
            ],
            '/^(?:[a-fA-F0-9]{32}|[a-fA-F0-9]{40}|[a-fA-F0-9]{64}|[a-fA-F0-9]{96}|[a-fA-F0-9]{128})$/',
            [
                ExternalReferenceType::VCS,
                ExternalReferenceType::IssueTracker,
                ExternalReferenceType::Website,
                ExternalReferenceType::Advisories,
                ExternalReferenceType::BOM,
                ExternalReferenceType::MailingList,
                ExternalReferenceType::Social,
                ExternalReferenceType::Chat,
                ExternalReferenceType::Documentation,
                ExternalReferenceType::Support,
                ExternalReferenceType::Distribution,
                ExternalReferenceType::License,
                ExternalReferenceType::BuildMeta,
                ExternalReferenceType::BuildSystem,
                ExternalReferenceType::Other,
            ],
            true,
            true,
            true,
            true,
            true,
            true,
            false,
            true,
            true,
            true,
            true,
            [
                Format::XML,
            ],
            false,
        );
    }

    /**
     * Create the {@see _SpecProtocol Specification} based on {@see Version::v1dot4}.
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public static function make1dot4(): _SpecProtocol
    {
        return new _Spec(
            Version::v1dot4,
            [
                Format::XML,
                Format::JSON,
                Format::ProtoBuff,
            ],
            [
                ComponentType::Application,
                ComponentType::Framework,
                ComponentType::Library,
                ComponentType::OperatingSystem,
                ComponentType::Device,
                ComponentType::File,
                ComponentType::Container,
                ComponentType::Firmware,
            ],
            [
                HashAlgorithm::MD5,
                HashAlgorithm::SHA_1,
                HashAlgorithm::SHA_256,
                HashAlgorithm::SHA_384,
                HashAlgorithm::SHA_512,
                HashAlgorithm::SHA3_256,
                HashAlgorithm::SHA3_384,
                HashAlgorithm::SHA3_512,
                HashAlgorithm::BLAKE2b_256,
                HashAlgorithm::BLAKE2b_384,
                HashAlgorithm::BLAKE2b_512,
                HashAlgorithm::BLAKE3,
            ],
            '/^(?:[a-fA-F0-9]{32}|[a-fA-F0-9]{40}|[a-fA-F0-9]{64}|[a-fA-F0-9]{96}|[a-fA-F0-9]{128})$/',
            [
                ExternalReferenceType::VCS,
                ExternalReferenceType::IssueTracker,
                ExternalReferenceType::Website,
                ExternalReferenceType::Advisories,
                ExternalReferenceType::BOM,
                ExternalReferenceType::MailingList,
                ExternalReferenceType::Social,
                ExternalReferenceType::Chat,
                ExternalReferenceType::Documentation,
                ExternalReferenceType::Support,
                ExternalReferenceType::Distribution,
                ExternalReferenceType::License,
                ExternalReferenceType::BuildMeta,
                ExternalReferenceType::BuildSystem,
                ExternalReferenceType::ReleaseNotes,
                ExternalReferenceType::Other,
            ],
            true,
            true,
            true,
            true,
            true,
            false,
            true,
            true,
            true,
            true,
            true,
            [
                Format::XML,
            ],
            false,
        );
    }

    /**
     * Create the {@see _SpecProtocol Specification} based on {@see Version::v1dot5}.
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public static function make1dot5(): _SpecProtocol
    {
        return new _Spec(
            Version::v1dot5,
            [
                Format::XML,
                Format::JSON,
                Format::ProtoBuff,
            ],
            [
                ComponentType::Application,
                ComponentType::Framework,
                ComponentType::Library,
                ComponentType::Container,
                ComponentType::Platform,
                ComponentType::OperatingSystem,
                ComponentType::Device,
                ComponentType::DeviceDriver,
                ComponentType::Firmware,
                ComponentType::File,
                ComponentType::MachineLearningModel,
                ComponentType::Data,
            ],
            [
                HashAlgorithm::MD5,
                HashAlgorithm::SHA_1,
                HashAlgorithm::SHA_256,
                HashAlgorithm::SHA_384,
                HashAlgorithm::SHA_512,
                HashAlgorithm::SHA3_256,
                HashAlgorithm::SHA3_384,
                HashAlgorithm::SHA3_512,
                HashAlgorithm::BLAKE2b_256,
                HashAlgorithm::BLAKE2b_384,
                HashAlgorithm::BLAKE2b_512,
                HashAlgorithm::BLAKE3,
            ],
            '/^(?:[a-fA-F0-9]{32}|[a-fA-F0-9]{40}|[a-fA-F0-9]{64}|[a-fA-F0-9]{96}|[a-fA-F0-9]{128})$/',
            [
                ExternalReferenceType::VCS,
                ExternalReferenceType::IssueTracker,
                ExternalReferenceType::Website,
                ExternalReferenceType::Advisories,
                ExternalReferenceType::BOM,
                ExternalReferenceType::MailingList,
                ExternalReferenceType::Social,
                ExternalReferenceType::Chat,
                ExternalReferenceType::Documentation,
                ExternalReferenceType::Support,
                ExternalReferenceType::Distribution,
                ExternalReferenceType::DistributionIntake,
                ExternalReferenceType::License,
                ExternalReferenceType::BuildMeta,
                ExternalReferenceType::BuildSystem,
                ExternalReferenceType::ReleaseNotes,
                ExternalReferenceType::SecurityContact,
                ExternalReferenceType::ModelCard,
                ExternalReferenceType::Log,
                ExternalReferenceType::Configuration,
                ExternalReferenceType::Evidence,
                ExternalReferenceType::Formulation,
                ExternalReferenceType::Attestation,
                ExternalReferenceType::ThreatModel,
                ExternalReferenceType::AdversaryModel,
                ExternalReferenceType::RiskAssessment,
                ExternalReferenceType::VulnerabilityAssertion,
                ExternalReferenceType::ExploitabilityStatement,
                ExternalReferenceType::PentestReport,
                ExternalReferenceType::StaticAnalysisReport,
                ExternalReferenceType::DynamicAnalysisReport,
                ExternalReferenceType::RuntimeAnalysisReport,
                ExternalReferenceType::ComponentAnalysisReport,
                ExternalReferenceType::MaturityReport,
                ExternalReferenceType::CertificationReport,
                ExternalReferenceType::CodifiedInfrastructure,
                ExternalReferenceType::QualityMetrics,
                ExternalReferenceType::POAM,
                ExternalReferenceType::Other,
            ],
            true,
            true,
            true,
            true,
            true,
            false,
            true,
            true,
            true,
            true,
            true,
            [
                Format::XML,
                Format::JSON,
                Format::ProtoBuff,
            ],
            false,
        );
    }

    /**
     * Create the {@see _SpecProtocol Specification} based on {@see Version::v1dot6}.
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public static function make1dot6(): _SpecProtocol
    {
        return new _Spec(
            Version::v1dot6,
            [
                Format::XML,
                Format::JSON,
                Format::ProtoBuff,
            ],
            [
                ComponentType::Application,
                ComponentType::Framework,
                ComponentType::Library,
                ComponentType::Container,
                ComponentType::Platform,
                ComponentType::OperatingSystem,
                ComponentType::Device,
                ComponentType::DeviceDriver,
                ComponentType::Firmware,
                ComponentType::File,
                ComponentType::MachineLearningModel,
                ComponentType::Data,
                ComponentType::CryptographicAsset,
            ],
            [
                HashAlgorithm::MD5,
                HashAlgorithm::SHA_1,
                HashAlgorithm::SHA_256,
                HashAlgorithm::SHA_384,
                HashAlgorithm::SHA_512,
                HashAlgorithm::SHA3_256,
                HashAlgorithm::SHA3_384,
                HashAlgorithm::SHA3_512,
                HashAlgorithm::BLAKE2b_256,
                HashAlgorithm::BLAKE2b_384,
                HashAlgorithm::BLAKE2b_512,
                HashAlgorithm::BLAKE3,
            ],
            '/^(?:[a-fA-F0-9]{32}|[a-fA-F0-9]{40}|[a-fA-F0-9]{64}|[a-fA-F0-9]{96}|[a-fA-F0-9]{128})$/',
            [
                ExternalReferenceType::VCS,
                ExternalReferenceType::IssueTracker,
                ExternalReferenceType::Website,
                ExternalReferenceType::Advisories,
                ExternalReferenceType::BOM,
                ExternalReferenceType::MailingList,
                ExternalReferenceType::Social,
                ExternalReferenceType::Chat,
                ExternalReferenceType::Documentation,
                ExternalReferenceType::Support,
                ExternalReferenceType::SourceDistribution,
                ExternalReferenceType::Distribution,
                ExternalReferenceType::DistributionIntake,
                ExternalReferenceType::License,
                ExternalReferenceType::BuildMeta,
                ExternalReferenceType::BuildSystem,
                ExternalReferenceType::ReleaseNotes,
                ExternalReferenceType::SecurityContact,
                ExternalReferenceType::ModelCard,
                ExternalReferenceType::Log,
                ExternalReferenceType::Configuration,
                ExternalReferenceType::Evidence,
                ExternalReferenceType::Formulation,
                ExternalReferenceType::Attestation,
                ExternalReferenceType::ThreatModel,
                ExternalReferenceType::AdversaryModel,
                ExternalReferenceType::RiskAssessment,
                ExternalReferenceType::VulnerabilityAssertion,
                ExternalReferenceType::ExploitabilityStatement,
                ExternalReferenceType::PentestReport,
                ExternalReferenceType::StaticAnalysisReport,
                ExternalReferenceType::DynamicAnalysisReport,
                ExternalReferenceType::RuntimeAnalysisReport,
                ExternalReferenceType::ComponentAnalysisReport,
                ExternalReferenceType::MaturityReport,
                ExternalReferenceType::CertificationReport,
                ExternalReferenceType::CodifiedInfrastructure,
                ExternalReferenceType::QualityMetrics,
                ExternalReferenceType::POAM,
                ExternalReferenceType::ElectronicSignature,
                ExternalReferenceType::DigitalSignature,
                ExternalReferenceType::RFC9116,
                ExternalReferenceType::Other,
            ],
            true,
            true,
            true,
            true,
            true,
            false,
            true,
            true,
            true,
            true,
            true,
            [
                Format::XML,
                Format::JSON,
                Format::ProtoBuff,
            ],
            true,
        );
    }

    /**
     * Create the {@see _SpecProtocol Specification} based on {@see Version::v1dot7}.
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public static function make1dot7(): _SpecProtocol
    {
        return new _Spec(
            Version::v1dot7,
            [
                Format::XML,
                Format::JSON,
                Format::ProtoBuff,
            ],
            [
                ComponentType::Application,
                ComponentType::Framework,
                ComponentType::Library,
                ComponentType::Container,
                ComponentType::Platform,
                ComponentType::OperatingSystem,
                ComponentType::Device,
                ComponentType::DeviceDriver,
                ComponentType::Firmware,
                ComponentType::File,
                ComponentType::MachineLearningModel,
                ComponentType::Data,
                ComponentType::CryptographicAsset,
            ],
            [
                HashAlgorithm::MD5,
                HashAlgorithm::SHA_1,
                HashAlgorithm::SHA_256,
                HashAlgorithm::SHA_384,
                HashAlgorithm::SHA_512,
                HashAlgorithm::SHA3_256,
                HashAlgorithm::SHA3_384,
                HashAlgorithm::SHA3_512,
                HashAlgorithm::BLAKE2b_256,
                HashAlgorithm::BLAKE2b_384,
                HashAlgorithm::BLAKE2b_512,
                HashAlgorithm::BLAKE3,
                HashAlgorithm::STREEBOG_256,
                HashAlgorithm::STREEBOG_512,
            ],
            '/^(?:[a-fA-F0-9]{32}|[a-fA-F0-9]{40}|[a-fA-F0-9]{64}|[a-fA-F0-9]{96}|[a-fA-F0-9]{128})$/',
            [
                ExternalReferenceType::VCS,
                ExternalReferenceType::IssueTracker,
                ExternalReferenceType::Website,
                ExternalReferenceType::Advisories,
                ExternalReferenceType::BOM,
                ExternalReferenceType::MailingList,
                ExternalReferenceType::Social,
                ExternalReferenceType::Chat,
                ExternalReferenceType::Documentation,
                ExternalReferenceType::Support,
                ExternalReferenceType::SourceDistribution,
                ExternalReferenceType::Distribution,
                ExternalReferenceType::DistributionIntake,
                ExternalReferenceType::License,
                ExternalReferenceType::BuildMeta,
                ExternalReferenceType::BuildSystem,
                ExternalReferenceType::ReleaseNotes,
                ExternalReferenceType::SecurityContact,
                ExternalReferenceType::ModelCard,
                ExternalReferenceType::Log,
                ExternalReferenceType::Configuration,
                ExternalReferenceType::Evidence,
                ExternalReferenceType::Formulation,
                ExternalReferenceType::Attestation,
                ExternalReferenceType::ThreatModel,
                ExternalReferenceType::AdversaryModel,
                ExternalReferenceType::RiskAssessment,
                ExternalReferenceType::VulnerabilityAssertion,
                ExternalReferenceType::ExploitabilityStatement,
                ExternalReferenceType::PentestReport,
                ExternalReferenceType::StaticAnalysisReport,
                ExternalReferenceType::DynamicAnalysisReport,
                ExternalReferenceType::RuntimeAnalysisReport,
                ExternalReferenceType::ComponentAnalysisReport,
                ExternalReferenceType::MaturityReport,
                ExternalReferenceType::CertificationReport,
                ExternalReferenceType::CodifiedInfrastructure,
                ExternalReferenceType::QualityMetrics,
                ExternalReferenceType::POAM,
                ExternalReferenceType::ElectronicSignature,
                ExternalReferenceType::DigitalSignature,
                ExternalReferenceType::RFC9116,
                ExternalReferenceType::Citation,
                ExternalReferenceType::Patent,
                ExternalReferenceType::PatentAssertion,
                ExternalReferenceType::PatentFamily,
                ExternalReferenceType::Other,
            ],
            true,
            true,
            true,
            true,
            true,
            false,
            true,
            true,
            true,
            true,
            true,
            [
                Format::XML,
                Format::JSON,
                Format::ProtoBuff,
            ],
            true,
        );
    }
}
