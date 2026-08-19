# CycloneDX PHP Library

[![shield_packagist-version]][link_packagist]
[![shield_rtfd]][link_rtfd]
[![shield_gh-workflow-test]][link_gh-workflow-test]
[![shield_coverage]][link_codacy]
[![shield_shepherd]][link_shepherd]
[![shield_ossf-best-practices]][link_ossf-best-practices]
[![shield_license]][license_file]  
[![shield_website]][link_website]
[![shield_slack]][link_slack]
[![shield_groups]][link_discussion]
[![shield_twitter-follow]][link_twitter]

----

Work with [CycloneDX] documents.  
OWASP CycloneDX is a full-stack Bill of Materials (BOM) standard
that provides advanced supply chain capabilities for cyber risk reduction.

> [!NOTE]  
> This package is a software library not intended for standalone use.  
> For generating Software Bill of Materials (SBOM), check out [CycloneDX PHP Composer Plugin](https://github.com/CycloneDX/cyclonedx-php-composer).

## Responsibilities

* Provide a general purpose _php_-implementation of [_CycloneDX_][CycloneDX].
* Provide [_phpDoc3_](https://phpdoc.org/)- & [_psalm_](https://psalm.dev/)-compatible annotations for said implementation,
  so developers and dev-tools can rely on it.
* Provide data models to work with _CycloneDX_.
* Provide a JSON- and an XML-normalizer, that...
  * supports all shipped data models.
  * respects any injected [_CycloneDX_ Specification][CycloneDX-spec] and generates valid output according to it.
  * can prepare data structures for JSON- and XML-serialization.
* Serialization:
  * Provide a JSON-serializer.
  * Provide an XML-serializer.
* Validation against _CycloneDX_ Specification:
  * Provide a JSON-validator.
  * Provide an XML-validator.
* Provide [_composer_-based autoloading](https://getcomposer.org/doc/01-basic-usage.md#autoloading) for downstream usage.

## Capabilities

* Enums for the following use cases:
  * `ComponentType`
  * `ExternalReferenceType`
  * `HashAlgorithm`
  * `LicenseAcknowledgement`
* Data models for the following use cases:
  * `Bom`
  * `BomRef`, `BomRefRepository`
  * `Component`, `ComponentRepository`, `ComponentEvidence`
  * `ExternalReference`, `ExternalReferenceRepository`
  * `HashDictionary`
  * `LicenseExpression`, `NamedLicense`, `SpdxLicense`, `LicenseRepository`
  * `Metadata`
  * `Property`, `PropertyRepository`
  * `Tool`, `ToolRepository`
* Utilities for the following use cases:
  * Generate valid random SerialNumbers for `Bom.serialNumber`
* Factories for the following use cases:
  * Create data models from any license descriptor string
* Implementation of the [_CycloneDX_ Specification][CycloneDX-spec] for the following versions:
  * `1.7`
  * `1.6`
  * `1.5`
  * `1.4`
  * `1.3`
  * `1.2`
  * `1.1`
* Normalizers that convert data models to JSON structures
* Normalizers that convert data models to  XML structures
* Serializer that converts `Bom` data models to JSON string
* Serializer that converts `Bom` data models to  XML string
* Validator that checks JSON against _CycloneDX_ Specification
* Validator that checks  XML against _CycloneDX_ Specification

## Installation

Install via composer:

```shell
composer require cyclonedx/cyclonedx-library
```

## Usage

See extended [examples].

```php
$bom = new \CycloneDX\Core\Models\Bom();
$bom->getComponents()->addItems(
    new \CycloneDX\Core\Models\Component(
        \CycloneDX\Core\Enums\ComponentType::Library,
        'myComponent'
    )
);
```

## API Documentation

We ship code annotations, so that your IDE and tools may pick up the documentation when you use this library downstream.

There are also pre-rendered documentations hosted on [readthedocs][link_rtfd].

Additionally, there is a prepared config for [_phpDoc3_](https://docs.phpdoc.org/guide/getting-started/index.html)
that you can use to generate the docs for yourself.

## Conflicts

Due to the fact that this library was split out of [`/src/Core` of cyclonedx-php-composer (346e6200fb2f5086061b15c2ee44f540893ce97d)](https://github.com/CycloneDX/cyclonedx-php-composer/tree/346e6200fb2f5086061b15c2ee44f540893ce97d/src/Core)
it will conflict with its original source: `cyclonedx/cyclonedx-php-composer:<3.5`.

## Contributing

Feel free to open issues, bug reports or pull requests.  
See the [CONTRIBUTING][contributing_file] file for details.

## License

Permission to modify and redistribute is granted under the terms of the Apache 2.0 license.  
See the [LICENSE][license_file] file for the full license.

[CycloneDX]: https://cyclonedx.org/
[CycloneDX-spec]: https://github.com/CycloneDX/specification/tree/master#readme

[license_file]: https://github.com/CycloneDX/cyclonedx-php-library/blob/master/LICENSE
[contributing_file]: https://github.com/CycloneDX/cyclonedx-php-library/blob/master/CONTRIBUTING.md
[examples]: https://github.com/CycloneDX/cyclonedx-php-library/tree/master/examples
[link_rtfd]: https://cyclonedx-php-library.readthedocs.io

[shield_packagist-version]: https://img.shields.io/packagist/v/cyclonedx/cyclonedx-library?logo=Packagist&logoColor=white "packagist"
[shield_rtfd]: https://img.shields.io/readthedocs/cyclonedx-php-library?logo=readthedocs&logoColor=white "Read the Docs"
[shield_gh-workflow-test]: https://img.shields.io/github/actions/workflow/status/CycloneDX/cyclonedx-php-library/php.yml?branch=master&logo=GitHub&logoColor=white "build"
[shield_coverage]: https://img.shields.io/codacy/coverage/7e5610bee31a4c99b1b8efb0eeab9e73?logo=Codacy&logoColor=white "test coverage"
[shield_shepherd]: https://shepherd.dev/github/CycloneDX/cyclonedx-php-library/coverage.svg "type coverage"
[shield_ossf-best-practices]: https://img.shields.io/cii/percentage/7955?label=OpenSSF%20best%20practices "OpenSSF best practices"
[shield_license]: https://img.shields.io/github/license/CycloneDX/cyclonedx-php-library?logo=open%20source%20initiative&logoColor=white "license"
[shield_website]: https://img.shields.io/badge/https://-cyclonedx.org-blue.svg "homepage"
[shield_slack]: https://img.shields.io/badge/slack-join-blue?logo=Slack&logoColor=white "slack join"
[shield_groups]: https://img.shields.io/badge/discussion-groups.io-blue.svg "groups discussion"
[shield_twitter-follow]: https://img.shields.io/badge/Twitter-follow-blue?logo=Twitter&logoColor=white "twitter follow"
[link_packagist]: https://packagist.org/packages/cyclonedx/cyclonedx-library
[link_gh-workflow-test]: https://github.com/CycloneDX/cyclonedx-php-library/actions/workflows/php.yml?query=branch%3Amaster
[link_codacy]: https://app.codacy.com/gh/CycloneDX/cyclonedx-php-library
[link_shepherd]: https://shepherd.dev/github/CycloneDX/cyclonedx-php-library
[link_ossf-best-practices]: https://www.bestpractices.dev/projects/7955
[link_website]: https://cyclonedx.org/
[link_slack]: https://cyclonedx.org/slack/invite
[link_discussion]: https://groups.io/g/CycloneDX
[link_twitter]: https://twitter.com/CycloneDX_Spec
