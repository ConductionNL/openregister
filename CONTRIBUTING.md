# Contributing to Open Register

First off, thank you for considering contributing to Open Register! It's people like you that make Open Register such a great tool.

## Code of Conduct

This project and everyone participating in it is governed by our Code of Conduct. By participating, you are expected to uphold this code.

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check the issue list as you might find out that you don't need to create one. When you are creating a bug report, please include as many details as possible:

* Use a clear and descriptive title
* Describe the exact steps which reproduce the problem
* Provide specific examples to demonstrate the steps
* Describe the behavior you observed after following the steps
* Explain which behavior you expected to see instead and why
* Include screenshots if possible

### Suggesting Enhancements

Enhancement suggestions are tracked as GitHub issues. When creating an enhancement suggestion, please include:

* Use a clear and descriptive title
* Provide a step-by-step description of the suggested enhancement
* Provide specific examples to demonstrate the steps
* Describe the current behavior and explain which behavior you expected to see instead
* Explain why this enhancement would be useful

### Pull Requests

* Fork the repo and create your branch from `development`
* If you've added code that should be tested, add tests
* If you've changed APIs, update the documentation
* Always update other documentation to describe your feature
* Ensure the test suite passes
* Create a pull request!

## Development Process

1. Create a feature request issue describing your proposed changes
2. Fork the repository
3. Create a new branch: `git checkout -b feature/[issue-number]/[feature-name]`
   - Example: `git checkout -b feature/123/add-search-filter`
4. Make your changes
5. Run tests: `composer test`
6. Push to your fork: `git push origin feature/[issue-number]/[feature-name]`
7. Open a Pull Request referencing the feature request issue
   - Example title: "Feature #123: Add search filter functionality"
   - Include "Closes #123" in PR description

### Git Commit Messages

We use the [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) specification for commit messages. Follow the specification when creating commit messages is important as our CI will fail if the commit message does not follow the specification. We also use [changelog-ci](https://github.com/marketplace/actions/changelog-ci) to    automatically generate a changelog.

* Use the present tense ("Add feature" not "Added feature")
* Use the imperative mood ("Move cursor to..." not "Moves cursor to...")
* Limit the first line to 72 characters or less
* Reference issues and pull requests liberally after the first line

### Documentation

* Update the 'website/docs' folder of changes to the interface or business logic
* Use docblocks in the code for good and readable code documentation

### Testing

* Write test cases for your code
* Run the full test suite before submitting
* Document any new test cases

## Development Setup

1. Install PHP 8.1 or higher
2. Install Composer
3. Clone the repository
4. Run `composer install`
5. Configure your Nextcloud development environment

### Documentation Development

1. Navigate to the website directory
2. Install dependencies: `npm install`
3. Start development server: `npm start`
4. Make your changes
5. Build documentation: `npm run build`

## Community

* Join the commonground [Slacl](https://discord.gg/your-invite-link) community
* Follow us on [X](https://x.com/conduction_nl)
* Read our [Blog](https://www.linkedin.com/company/conduction/) on linkedin

## License

By contributing, you agree that your contributions will be licensed under the EUPL-1.2 License.
