# Conduction

Welcome to Conduction's organization repository. This is the central hub for our org-level defaults, developer and Claude Code guides, global tooling configuration, and shared documentation.

> **Companion repo:** This `.github` repo holds the *documentation and shared tooling* — what you read and configure. The sister repo [`Conduction/hydra`](https://codeberg.org/Conduction/hydra) (private) holds the *agentic CI/CD pipeline* — what runs the builds, reviews, and skills. **Hydra is the factory, `.github` is the manual.** See [`docs/hydra/README.md`](./docs/hydra/README.md#hydra-repo-vs-github-repo) for the full split.

## What's in this repository

| Path                                                               | Purpose                                                                                                                                                        |
| ------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| [`docs/claude/`](./docs/claude/README.md)                          | Developer and Claude Code runtime guides — workflow, commands, spec/doc writing conventions, testing, setup, ADR authoring, skill authoring, retrofit playbook |
| [`global-settings/`](./global-settings/README.md)                  | Mandatory user-level Claude Code settings — permissions policy, write-approval hooks, version check                                                            |
| [`usage-tracker/`](./usage-tracker/README.md)                      | Real-time Claude token usage monitoring tool for VS Code / Cursor                                                                                              |
| [`APPLICATION-ROADMAP.md`](./APPLICATION-ROADMAP.md)               | Current Conduction app portfolio and proposed gap apps                                                                                                         |
| [`CONTRIBUTING.md`](./CONTRIBUTING.md)                             | How to contribute across Conduction projects                                                                                                                   |
| [`CODE_OF_CONDUCT.md`](./CODE_OF_CONDUCT.md)                       | Community code of conduct                                                                                                                                      |
| [`SECURITY.md`](./SECURITY.md)                                     | Security policy and vulnerability reporting                                                                                                                    |
| [`SUPPORT.md`](./SUPPORT.md)                                       | Where to ask for help                                                                                                                                          |
| `profile/`, `website/`, `HeaderContent.json`, `FooterContent.json` | Org-level GitHub profile and website assets                                                                                                                    |

Start with [`docs/claude/README.md`](./docs/claude/README.md) if you are setting up a new workstation or onboarding onto a Conduction project.

## Our Open Source Philosophy

At Conduction, we believe in the power of open collaboration. We see open source as more than just code sharing — it's a way to collaborate around shared values and principles. This is why we've made the deliberate choice to make our internal documentation publicly accessible.

By being transparent about our processes, guidelines, and organizational structure, we aim to:

- Foster collaboration and knowledge sharing
- Enable others to learn from our experiences
- Contribute to the broader open source community
- Demonstrate our commitment to transparency

## Contributing

We welcome contributions from both our team members and the broader community. See [`CONTRIBUTING.md`](./CONTRIBUTING.md) for the full guide, or:

- Open issues for questions or suggestions
- Submit pull requests for improvements
- Engage in discussions

## Licensing

Conduction's Nextcloud apps are released under EUPL-1.2. This meta-repo contains documentation and configuration only; individual Conduction repositories carry their own `LICENSE` file.
