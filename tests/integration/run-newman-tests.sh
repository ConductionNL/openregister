#!/bin/bash
##
# OpenRegister Newman Integration Test Runner
#
# This script runs the Postman collection using Newman CLI inside the Nextcloud container
# to ensure proper network access and environment setup.
#
# Usage:
#   bash run-newman-tests.sh
#
# Environment variables:
#   BASE_URL       - Base URL for the API (default: http://localhost)
#   ADMIN_USER     - Admin username (default: admin)
#   ADMIN_PASSWORD - Admin password (default: admin)
#   CONTAINER_NAME - Docker container name (default: master-nextcloud-1)
##

set -e

# Colors for output.
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration.
BASE_URL=${BASE_URL:-"http://localhost"}
ADMIN_USER=${ADMIN_USER:-"admin"}
ADMIN_PASSWORD=${ADMIN_PASSWORD:-"admin"}
CONTAINER_NAME=${CONTAINER_NAME:-"master-nextcloud-1"}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COLLECTION_FILE="$SCRIPT_DIR/openregister-crud.postman_collection.json"

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  OpenRegister Newman Integration Tests${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo -e "  Base URL: ${YELLOW}$BASE_URL${NC}"
echo -e "  User: ${YELLOW}$ADMIN_USER${NC}"
echo -e "  Collection: ${YELLOW}$(basename "$COLLECTION_FILE")${NC}"
echo -e "  Container: ${YELLOW}$CONTAINER_NAME${NC}"
echo ""

# Check if we're inside a container or on the host
if [ -f "/.dockerenv" ] || grep -q docker /proc/1/cgroup 2>/dev/null; then
    echo -e "${BLUE}ℹ${NC} Running inside container - executing Newman directly"
    echo ""
    
    # Check if newman is installed
    if ! command -v newman &> /dev/null; then
        echo -e "${RED}✗${NC} Newman is not installed in this container!"
        echo ""
        echo "To install Newman:"
        echo "  npm install -g newman"
        echo ""
        exit 1
    fi
    
    NEWMAN_VERSION=$(newman --version)
    echo -e "${GREEN}✓${NC} Newman ${NEWMAN_VERSION} found"
    echo ""
    
    # Run Newman directly
    CONTAINER_COLLECTION_PATH="/var/www/html/apps-extra/openregister/tests/integration/openregister-crud.postman_collection.json"
    
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${BLUE}  Running Newman Collection${NC}"
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""
    
    newman run "$CONTAINER_COLLECTION_PATH" \
        --env-var "base_url=$BASE_URL" \
        --env-var "admin_user=$ADMIN_USER" \
        --env-var "admin_password=$ADMIN_PASSWORD" \
        --reporters cli \
        --color on \
        --disable-unicode
    
    EXIT_CODE=$?
else
    echo -e "${BLUE}ℹ${NC} Running on host - executing Newman inside container"
    echo ""
    
    # Check if Docker is available
    if ! command -v docker &> /dev/null; then
        echo -e "${RED}✗${NC} Docker is not available!"
        exit 1
    fi
    
    # Check if container is running
    if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
        echo -e "${RED}✗${NC} Container '${CONTAINER_NAME}' is not running!"
        echo ""
        echo "Available containers:"
        docker ps --format "  - {{.Names}}"
        echo ""
        exit 1
    fi
    
    echo -e "${GREEN}✓${NC} Container '${CONTAINER_NAME}' is running"
    
    # Check if Newman is installed in the container
    if ! docker exec -u 33 "$CONTAINER_NAME" which newman &> /dev/null; then
        echo -e "${RED}✗${NC} Newman is not installed in container!"
        echo ""
        echo "To install Newman in the container:"
        echo "  docker exec -u root $CONTAINER_NAME npm install -g newman"
        echo ""
        exit 1
    fi
    
    NEWMAN_VERSION=$(docker exec -u 33 "$CONTAINER_NAME" newman --version)
    echo -e "${GREEN}✓${NC} Newman ${NEWMAN_VERSION} found in container"
    echo ""
    
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${BLUE}  Running Newman Collection in Container${NC}"
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""
    
    # Run Newman inside the container
    docker exec -u 33 "$CONTAINER_NAME" newman run \
        /var/www/html/apps-extra/openregister/tests/integration/openregister-crud.postman_collection.json \
        --env-var "base_url=$BASE_URL" \
        --env-var "admin_user=$ADMIN_USER" \
        --env-var "admin_password=$ADMIN_PASSWORD" \
        --reporters cli \
        --color on \
        --disable-unicode
    
    EXIT_CODE=$?
fi

echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  Test Summary${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

if [ $EXIT_CODE -eq 0 ]; then
    echo -e "${GREEN}✓ All tests passed!${NC}"
else
    echo -e "${RED}✗ Some tests failed (exit code: $EXIT_CODE)${NC}"
fi

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

exit $EXIT_CODE

