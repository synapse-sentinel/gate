# Synapse Sentinel Gate

Universal code quality gate for the Jordan ecosystem. Enforces consistent standards across all repositories.

## Quick Start

Add to your repository's workflow:

```yaml
name: Gate
on: [pull_request]

jobs:
  gate:
    runs-on: ubuntu-latest
    permissions:
      contents: write      # Required for auto-merge
      checks: write        # Required for check status
      pull-requests: write # Required for PR comments
    steps:
      - uses: actions/checkout@v4
      - uses: synapse-sentinel/gate@v1
        with:
          coverage-threshold: 100
```

### Required Permissions

The Gate action requires specific workflow permissions to function properly:

- `contents: write` - Enables auto-merge on approved PRs
- `checks: write` - Allows creating check runs with status
- `pull-requests: write` - Enables posting coverage reports and verdict comments

Without these permissions, the action will run successfully but features will silently fail (e.g., no PR comments, no auto-merge).

## What It Checks

### Technical Gate (Phase 1)
- **Tests & Coverage**: Runs `pest --coverage --min=X`
- **Security Audit**: Runs `composer audit` for vulnerabilities
- **Pest Syntax**: Validates all tests use `describe()/it()` blocks

### Business Logic Gate (Phase 2 - Coming Soon)
- Issue intent matching
- Architectural compliance
- Over/under-engineering detection

## Inputs

| Input | Description | Default |
|-------|-------------|---------|
| `coverage-threshold` | Minimum test coverage % | `100` |
| `php-version` | PHP version to use | `8.3` |

## Outputs

| Output | Description |
|--------|-------------|
| `verdict` | `approved`, `rejected`, or `escalate` |
| `reason` | Human-readable explanation |

## Verdicts

- **Approved** → Green check, exit 0
- **Rejected** → Red X with annotations, exit 1
- **Escalate** → Requires human review, exit 1

## Local Usage

```bash
# Run gate on current directory
php gate run --coverage=100

# Run with lower threshold
php gate run --coverage=80
```

## Development

```bash
# Install dependencies
composer install

# Run tests
vendor/bin/pest

# Run with coverage
vendor/bin/pest --coverage --min=100
```

## License

GPL-3.0
