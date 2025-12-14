# Phase 1: Technical Gate Implementation Plan

## Goal
Uniform quality checks across all core repos. Opening a PR triggers automatic checks - green checkmarks or red X's with clear feedback.

## Target Repos
- jordanpartridge/*
- the-shit/*
- conduit-ui/*
- partridgerocks/*
- synapse-sentinel/*

---

## Batch 1: Laravel Zero Scaffolding

### 1.1 Create Laravel Zero project
```bash
composer create-project laravel-zero/laravel-zero gate --prefer-dist
cd gate
php application app:rename gate
```

### 1.2 Install dependencies
```bash
composer require symfony/process
php gate app:install config  # Enable config folder
```

### 1.3 Directory structure (Laravel Zero defaults + our additions)
```
app/
  Commands/
    RunCommand.php          # Main gate command
  Checks/
    CheckInterface.php
    TestRunner.php          # pest --coverage --min=X (tests + coverage in one)
    SecurityScanner.php     # composer audit
    PestSyntaxValidator.php # describe/it validation
  Stages/
    TechnicalGate.php
  Verdict.php
config/
  standards.php
tests/
  Pest.php
  Unit/
  Feature/
```

**Checkpoint:** `php gate` shows Laravel Zero welcome, `vendor/bin/pest` runs

---

## Batch 2: Verdict Value Object (TDD)

### 2.1 Write failing test for Verdict
```php
// tests/Unit/VerdictTest.php
describe('Verdict', function () {
    it('creates approved verdict with reason');
    it('creates rejected verdict with failures');
    it('creates escalate verdict for human review');
    it('returns exit code 0 for approved');
    it('returns exit code 1 for rejected');
    it('renders markdown summary');
    it('lists failures for annotations');
});
```

### 2.2 Implement Verdict.php to pass tests

**Checkpoint:** `vendor/bin/pest tests/Unit/VerdictTest.php` green

---

## Batch 3: Check Interface & Implementations (TDD)

### 3.1 Define CheckInterface
```php
interface CheckInterface {
    public function name(): string;
    public function run(): CheckResult;
}
```

### 3.2 Write failing tests for each check
- TestRunner: executes `pest --coverage --min=X`, captures pass/fail + coverage in one run
- SecurityScanner: executes `composer audit`, detects vulnerabilities
- PestSyntaxValidator: scans test files for describe/it blocks

Note: Single test run with coverage - no need for separate TestRunner and CoverageChecker

### 3.3 Implement each check using Symfony Process

**Checkpoint:** All unit tests green for Checks/

---

## Batch 4: TechnicalGate Stage (TDD)

### 4.1 Write failing feature test
```php
// tests/Feature/TechnicalGateTest.php
describe('TechnicalGate', function () {
    it('returns PASS when all checks pass');
    it('returns FAIL when tests fail');
    it('returns FAIL when coverage below threshold');
    it('returns FAIL when security vulnerabilities found');
    it('returns FAIL when Pest syntax invalid');
    it('aggregates multiple failures into single verdict');
});
```

### 4.2 Implement TechnicalGate.php
- Runs all checks in sequence
- Collects results
- Returns aggregate Verdict

**Checkpoint:** Feature tests green

---

## Batch 5: Gate Command (TDD)

### 5.1 Write test for RunCommand
```php
describe('RunCommand', function () {
    it('runs technical gate and outputs verdict');
    it('accepts --coverage option');
    it('writes GitHub step summary when GITHUB_STEP_SUMMARY set');
    it('outputs annotations for failures');
    it('exits 0 on approved');
    it('exits 1 on rejected');
});
```

### 5.2 Implement RunCommand.php
```php
class RunCommand extends Command
{
    protected $signature = 'run
        {--coverage=100 : Minimum coverage threshold}
        {--repo= : Repository name}
        {--pr= : Pull request number}';

    protected $description = 'Run quality gate checks';
}
```

- Inject TechnicalGate
- Output GitHub Actions format
- Write to GITHUB_STEP_SUMMARY
- Echo annotations for failures
- Return proper exit code

**Checkpoint:** `php gate run --coverage=80` runs locally

---

## Batch 6: GitHub Action Definition

### 6.1 Create action.yml
```yaml
name: 'Synapse Sentinel Gate'
description: 'Universal code quality gate for Jordan ecosystem'
branding:
  icon: 'shield'
  color: 'blue'

inputs:
  coverage-threshold:
    description: 'Minimum test coverage percentage'
    default: '100'
  php-version:
    description: 'PHP version'
    default: '8.3'

outputs:
  verdict:
    description: 'Gate verdict'
    value: ${{ steps.gate.outputs.verdict }}

runs:
  using: 'composite'
  steps:
    - uses: shivammathur/setup-php@v2
      with:
        php-version: ${{ inputs.php-version }}
        coverage: xdebug

    - name: Cache Gate dependencies
      uses: actions/cache@v4
      with:
        path: ${{ github.action_path }}/vendor
        key: gate-vendor-${{ hashFiles('**/composer.lock') }}

    - name: Install Gate
      shell: bash
      run: composer install --working-dir=${{ github.action_path }} --no-dev -q

    - name: Run Gate
      id: gate
      shell: bash
      run: |
        php ${{ github.action_path }}/gate run \
          --coverage=${{ inputs.coverage-threshold }}
```

### 6.2 Create README.md with usage
```yaml
- uses: synapse-sentinel/gate@v1
  with:
    coverage-threshold: 100
```

**Checkpoint:** Action YAML validates

---

## Batch 7: Self-Validation & Release

### 7.1 Run gate on itself
```bash
php bin/gate.php --coverage-threshold=100
```

### 7.2 Verify 100% coverage
```bash
vendor/bin/pest --coverage --min=100
```

### 7.3 Quality gates
```bash
composer audit
# Pest syntax check (all tests use describe/it)
```

### 7.4 Tag release
```bash
git tag v1.0.0
git push origin v1.0.0
```

**Checkpoint:** Gate passes its own gate

---

## Batch 8: Rollout to Repos

### 8.1 Create workflow file template
```yaml
# .github/workflows/gate.yml
name: Gate
on: [pull_request]
jobs:
  gate:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: synapse-sentinel/gate@v1
        with:
          coverage-threshold: 100
```

### 8.2 Open PRs to add gate to each repo
- jordanpartridge/bike-api
- jordanpartridge/jordan-partridge
- partridgerocks/github-client
- conduit-ui/gate
- (others as needed)

**Checkpoint:** PRs show new Gate check in CI

---

## Quality Gates (Run After Each Batch)

```bash
# Tests pass
vendor/bin/pest

# Coverage threshold
vendor/bin/pest --coverage --min=100

# Security
composer audit

# Syntax (manual for now, automated later)
grep -r "^test(" tests/ && echo "FAIL: Use describe/it" || echo "PASS"
```

---

## Not In Phase 1 (Future)

- Business Logic Gate (AI-powered review)
- Auto-merge on APPROVED
- Troubleshooter spawning on FAIL
- Pattern compliance checking
- Rector integration

---

## Execution Order

1. Scaffolding → checkpoint
2. Verdict tests → Verdict impl → checkpoint
3. Check tests → Check impls → checkpoint
4. TechnicalGate tests → TechnicalGate impl → checkpoint
5. Gate tests → Gate impl → CLI → checkpoint
6. action.yml → README → checkpoint
7. Self-validation → tag → checkpoint
8. Rollout PRs → done
