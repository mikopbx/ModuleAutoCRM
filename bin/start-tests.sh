#!/bin/bash
#
# ModuleAutoCRM Test Runner
# Single entry point for all module tests
#
# Usage:
#   ./start-tests.sh           # Run all tests
#   ./start-tests.sh models    # Run only models tests
#   ./start-tests.sh logger    # Run only logger permissions tests
#   ./start-tests.sh all       # Run all tests (same as no argument)
#

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE_DIR="$(dirname "$SCRIPT_DIR")"
TESTS_DIR="$MODULE_DIR/tests"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Ensure Globals.php symlink exists in tests directory
if [ ! -f "$TESTS_DIR/Globals.php" ]; then
    echo -e "${YELLOW}Creating Globals.php symlink...${NC}"
    ln -sf /usr/www/src/Core/Config/Globals.php "$TESTS_DIR/Globals.php"
fi

# Test counters
TOTAL_PASSED=0
TOTAL_FAILED=0
TOTAL_TESTS=0

run_test() {
    local test_name="$1"
    local test_file="$2"

    echo -e "\n${BLUE}========================================${NC}"
    echo -e "${BLUE}Running: $test_name${NC}"
    echo -e "${BLUE}========================================${NC}\n"

    if [ ! -f "$test_file" ]; then
        echo -e "${RED}Test file not found: $test_file${NC}"
        return 1
    fi

    cd "$TESTS_DIR"
    php "$test_file"
    local exit_code=$?

    if [ $exit_code -eq 0 ]; then
        echo -e "\n${GREEN}$test_name: PASSED${NC}"
    else
        echo -e "\n${RED}$test_name: FAILED${NC}"
    fi

    return $exit_code
}

run_models_tests() {
    run_test "Models Tests" "$TESTS_DIR/test-models.php"
    return $?
}

run_logger_tests() {
    run_test "Logger Permissions Tests" "$TESTS_DIR/test-logger-permissions.php"
    return $?
}

run_all_tests() {
    local failed=0

    echo -e "${BLUE}Starting ModuleAutoCRM Test Suite${NC}"
    echo -e "${BLUE}Module: $MODULE_DIR${NC}"
    echo ""

    # Run models tests
    run_models_tests
    if [ $? -ne 0 ]; then
        ((failed++))
    fi

    # Run logger permissions tests
    run_logger_tests
    if [ $? -ne 0 ]; then
        ((failed++))
    fi

    # Summary
    echo -e "\n${BLUE}========================================${NC}"
    echo -e "${BLUE}Test Suite Summary${NC}"
    echo -e "${BLUE}========================================${NC}"

    if [ $failed -eq 0 ]; then
        echo -e "${GREEN}All test suites passed!${NC}"
        return 0
    else
        echo -e "${RED}$failed test suite(s) failed${NC}"
        return 1
    fi
}

# Parse command line arguments
case "${1:-all}" in
    models)
        run_models_tests
        exit $?
        ;;
    logger|permissions)
        run_logger_tests
        exit $?
        ;;
    all|"")
        run_all_tests
        exit $?
        ;;
    help|-h|--help)
        echo "ModuleAutoCRM Test Runner"
        echo ""
        echo "Usage: $0 [test_suite]"
        echo ""
        echo "Available test suites:"
        echo "  all         Run all tests (default)"
        echo "  models      Run models tests"
        echo "  logger      Run logger permissions tests"
        echo "  help        Show this help message"
        exit 0
        ;;
    *)
        echo -e "${RED}Unknown test suite: $1${NC}"
        echo "Use '$0 help' for available options"
        exit 1
        ;;
esac
