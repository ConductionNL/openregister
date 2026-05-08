#!/bin/bash
#
# test-database-compatibility.sh
# 
# Test OpenRegister with both PostgreSQL and MariaDB to ensure compatibility
#
# Usage:
#   ./docker/test-database-compatibility.sh [--skip-postgres] [--skip-mariadb]
#

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
SKIP_POSTGRES=false
SKIP_MARIADB=false
POSTGRES_WAIT=45
MARIADB_WAIT=45

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --skip-postgres)
            SKIP_POSTGRES=true
            shift
            ;;
        --skip-mariadb)
            SKIP_MARIADB=true
            shift
            ;;
        --help)
            echo "Usage: $0 [--skip-postgres] [--skip-mariadb]"
            echo ""
            echo "Test OpenRegister with both PostgreSQL and MariaDB"
            echo ""
            echo "Options:"
            echo "  --skip-postgres   Skip PostgreSQL tests"
            echo "  --skip-mariadb    Skip MariaDB tests"
            echo "  --help            Show this help message"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

# Function to print colored messages
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

# Function to wait for service
wait_for_service() {
    local service=$1
    local max_attempts=$2
    local attempt=0

    log_info "Waiting for $service to be ready..."
    
    while [ $attempt -lt $max_attempts ]; do
        if docker-compose ps | grep -q "$service.*healthy"; then
            log_success "$service is ready!"
            return 0
        fi
        attempt=$((attempt + 1))
        echo -n "."
        sleep 1
    done
    
    log_error "$service did not become ready in time"
    return 1
}

# Function to cleanup
cleanup() {
    local profile=$1
    
    log_info "Cleaning up $profile environment..."
    
    if [ "$profile" = "mariadb" ]; then
        docker-compose --profile mariadb down -v
    else
        docker-compose down -v
    fi
    
    log_success "Cleanup complete"
}

# Function to run Newman tests
run_newman_tests() {
    local db_type=$1
    
    log_info "Running Newman integration tests with $db_type..."
    
    # Check if Newman is installed in container
    if ! docker exec -u 33 nextcloud which newman &>/dev/null; then
        log_warning "Newman not found in container, installing..."
        docker exec -u root nextcloud bash -c "apt-get update && apt-get install -y nodejs npm"
        docker exec -u root nextcloud npm install -g newman
    fi
    
    # Run Newman tests
    if docker exec -u 33 nextcloud newman run \
        /var/www/html/custom_apps/openregister/tests/integration/openregister-crud.postman_collection.json \
        --env-var "base_url=http://localhost" \
        --env-var "admin_user=admin" \
        --env-var "admin_password=admin" \
        --reporters cli 2>&1 | tee "/tmp/newman-$db_type.log"; then
        
        # Extract test results
        local assertions_executed=$(grep "assertions" "/tmp/newman-$db_type.log" | grep "executed" | awk '{print $4}')
        local assertions_failed=$(grep "assertions" "/tmp/newman-$db_type.log" | grep "failed" | awk '{print $6}')
        
        if [ -n "$assertions_executed" ] && [ -n "$assertions_failed" ]; then
            local assertions_passed=$((assertions_executed - assertions_failed))
            local pass_rate=$((assertions_passed * 100 / assertions_executed))
            
            log_info "Test results for $db_type:"
            echo "  - Assertions executed: $assertions_executed"
            echo "  - Assertions passed: $assertions_passed"
            echo "  - Assertions failed: $assertions_failed"
            echo "  - Pass rate: ${pass_rate}%"
            
            if [ "$assertions_failed" -eq 0 ]; then
                log_success "All tests passed with $db_type!"
                return 0
            else
                log_warning "$assertions_failed tests failed with $db_type"
                return 1
            fi
        else
            log_warning "Could not extract test results"
            return 1
        fi
    else
        log_error "Newman tests failed with $db_type"
        return 1
    fi
}

# Main execution
main() {
    local postgres_result=0
    local mariadb_result=0
    
    echo ""
    log_info "=========================================="
    log_info "OpenRegister Database Compatibility Tests"
    log_info "=========================================="
    echo ""
    
    # Test PostgreSQL
    if [ "$SKIP_POSTGRES" = false ]; then
        echo ""
        log_info "==================== PostgreSQL Tests ===================="
        echo ""
        
        # Cleanup any existing containers
        cleanup "postgres"
        
        # Start PostgreSQL stack
        log_info "Starting PostgreSQL stack..."
        docker-compose up -d
        
        # Wait for services
        sleep 10
        wait_for_service "openregister-postgres" 60 || { log_error "PostgreSQL failed to start"; postgres_result=1; }
        
        if [ $postgres_result -eq 0 ]; then
            log_info "Waiting for Nextcloud initialization..."
            sleep $POSTGRES_WAIT
            
            # Check if OpenRegister is enabled
            log_info "Enabling OpenRegister app..."
            docker exec -u 33 nextcloud php occ app:enable openregister
            
            # Run tests
            run_newman_tests "postgresql" || postgres_result=1
        fi
        
        # Cleanup
        cleanup "postgres"
    else
        log_info "Skipping PostgreSQL tests"
    fi
    
    # Test MariaDB
    if [ "$SKIP_MARIADB" = false ]; then
        echo ""
        log_info "==================== MariaDB Tests ===================="
        echo ""
        
        # Cleanup any existing containers
        cleanup "mariadb"
        
        # Start MariaDB stack
        log_info "Starting MariaDB stack..."
        docker-compose --profile mariadb up -d
        
        # Wait for services
        sleep 10
        wait_for_service "openregister-mariadb" 60 || { log_error "MariaDB failed to start"; mariadb_result=1; }
        
        if [ $mariadb_result -eq 0 ]; then
            log_info "Waiting for Nextcloud initialization..."
            sleep $MARIADB_WAIT
            
            # Check if OpenRegister is enabled
            log_info "Enabling OpenRegister app..."
            docker exec -u 33 nextcloud php occ app:enable openregister
            
            # Run tests
            run_newman_tests "mariadb" || mariadb_result=1
        fi
        
        # Cleanup
        cleanup "mariadb"
    else
        log_info "Skipping MariaDB tests"
    fi
    
    # Final summary
    echo ""
    log_info "=========================================="
    log_info "Test Summary"
    log_info "=========================================="
    echo ""
    
    if [ "$SKIP_POSTGRES" = false ]; then
        if [ $postgres_result -eq 0 ]; then
            log_success "PostgreSQL: PASSED ✅"
        else
            log_error "PostgreSQL: FAILED ❌"
        fi
    fi
    
    if [ "$SKIP_MARIADB" = false ]; then
        if [ $mariadb_result -eq 0 ]; then
            log_success "MariaDB: PASSED ✅"
        else
            log_error "MariaDB: FAILED ❌"
        fi
    fi
    
    echo ""
    
    # Exit with error if any tests failed
    if [ $postgres_result -ne 0 ] || [ $mariadb_result -ne 0 ]; then
        log_error "Some tests failed. Please review the logs."
        exit 1
    else
        log_success "All tests passed! 🎉"
        exit 0
    fi
}

# Run main function
main


