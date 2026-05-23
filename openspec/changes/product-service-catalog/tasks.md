# Tasks: product-service-catalog

## 1. Redirect stub

- [x] 1.1 Create `openspec/specs/product-service-catalog/spec.md` with YAML frontmatter `status: redirect`, H1 title "Product & Service Catalog (PDC)", Purpose section identifying this as a stub, and a Requirements section with one Requirement/Scenario pair directing implementers to the canonical Pipelinq spec.

## 2. Registry verification

- [x] 2.1 Verify that tooling (specs linter, hydra gates, openspec CLI, etc.) correctly identifies and skips or flags redirect-status specs without attempting to extract data model, requirements, or other normative content from them.

## 3. Documentation

- [x] 3.1 Ensure fleet documentation and OpenRegister/Pipelinq cross-app guides reference the canonical PDC spec location (`pipelinq/openspec/specs/product-service-catalog/spec.md`) rather than pointing to this local stub.
