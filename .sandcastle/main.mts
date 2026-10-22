// Parallel Planner with Review — four-phase orchestration loop
//
// This template drives a multi-phase workflow:
//   Phase 1 (Plan):             An opus agent analyzes open issues, builds a
//                               dependency graph, and outputs a <plan> JSON
//                               listing unblocked issues with branch names.
//   Phase 2 (Execute + Review): For each issue, a sandbox is created via
//                               createSandbox(). The implementer runs first
//                               (100 iterations). If it produces commits, a
//                               reviewer runs in the same sandbox on the same
//                               branch (1 iteration). All issue pipelines run
//                               concurrently via Promise.allSettled().
//   Phase 3 (Pull requests):    The host pushes each completed branch and
//                               opens a pull request against the current
//                               branch. Nothing is merged: a human reviews
//                               and merges every pull request.
//
// The outer loop repeats up to MAX_ITERATIONS times. An issue with an open
// pull request is in review and is not worked again, so the loop ends once
// every unblocked issue is waiting on a human.
//
// Usage:
//   npx tsx .sandcastle/main.mts
// Or add to package.json:
//   "scripts": { "sandcastle": "npx tsx .sandcastle/main.mts" }

import { execFileSync } from "node:child_process";
import * as sandcastle from "@ai-hero/sandcastle";
import { docker } from "@ai-hero/sandcastle/sandboxes/docker";
import { z } from "zod";

// The planner emits its plan as JSON inside <plan> tags; Output.object extracts
// and validates it against this schema. We use Zod here, but any Standard
// Schema validator works just as well — Valibot, ArkType, etc. See
// https://standardschema.dev.
const planSchema = z.object({
  issues: z.array(
    z.object({ id: z.string(), title: z.string(), branch: z.string() }),
  ),
});

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

// Maximum number of plan→execute→pull request cycles before stopping.
// Raise this if your backlog is large; lower it for a quick smoke-test run.
const MAX_ITERATIONS = 10;

// Hooks run inside the sandbox before the agent starts each iteration.
// composer install ensures the sandbox always has fresh dependencies.
// Node is only used on the host to run Sandcastle itself.
const hooks = {
  sandbox: {
    onSandboxReady: [{ command: "composer install --no-interaction" }],
  },
};

// Copy vendor from the host into the worktree before each sandbox starts.
// Avoids a full composer install from scratch; the hook above picks up any
// packages added since the last copy.
const copyToWorktree = ["vendor"];

const MERGING_IS_OFF =
  "⚠ Merging is OFF: Sandcastle only opens pull requests. Review and merge them yourself.";

// Runs a command on the host, with the host's git and gh credentials.
const host = (command: string, args: string[]): string =>
  execFileSync(command, args, { encoding: "utf8" }).trim();

// The branch Sandcastle was started from. Every pull request targets it.
const baseBranch = host("git", ["rev-parse", "--abbrev-ref", "HEAD"]);

// Head branches of the open pull requests: their issues are in review.
const branchesInReview = (): Set<string> =>
  new Set(
    JSON.parse(
      host("gh", [
        "pr",
        "list",
        "--state",
        "open",
        "--json",
        "headRefName",
        "--jq",
        "[.[].headRefName]",
      ]),
    ),
  );

// Pushes the issue's branch and opens a pull request for it, or reuses the
// one already open (the push has updated it). Returns the pull request's URL.
// Never merges.
function openPullRequest(issue: { id: string; title: string; branch: string }) {
  host("git", ["push", "--set-upstream", "origin", issue.branch]);

  const existing = host("gh", [
    "pr",
    "list",
    "--head",
    issue.branch,
    "--state",
    "open",
    "--json",
    "url",
    "--jq",
    ".[0].url // empty",
  ]);

  if (existing !== "") {
    return existing;
  }

  const commits = host("git", [
    "log",
    "--reverse",
    "--format=- %s",
    `${baseBranch}..${issue.branch}`,
  ]);

  return host("gh", [
    "pr",
    "create",
    "--base",
    baseBranch,
    "--head",
    issue.branch,
    "--title",
    issue.title,
    "--body",
    `Closes #${issue.id}\n\n${commits}\n\nOpened by Sandcastle, which never merges. Review and merge by hand.\n\n🤖 Generated with [Claude Code](https://claude.com/claude-code)`,
  ]);
}

console.log(`\n${MERGING_IS_OFF}\n`);

// ---------------------------------------------------------------------------
// Main loop
// ---------------------------------------------------------------------------

for (let iteration = 1; iteration <= MAX_ITERATIONS; iteration++) {
  console.log(`\n=== Iteration ${iteration}/${MAX_ITERATIONS} ===\n`);

  // -------------------------------------------------------------------------
  // Phase 1: Plan
  //
  // The planning agent (opus, for deeper reasoning) reads the open issue list,
  // builds a dependency graph, and selects the issues that can be worked in
  // parallel right now (i.e., no blocking dependencies on other open issues).
  //
  // It outputs a <plan> JSON block — Output.object parses and validates it.
  // -------------------------------------------------------------------------
  const plan = await sandcastle.run({
    hooks,
    sandbox: docker(),
    name: "planner",
    // One iteration is enough: the planner just needs to read and reason,
    // not write code. (Structured output requires maxIterations: 1.)
    maxIterations: 1,
    // Opus for planning: dependency analysis benefits from deeper reasoning.
    agent: sandcastle.claudeCode("claude-opus-5-5"),
    promptFile: "./.sandcastle/plan-prompt.md",
    // Extract and validate the <plan> JSON into a typed object. Throws
    // StructuredOutputError if the tag is missing, the JSON is malformed, or
    // validation fails — which aborts the loop.
    output: sandcastle.Output.object({ tag: "plan", schema: planSchema }),
  });

  // The planner is told to skip issues in review; this guards it.
  const inReview = branchesInReview();
  const issues = plan.output.issues.filter(
    (issue) => !inReview.has(issue.branch),
  );

  if (issues.length === 0) {
    // No unblocked work — either everything is done or everything is blocked.
    console.log("No unblocked issues to work on. Exiting.");
    break;
  }

  console.log(
    `Planning complete. ${issues.length} issue(s) to work in parallel:`,
  );
  for (const issue of issues) {
    console.log(`  ${issue.id}: ${issue.title} → ${issue.branch}`);
  }

  // -------------------------------------------------------------------------
  // Phase 2: Execute + Review
  //
  // For each issue, create a sandbox via createSandbox() so the implementer
  // and reviewer share the same sandbox instance per branch. The implementer
  // runs first; if it produces commits, the reviewer runs in the same sandbox.
  //
  // Promise.allSettled means one failing pipeline doesn't cancel the others.
  // -------------------------------------------------------------------------

  const settled = await Promise.allSettled(
    issues.map(async (issue) => {
      const sandbox = await sandcastle.createSandbox({
        branch: issue.branch,
        sandbox: docker(),
        hooks,
        copyToWorktree,
      });

      try {
        // Run the implementer
        const implement = await sandbox.run({
          name: "implementer",
          maxIterations: 100,
          agent: sandcastle.claudeCode("claude-opus-5-5"),
          promptFile: "./.sandcastle/implement-prompt.md",
          promptArgs: {
            TASK_ID: issue.id,
            ISSUE_TITLE: issue.title,
            BRANCH: issue.branch,
          },
        });

        // Only review if the implementer produced commits
        if (implement.commits.length > 0) {
          const review = await sandbox.run({
            name: "reviewer",
            maxIterations: 1,
            agent: sandcastle.claudeCode("claude-opus-5-5"),
            promptFile: "./.sandcastle/review-prompt.md",
            promptArgs: {
              BRANCH: issue.branch,
            },
          });

          // Combine commits from both runs so the pull request phase sees all of them.
          // Each sandbox.run() only returns commits from its own run.
          return {
            ...review,
            commits: [...implement.commits, ...review.commits],
          };
        }

        return implement;
      } finally {
        await sandbox.close();
      }
    }),
  );

  // Log any agents that threw (network error, sandbox crash, etc.).
  for (const [i, outcome] of settled.entries()) {
    if (outcome.status === "rejected") {
      console.error(
        `  ✗ ${issues[i]!.id} (${issues[i]!.branch}) failed: ${outcome.reason}`,
      );
    }
  }

  // Only open pull requests for branches that actually produced commits.
  // An agent that ran successfully but made no commits has nothing to review.
  const completedIssues = settled
    .map((outcome, i) => ({ outcome, issue: issues[i]! }))
    .filter(
      (entry) =>
        entry.outcome.status === "fulfilled" &&
        entry.outcome.value.commits.length > 0,
    )
    .map((entry) => entry.issue);

  const completedBranches = completedIssues.map((i) => i.branch);

  console.log(
    `\nExecution complete. ${completedBranches.length} branch(es) with commits:`,
  );
  for (const branch of completedBranches) {
    console.log(`  ${branch}`);
  }

  if (completedBranches.length === 0) {
    // All agents ran but none made commits — nothing to review this cycle.
    console.log("No commits produced. No pull requests to open.");
    continue;
  }

  // -------------------------------------------------------------------------
  // Phase 3: Pull requests
  //
  // The host, not an agent, pushes each completed branch and opens a pull
  // request against the branch Sandcastle was started from. Nothing is merged
  // and no issue is closed here: each pull request says "Closes #<id>", so the
  // issue closes when a human merges it.
  // -------------------------------------------------------------------------
  console.log("\nOpening pull requests (not merging):");
  for (const issue of completedIssues) {
    try {
      console.log(`  ${issue.id}: ${openPullRequest(issue)}`);
    } catch (error) {
      console.error(`  ✗ ${issue.id} (${issue.branch}): no pull request: ${error}`);
    }
  }

  console.log(`\n${MERGING_IS_OFF}`);
}

console.log(`\nAll done. ${MERGING_IS_OFF}`);
