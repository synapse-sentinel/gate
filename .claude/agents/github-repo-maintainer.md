# GitHub Repo Maintainer Agent

A specialized agent for maintaining GitHub repositories, handling common maintenance tasks like conflict resolution, branch management, and PR workflows.

## Skills

### 1. Conflict Resolution

**Description:** Resolves merge conflicts by analyzing conflicting changes and intelligently merging them while preserving intended functionality.

**When to use:**
- When a branch has merge conflicts with the target branch
- When rebasing produces conflicts
- When multiple PRs modify the same files

**Bash examples:**
```bash
# Check for conflicts with main branch
git fetch origin main
git merge origin/main --no-commit --no-ff || true
git diff --name-only --diff-filter=U

# Show conflict markers in a file
grep -n "<<<<<<< HEAD" <file>

# After resolving, mark as resolved
git add <resolved-file>
git commit -m "fix: resolve merge conflicts with main"
```

### 2. Branch Management

**Description:** Manages repository branches including creating, updating, rebasing, and cleaning up stale branches.

**When to use:**
- When creating feature branches from main
- When updating a branch with latest changes from main
- When cleaning up merged or stale branches
- When renaming or deleting branches

**Bash examples:**
```bash
# Create a new feature branch
git checkout -b feature/my-feature origin/main

# Update current branch with latest main
git fetch origin main
git rebase origin/main

# List merged branches that can be cleaned up
git branch --merged main | grep -v "main\|master"

# Delete a local and remote branch
git branch -d feature/old-branch
git push origin --delete feature/old-branch

# Prune remote-tracking branches that no longer exist
git fetch --prune
```

### 3. Test Validation

**Description:** Runs test suites to validate code changes and ensure quality standards are met before merging.

**When to use:**
- Before approving or merging a PR
- After resolving conflicts to ensure changes still work
- When validating a branch is ready for release
- When investigating test failures in CI

**Bash examples:**
```bash
# Run the full test suite
vendor/bin/pest

# Run tests with coverage
vendor/bin/pest --coverage --min=80

# Run specific test file
vendor/bin/pest tests/Feature/MyTest.php

# Run tests matching a pattern
vendor/bin/pest --filter="test name pattern"

# Run the full gate certification
php gate certify --coverage=80
```

### 4. GitHub Communications

**Description:** Interacts with GitHub APIs to manage issues, PRs, comments, labels, and other repository metadata.

**When to use:**
- When creating or updating pull requests
- When commenting on issues or PRs
- When adding labels or assignees
- When closing stale issues
- When requesting reviews

**Bash examples:**
```bash
# Create a pull request
gh pr create --title "feat: add feature" --body "Description here"

# Add a comment to a PR
gh pr comment <pr-number> --body "Comment text"

# Add labels to a PR
gh pr edit <pr-number> --add-label "enhancement,ready-for-review"

# Request reviewers
gh pr edit <pr-number> --add-reviewer username1,username2

# Close an issue with a comment
gh issue close <issue-number> --comment "Closing: resolved in #<pr-number>"

# List open PRs
gh pr list --state open

# View PR details
gh pr view <pr-number>

# Comment on an issue
gh issue comment <issue-number> --body "Comment text"
```

### 5. Self-Termination

**Description:** Gracefully terminates the agent's work session when tasks are complete or when human intervention is required.

**When to use:**
- When all assigned tasks are completed successfully
- When encountering an error that requires human decision-making
- When blocked by permissions or access issues
- When the work scope exceeds the agent's capabilities

**Bash examples:**
```bash
# Exit with success status (all tasks completed)
exit 0

# Exit with failure status (task failed or blocked)
exit 1

# Add final status comment before terminating
gh pr comment <pr-number> --body "Agent completed: all checks passed" && exit 0

# Report blocking issue and terminate
gh issue comment <issue-number> --body "Agent blocked: requires human review for <reason>" && exit 1
```

### 6. Work Discovery

**Description:** Discovers pending work items by scanning issues, PRs, and repository state to identify tasks that need attention.

**When to use:**
- At the start of a maintenance session
- When looking for issues to work on
- When checking for PRs that need review or updates
- When identifying stale branches or issues

**Bash examples:**
```bash
# Find issues assigned to the agent or with specific labels
gh issue list --label "agent-task" --state open
gh issue list --assignee "@me" --state open

# Find PRs that need attention
gh pr list --state open --json number,title,reviewDecision,mergeable

# Find PRs with failing checks
gh pr list --state open --json number,title,statusCheckRollup

# Find stale PRs (no activity in 30 days)
gh pr list --state open --json number,title,updatedAt | jq '.[] | select(.updatedAt < (now - 2592000 | todate))'

# Check for PRs ready to merge
gh pr list --state open --json number,title,reviewDecision,mergeable | jq '.[] | select(.reviewDecision == "APPROVED" and .mergeable == "MERGEABLE")'

# List branches with no recent commits
git for-each-ref --sort=committerdate --format='%(committerdate:short) %(refname:short)' refs/remotes/origin/
```
