# Synapse Sentinel Gate

Universal code quality gate with AI-powered validation. Enforces consistent standards across all repositories using local Ollama models.

## Quick Start

Install gate hooks in your repository:

```bash
# Install gate globally
composer global require synapse-sentinel/gate

# Install hooks in your repository
cd /path/to/your/repo
gate install
```

Or use in GitHub Actions:

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
      - uses: synapse-sentinel/gate@v2
        with:
          coverage-threshold: 80
```

### Required Permissions

The Gate action requires specific workflow permissions to function properly:

- `contents: write` - Enables auto-merge on approved PRs
- `checks: write` - Allows creating check runs with status
- `pull-requests: write` - Enables posting coverage reports and verdict comments

Without these permissions, the action will run successfully but features will silently fail (e.g., no PR comments, no auto-merge).

## What It Checks

### Phase 1: Pre-Commit Validation (Local, <10s)
- **Attribution Check**: Removes Claude Code attribution from commits
- **Logic & Atomicity**: AI validation that commits are atomic and coherent (Ollama)
- **Syntax Check**: Fast syntax validation

### Phase 2: CI/CD Validation (GitHub Actions, 2-5min)
- **Tests & Coverage**: Runs `pest --coverage --min=X`
- **Security Audit**: Runs `composer audit` for vulnerabilities
- **Pest Syntax**: Validates all tests use `describe()/it()` blocks
- **PR Cohesion**: Cross-file analysis for missing files and MVC coherence (Ollama)

### Phase 3: AI Code Review (GitHub Actions, 30s with caching)
- **Pattern Analysis**: Detects Laravel anti-patterns (N+1 queries, fat controllers)
- **Security Analysis**: Identifies SQL injection, XSS, mass assignment issues
- **Test Suggestions**: Generates specific test recommendations

### Phase 4: Semantic Release (On merge to main)
- **Auto-versioning**: Based on conventional commits (feat, fix, BREAKING)
- **Changelog Generation**: Automatic CHANGELOG.md updates
- **GitHub Releases**: Automated release creation with notes

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
# Install gate globally
composer global require synapse-sentinel/gate

# Install hooks in your repository
gate install

# Run full certification
gate certify --coverage=80

# Run individual checks
gate check:attribution          # Check for Claude Code attribution
gate check:attribution --fix    # Remove attribution automatically
gate check:logic                # Validate commit atomicity (Ollama)
gate check:cohesion             # Analyze PR cohesion (Ollama)

# Compact output mode
gate certify --compact
```

## AI Models

Gate uses [Ollama](https://ollama.com) for local AI validation:

- **llama3.2:3b** - Fast atomicity and logic checks (3-8 seconds)
- **qwen2.5-coder:7b** - Deep code review in CI (with caching)

Models are automatically downloaded when first needed. Ollama is optional - gate works without it but skips AI checks.

## Configuration

After running `gate install`, edit `.gate/config.php`:

```php
return [
    'pre_commit' => [
        'attribution' => true,  // Remove Claude attribution
        'logic' => true,        // Ollama atomicity check
        'syntax' => true,       // Fast syntax validation
    ],
    'ci_checks' => [
        'tests' => true,
        'security' => true,
        'cohesion' => true,     // PR cross-file analysis
    ],
    'ollama' => [
        'model' => 'llama3.2:3b',
        'timeout' => 30,
    ],
];
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
