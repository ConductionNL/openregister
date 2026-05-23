# Base: official PHP image pinned to PHP 8.3 (matches Nextcloud dev environment)
# Using the debian bookworm variant for consistency with the host environment
FROM php:8.3-cli-bookworm

# System libraries required by PHP extensions
RUN apt-get update && apt-get install -y libxml2-dev libonig-dev libicu-dev && rm -rf /var/lib/apt/lists/*

# PHP extensions required by phpcs (Nextcloud coding standards), phpstan, phpmd, and psalm
RUN docker-php-ext-install xml dom mbstring intl

# Install Node.js LTS (for Claude CLI and frontend quality checks)
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer (PHP dependency manager for quality checks)
RUN curl -fsSL https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin --filename=composer

# Install Claude CLI — pinned to host version 2.1.83
# Authentication: mount ~/.claude/.credentials.json into /home/claude/.claude/.credentials.json:ro
# The credentials file is created by `claude auth login` on the host and contains OAuth tokens.
# The skill mounts it read-only into the container at runtime (see docker run command in SKILL.md).
RUN npm install -g @anthropic-ai/claude-code@2.1.83

# Install openspec CLI — pinned to host version 1.2.0
RUN npm install -g @fission-ai/openspec@1.2.0

# Create a non-root user — claude --dangerously-skip-permissions refuses to run as root
# Pre-create /home/claude/.claude/ so that volume-mounting the credentials file into it
# does not cause Docker to create the directory as root (which blocks CLI writes to session-env/)
RUN useradd -m -u 1000 -s /bin/bash claude && \
    mkdir -p /workspace /home/claude/.claude && \
    chown claude:claude /workspace /home/claude/.claude

# Intentionally NOT installed: git, gh CLI, Docker client, SSH client
USER claude
WORKDIR /workspace
