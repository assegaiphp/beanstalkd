# GitHub Copilot Instructions

Use these repository instructions when helping with code, commits, or pull requests in this repository.

## Release and milestone discipline

- Follow the milestone-driven workflow described in the shared Assegai release playbook.
- Keep work aligned to one milestone at a time.
- Do not bundle unrelated feature work into one change.

## Commit guidance

When suggesting a commit message, prefer:

```text
type(scope): short summary
```

Examples:

- `fix(beanstalkd): release failed jobs without deleting them`
- `feature(beanstalkd): expose queue connection timeout options`
- `test(beanstalkd): cover retry behavior for failed jobs`

Preferred types:

- `fix`
- `feature`
- `docs`
- `test`
- `refactor`
- `chore`

Keep commits small and single-purpose.

## Pull request guidance

When suggesting or drafting a pull request, use these sections:

- `Milestone`
- `Type`
- `Why this belongs in this milestone`
- `What changed`
- `What did not change`
- `Verification`
- `User impact`
- `Release notes`
- `Upgrade notes`

## Verification guidance

The default check is usually:

- `composer test`

Do not claim work is green unless the relevant checks actually passed.

## 1.0.0 framing

Treat `1.0.0` as the minimum viable identity of AssegaiPHP, not the complete list of everything the framework could eventually become.
