# Commit And PR Guidelines

Use this format for `assegaiphp/beanstalkd` commits and pull requests.

## Commits

Prefer small, single-purpose commits:

```text
type(scope): short summary
```

Examples:

```text
fix(beanstalkd): release failed jobs without deleting them
feature(beanstalkd): expose queue connection timeout options
test(beanstalkd): cover retry behavior for failed jobs
docs(beanstalkd): clarify queue name configuration
```

Recommended types:

- `fix`
- `feature`
- `docs`
- `test`
- `refactor`
- `chore`

If the summary needs "and" to describe unrelated work, split the commit.

## Pull Requests

Every PR should clearly answer:

1. which milestone it belongs to
2. why it belongs there
3. what changed
4. what did not change
5. how it was verified

Use the repository PR template and keep the body concise.

## Verification

The default check for this repo is:

- `composer test`

Add any extra manual verification that matters for the changed behavior.

## Release Notes

Use:

- `Release notes: Not needed` for internal refactors, tests, and narrow fixes
- `Release notes: Needed` for user-visible behavior changes
- `Upgrade notes: Needed` when users may need to change code, config, workflow, or expectations
