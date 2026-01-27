# Testing

## Quick Start

```bash
# Install dependencies
composer install

# Run unit/feature tests (mocks, no Redis needed)
./vendor/bin/phpunit

# Run specific test suite
./vendor/bin/phpunit --testsuite=Feature
./vendor/bin/phpunit --testsuite=Unit
```

## Integration Tests (Real Redis)

Integration tests require a running Redis instance.

### Using Docker (Recommended)

```bash
# Start Redis on port 6380
docker compose -f docker-compose.test.yml up -d

# Run integration tests
REDIS_INTEGRATION=1 REDIS_PORT=6380 ./vendor/bin/phpunit --testsuite=Integration

# Stop Redis when done
docker compose -f docker-compose.test.yml down
```

### Using Local Redis

If you have Redis running locally on default port:

```bash
REDIS_INTEGRATION=1 REDIS_PORT=6379 ./vendor/bin/phpunit --testsuite=Integration
```

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `REDIS_INTEGRATION` | - | Set to `1` to enable integration tests |
| `REDIS_HOST` | `127.0.0.1` | Redis host |
| `REDIS_PORT` | `6380` | Redis port |
| `REDIS_DB` | `15` | Redis database (uses separate DB to avoid conflicts) |

## Running Specific Tests

```bash
# Run single test method
./vendor/bin/phpunit --filter=test_push_job_creates_partition

# Run single test class
./vendor/bin/phpunit tests/Feature/StrategyTest.php

# Run with verbose output
./vendor/bin/phpunit -v

# Run with coverage (requires xdebug or pcov)
./vendor/bin/phpunit --coverage-html=build/coverage
```

## CI/CD

GitHub Actions runs both unit and integration tests automatically on push/PR.
See `.github/workflows/tests.yml` for configuration.

## Test Structure

```
tests/
├── TestCase.php                    # Base test case with mocks
├── Feature/                        # Feature tests (with mocks)
│   ├── BalancedDispatchableTest.php
│   ├── LimiterTest.php
│   ├── MetricsTest.php
│   ├── PrometheusTest.php
│   ├── IpWhitelistTest.php
│   └── StrategyTest.php
└── Integration/                    # Integration tests (real Redis)
    ├── IntegrationTestCase.php
    ├── BalancedQueueIntegrationTest.php   # Core queue tests
    ├── CommandsIntegrationTest.php        # Artisan commands
    ├── PrometheusIntegrationTest.php      # Metrics endpoint
    ├── TestJob.php
    └── TestJobWithNumericPartition.php
```

## Test Coverage Summary

| Component | Unit/Feature | Integration |
|-----------|--------------|-------------|
| Strategies | ✅ | ✅ |
| BalancedDispatchable | ✅ | ✅ |
| Limiters | ✅ | ✅ |
| Metrics | ✅ | ✅ |
| BalancedRedisQueue | - | ✅ |
| Console commands | - | ✅ |
| PrometheusExporter | ✅ | ✅ |
