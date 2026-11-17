export const meta = {
  name: 'ship-change',
  description: 'Triage → (repro) → plan → implement → docs → verify/fix loop → review',
  whenToUse: 'A change in phem from a task description: a feature or a bug fix, with its docs, changelog and tests. It does not commit.',
  phases: [
    { title: 'Triage', detail: 'classify the task and find the parts of the repo it touches' },
    { title: 'Reproduce', detail: 'bug fix only: a failing test, no fix' },
    { title: 'Plan', detail: 'write .claude/plans/current.md' },
    { title: 'Implement', detail: 'the plan, then the docs, the changelog and the editor plugins' },
    { title: 'Verify', detail: 'run the tests, fix the failures, up to 4 rounds' },
    { title: 'Review', detail: 'correctness, conventions and test coverage of the diff' },
  ],
}

// Run it with `/ship-change <task>`. The agents never commit or push: the user reviews the diff.
const task = args?.task ?? args

if (!task) return { status: 'blocked', reason: 'no task: pass it as the input of the workflow' }

const NO_COMMIT = 'Do not commit and do not push.'

phase('Triage')
const triage = await agent(
  `Classify and assess this task for the phem repository: ${task}

Read the code that the task names. Report the kind of change and the parts of the repository that it touches:
- client: the Stimulus controllers and the client in assets/ (a real browser must check them with the e2e tests);
- compiler: compiler/ or editors/ (the editor plugins need a rebuild and a version check).`,
  {
    schema: {
      type: 'object',
      required: ['kind', 'touchesClient', 'touchesCompiler', 'summary'],
      properties: {
        kind: { enum: ['feature', 'bugfix'] },
        touchesClient: { type: 'boolean' },
        touchesCompiler: { type: 'boolean' },
        summary: { type: 'string' },
      },
    },
  },
)

log(`${triage.kind}: ${triage.summary}`)

if (triage.kind === 'bugfix') {
  phase('Reproduce')
  const repro = await agent(
    `Write a failing test that reproduces this bug: ${task}

Put it next to the tests of the same code: assets/tests/ (Vitest), e2e/tests/ (Playwright PHP), tests/ (PHPUnit) or the cargo tests of compiler/. Run it and confirm that it fails for the reason of the bug. Do NOT fix the bug. ${NO_COMMIT}`,
    {
      schema: {
        type: 'object',
        required: ['reproduced'],
        properties: { reproduced: { type: 'boolean' }, testPath: { type: 'string' }, output: { type: 'string' } },
      },
    },
  )

  if (!repro.reproduced) return { status: 'blocked', reason: 'could not reproduce', triage }
}

phase('Plan')
await agent(
  `Write an implementation plan for: ${task}

Triage: ${triage.summary}
Save it to .claude/plans/current.md. Name the files and the functions to change, the tests to add, and the docs in docs/ that describe the behavior. Show the .phem code and the rendered HTML of a usage example. ${NO_COMMIT}`,
)

phase('Implement')
await agent(`Implement the plan in .claude/plans/current.md. Add the tests that the plan names. ${NO_COMMIT}`)

await agent(
  `Update the docs and the changelog for the change in the git diff (git diff HEAD), as CLAUDE.md says:
- change the doc in docs/ that describes the behavior, or add a new doc and put it in website/src/navigation.ts;
- add an entry at the top of the "## Unreleased" section of CHANGELOG.md;
- run make docs-build.
${NO_COMMIT}`,
)

if (triage.touchesCompiler) {
  await agent(
    `The change touches compiler/ or editors/. Follow the "Editor plugins" section of CLAUDE.md: only prepare the release. Check the version of the VS Code extension and of the PhpStorm plugin against the published versions. Increase a version only when the local version is the published version, and add the change to the changelog of each plugin. Never install a local build of a plugin (no make vscode-install, no make phpstorm-install, no code --install-extension): the user uses only the Marketplace versions. Do not build or publish a plugin. ${NO_COMMIT}`,
  )
}

phase('Verify')
const CHECK_SCHEMA = {
  type: 'object',
  required: ['passed', 'failures', 'summary'],
  properties: { passed: { type: 'boolean' }, failures: { type: 'number' }, summary: { type: 'string' } },
}
const e2e = triage.touchesClient ? ' The change touches the client: make test runs the e2e tests too, and a failed e2e test saves a screenshot and the HTML in e2e/var/failures/.' : ''

let prev = Infinity
let check = null

for (let round = 1; round <= 4; round++) {
  check = await agent(
    `Run make test from the root of the repository (Vitest, the e2e tests, cargo test and PHPUnit).${e2e} Count the failed tests, and give each failure with its test name and the important lines of its output. Report only: do not fix.`,
    { label: `check ${round}`, schema: CHECK_SCHEMA },
  )

  if (check.passed) break

  if (check.failures >= prev) return { status: 'stuck', round, summary: check.summary, triage }

  prev = check.failures
  log(`Round ${round}: ${check.failures} failures`)
  await agent(`Fix these test failures. Fix the code, not the test, unless the test is wrong for the new behavior:\n${check.summary}\n${NO_COMMIT}`, { label: `fix ${round}` })
}

if (!check?.passed) return { status: 'stuck', summary: check?.summary, triage }

phase('Review')
const FOCUS = [
  'correctness: bugs, wrong conditions, missed edge cases, broken callers of the changed functions',
  'the conventions of CLAUDE.md: SOLID, the code style (the blank lines), the docs in docs/ and the CHANGELOG.md entry',
  'test coverage: behavior of the change that no Vitest, e2e, PHPUnit or cargo test checks',
]
const reviews = await pipeline(FOCUS, (focus) =>
  agent(`Review the current git diff (git diff HEAD) for ${focus}. List concrete issues only, each with the file, the line and a failure scenario. Do not change files.`, { label: focus.split(':')[0] }),
)

return { status: 'done', triage, reviews: reviews.filter(Boolean) }
