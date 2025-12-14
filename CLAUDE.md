# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**synapse-sentinel/gate** is a GitHub Action that provides universal code quality checks across the Jordan ecosystem (jordanpartridge/*, the-shit/*, conduit-ui/*, partridgerocks/*, synapse-sentinel/*).

Built with Laravel Zero for clean CLI architecture.

## Development Commands

```bash
# Run the gate locally
php gate run --coverage=100

# Run tests
vendor/bin/pest

# Run tests with coverage
vendor/bin/pest --coverage --min=100

# Security audit
composer audit
```

## Architecture

### GitHub Action Flow
Consumer repos use: `uses: synapse-sentinel/gate@v1`
→ Composite action sets up PHP
→ Runs `php gate run`
→ Outputs verdict + annotations
→ Exit 0 (green) or 1 (red)

### Core Components

```
app/
├── Commands/RunCommand.php    # CLI entry point
├── Verdict.php                # Value object (approved/rejected/escalate)
├── Checks/                    # Individual quality checks
│   ├── CheckInterface.php
│   ├── TestRunner.php         # pest --coverage --min=X (tests + coverage in one run)
│   ├── SecurityScanner.php    # composer audit
│   └── PestSyntaxValidator.php # Validates describe/it blocks
└── Stages/
    └── TechnicalGate.php      # Orchestrates all checks
```

### Verdict States
- **APPROVED** → exit 0, green check
- **REJECTED** → exit 1, red X + annotations
- **ESCALATE** → exit 1, requires human review

### GitHub Actions Output
```php
// Annotations
echo "::error file=src/Foo.php,line=42::Message";

// Step summary
file_put_contents(getenv('GITHUB_STEP_SUMMARY'), $markdown);

// Outputs
echo "verdict=approved" >> getenv('GITHUB_OUTPUT');
```

## Testing Conventions

- All tests MUST use `describe()/it()` blocks (no `test()` functions)
- TDD: write failing tests first
- 100% coverage required (dogfooding our own gate)

## License

GPL-3.0
