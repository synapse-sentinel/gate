# Changelog

All notable changes to Gate will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-01-05

### Added

- **Attribution Check** - Automatically detects and removes Claude Code attribution from commits
  - New `AttributionCheck` class for `gate certify`
  - New `gate check:attribution` command with `--fix` option
  - Enforces clean commit messages without AI co-authorship

- **Logic & Atomicity Validation** - AI-powered commit analysis using Ollama
  - New `LogicCheck` class validates commits are atomic (single purpose)
  - Ensures all changes in a commit are related
  - Detects logic issues and incomplete implementations
  - Uses `llama3.2:3b` model for fast local analysis (3-8 seconds)
  - New `gate check:logic` command

- **PR Cohesion Analysis** - Cross-file relationship validation
  - New `CohesionCheck` class analyzes PRs holistically
  - Detects missing files (tests, migrations, etc.)
  - Validates MVC architecture coherence
  - Checks cross-file dependencies make sense
  - New `gate check:cohesion` command

- **Git Hooks Installation** - Easy hook setup for any repository
  - New `gate install` command
  - Automatically installs pre-commit hooks
  - Creates `.gate/config.php` for customization
  - Hooks call `gate certify` before each commit

- **AI-Powered GitHub Actions Workflows**
  - Layer 3: AI Code Review with Ollama (qwen2.5-coder:7b)
    - Pattern analysis (N+1 queries, fat controllers, anti-patterns)
    - Security analysis (SQL injection, XSS, CSRF, mass assignment)
    - Test suggestions with specific scenarios
    - Model caching reduces CI time from 5min to 30sec
  - Layer 4: Semantic Release Automation
    - Auto-versioning based on conventional commits
    - Automatic CHANGELOG.md generation
    - GitHub release creation with release notes
    - Runs on merge to main/master

### Changed

- **CertifyCommand** - Now includes all new checks in order:
  1. Attribution Check
  2. Logic & Atomicity (Ollama)
  3. Tests & Coverage
  4. Security Audit
  5. Pest Syntax
  6. PR Cohesion (Ollama)

- **Check Architecture** - Expanded CheckInterface pattern to support AI validation
  - All checks now follow consistent CheckResult pattern
  - Better error reporting with detailed failure messages
  - Compact mode shows single-line status for all checks

- **README** - Comprehensive rewrite documenting all new features
  - Phase-based validation architecture
  - AI model information and configuration
  - Updated quick start guide
  - New command examples

### Technical Details

- Uses [Ollama](https://ollama.com) for local AI models (free, no API costs)
- Models auto-download on first use
- Graceful degradation when Ollama not available (skips AI checks)
- Compatible with existing v1.x workflows
- All new checks implement `CheckInterface`
- Uses `SymfonyProcessRunner` for command execution

### Migration Guide

#### From v1.x to v2.0.0

**No breaking changes** - v2.0.0 is backwards compatible with v1.x

Optional: Install new git hooks for pre-commit validation:

```bash
cd your-repo
gate install
```

Optional: Add Ollama for AI validation:

```bash
# Install Ollama
curl -fsSL https://ollama.com/install.sh | sh

# Models will auto-download on first use
# Or manually pull:
ollama pull llama3.2:3b
ollama pull qwen2.5-coder:7b
```

Update GitHub Actions (optional):

```yaml
# Change from v1 to v2
- uses: synapse-sentinel/gate@v2
```

Add AI review workflow (optional):

```bash
# Copy from prototypes/gate-v1/
cp prototypes/gate-v1/layer-3-ai-review.yml .github/workflows/gate-ai-review.yml
cp prototypes/gate-v1/layer-4-release.yml .github/workflows/gate-release.yml
```

## [1.4.1] - 2024-12-XX

### Previous Release
- Tests & Coverage validation
- Security Audit
- Pest Syntax checking
- GitHub Checks API integration

[2.0.0]: https://github.com/synapse-sentinel/gate/compare/v1.4.1...v2.0.0
[1.4.1]: https://github.com/synapse-sentinel/gate/releases/tag/v1.4.1
