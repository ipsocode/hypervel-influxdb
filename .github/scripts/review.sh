#!/usr/bin/env bash
# Imported from ipsocode/hypervel-packages github/scripts/review.sh. Edit it there.
#
# The automated review's code. .github/workflows/review.yml runs the review as one job, whose
# steps, in the four actions under .github/actions/review-*/, each run one command of this
# script. Why each command does what it does is written beside it below.
#
#   bash .github/scripts/review.sh <command> [options]
#   bash .github/scripts/review.sh --help
#
# Run it from the repository root. The commands, in the order the job runs them:
#
#   credential          whether review.yml was handed a Claude credential (HAS_CREDENTIAL);
#                       outputs present
#   focus               checks that the review's skill, brief and package.md are here, and
#                       reads .github/review/scan-skip; outputs skip
#   collect             fills DIR: pr.md, diff.patch, files.txt and comments.md; and the file
#                       index for the prompt
#   scan                review-scan.sh over DIR/diff.patch, into DIR/scan.md and the summary
#   gzip-only           a zstd that fails, for the framework's restore (see there)
#   framework --restored true|false
#                       strips other projects' agent files from the restored framework
#   since-memory        DIR/since-memory.md (and .patch): what changed since the notes
#   claude-version [--strict]
#                       the Claude Code version the review's action installs; outputs version
#                       and key. Not knowing is a notice, or with --strict (warm.yml) a warning
#   claude-setup --version V [--restored true|false] [--strict]
#                       links a restored Claude Code, or installs it; outputs path
#   install             the review's skill and brief, into ~/.claude/
#   brief               the prompt; outputs prompt
#   check-memory        whether the review left notes on this head; outputs save
#   summarize [FILE]    the review session's turns, cost, tools and refusals
#   collapse            minimizes the older summaries once the newest covers this head
#   components-ref      the hypervel/components commit Composer installed; outputs ref. It is
#                       for the PHP 8.4 job in tests.yml, and needs php, not jq
#
# Options, after the command, in any order:
#
#   --repo OWNER/NAME   the repository; default $GITHUB_REPOSITORY
#   --pr N              the pull request; default the one $GITHUB_EVENT_PATH is about
#   --head SHA          its head; default the event's
#   --dir DIR           the bundle the review reads; default .pr-review
#   --from DIR          collect and since-memory read saved API responses from DIR instead of
#                       asking GitHub: files.json, review-comments.json, issue-comments.json,
#                       pr.json (`gh pr view --json`), compare.json and compare.diff
#
# Outputs go to $GITHUB_OUTPUT, and the job summary's part to $GITHUB_STEP_SUMMARY; without
# them, both are printed. What is not the review's reading material (the file index, the
# prompt, the API's raw responses, scratch) goes to $RUNNER_TEMP, or to DIR/.work without it.
# So it runs locally too, with gh and jq; to see what a review of a pull request would be
# handed, from a package's root:
#
#   export GITHUB_REPOSITORY=ipsocode/hypervel-cin7
#   dir=$(mktemp -d)/pr-review
#   bash .github/scripts/review.sh collect --pr 3 --dir "${dir}"
#   bash .github/scripts/review.sh since-memory --head <sha> --dir "${dir}"
#   bash .github/scripts/review.sh brief --pr 3 --head <sha> --dir "${dir}"
#
# --dir keeps all of it out of the checkout: the default, .pr-review/, is the job's, and no
# package's .gitignore names it, so a `git add -A` there would stage the pull request's data.
#
# install and claude-setup write to the home directory, so they run in Actions only. The script
# keeps to what bash 3.2 and a Mac's BSD tools run as well as the runner's bash and GNU tools:
# github-tests/run.sh in ipsocode/hypervel-packages runs it on both.

set -euo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
nl=$'\n'

# ------------------------------------------------------------------------------------- helpers

usage() {
    awk 'NR > 3 { if (!/^#/) exit; sub(/^# ?/, ""); print }' "${BASH_SOURCE[0]}"
}

die() {
    echo "review.sh: $*" >&2
    exit 1
}

# An output of the step: NAME=VALUE into $GITHUB_OUTPUT, or printed without it. A value of more
# than one line goes between random delimiters, so that no line of it can end the output early.
set_output() {
    local name=$1 value=$2 delimiter lines
    case "${value}" in
        *"${nl}"*)
            delimiter="${name}-$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')"
            lines="${name}<<${delimiter}${nl}${value}${nl}${delimiter}"
            ;;
        *)
            lines="${name}=${value}"
            ;;
    esac
    if [ -n "${GITHUB_OUTPUT:-}" ]; then
        printf '%s\n' "${lines}" >> "${GITHUB_OUTPUT}"
    else
        printf '%s\n' "${lines}"
    fi
}

# Markdown for the job summary, from stdin: into $GITHUB_STEP_SUMMARY, or printed without it.
# With --tee, into the log as well.
summary() {
    if [ -z "${GITHUB_STEP_SUMMARY:-}" ]; then
        cat
    elif [ "${1:-}" = --tee ]; then
        tee -a "${GITHUB_STEP_SUMMARY}"
    else
        cat >> "${GITHUB_STEP_SUMMARY}"
    fi
}

# A file's number of lines, bare: a Mac's wc pads it.
lines_in() {
    wc -l < "$1" | tr -d ' '
}

# Whether $1 is a full commit SHA: 40 lowercase hex digits.
is_sha() {
    [ "${#1}" -eq 40 ] && [ -z "$(printf '%s' "$1" | LC_ALL=C tr -d '0-9a-f')" ]
}

# A field of the pull request event the job runs for, or nothing.
event() {
    if [ -n "${GITHUB_EVENT_PATH:-}" ] && [ -f "${GITHUB_EVENT_PATH}" ]; then
        jq -r "$1 // empty" "${GITHUB_EVENT_PATH}"
    fi
}

need_repo() {
    [ -n "${repo}" ] || die "which repository? Pass --repo OWNER/NAME, or set GITHUB_REPOSITORY."
}

need_pr() {
    [ -n "${pr}" ] || pr=$(event .pull_request.number)
    [ -n "${pr}" ] || die "which pull request? Pass --pr N, or set GITHUB_EVENT_PATH to a pull_request event."
}

need_head() {
    [ -n "${head_sha}" ] || head_sha=$(event .pull_request.head.sha)
    [ -n "${head_sha}" ] || die "which head? Pass --head SHA, or set GITHUB_EVENT_PATH to a pull_request event."
}

# install and claude-setup write to the home directory: on a runner, one the job discards when
# it ends; anywhere else, a person's own ~/.claude/ and Claude Code.
ci_only() {
    [ "${GITHUB_ACTIONS:-}" = true ] || die "$1 writes to ${HOME}, so it runs in GitHub Actions only."
}

# The files a set of notes names, sorted: a file counts as named when it is one of the notes'
# path-like words, less a trailing full stop. Any further arguments are more names, one per
# line. since-memory and check-memory both count this way, so the files one tells the review
# to read in full are the files the other warned about.
named_files() {
    local notes=$1
    shift
    {
        { LC_ALL=C grep -oE '[[:alnum:]_./@+-]+' "${notes}" || true; } | sed 's/\.*$//'
        [ $# -eq 0 ] || printf '%s\n' "$@"
    } | LC_ALL=C sort -u
}

# ---------------------------------------------------------------------------------- credential

# Without a CLAUDE_CODE_OAUTH_TOKEN or ANTHROPIC_API_KEY secret, the review and the collapse
# skip instead of failing, and focus, collect and scan run all the same. review.yml hands in
# whether one is set as HAS_CREDENTIAL: a step's `if` cannot read secrets.
cmd_credential() {
    set_output present "${HAS_CREDENTIAL:-}"
    if [ "${HAS_CREDENTIAL:-}" != true ]; then
        echo "::notice::No CLAUDE_CODE_OAUTH_TOKEN or ANTHROPIC_API_KEY secret; collecting and scanning only."
    fi
}

# --------------------------------------------------------------------------------------- focus

# The review's instructions, and the package's own part of them. The instructions are the
# pr-review skill, .github/claude/skills/pr-review/SKILL.md, imported like this file with the
# brief beside it, .github/claude/AGENTS.md; a review can follow the skill locally before a push
# too. The package's part is in .github/review/: package.md, what the package is and where in
# src/ to look, which goes into the prompt, and the optional scan-skip, which becomes
# REVIEW_SCAN_SKIP.
#
# The skill, the brief and package.md are required: without the skill the review has no
# instructions, and without the others it does not know what the package is. scan-skip is
# optional; its first line that is neither blank nor a # comment is an extended regular
# expression, and review-scan.sh leaves the files whose path matches it alone (vendored code,
# such as hypervel-scribe's src/Reflection/).
cmd_focus() {
    local shared skip=''
    for shared in .github/claude/skills/pr-review/SKILL.md .github/claude/AGENTS.md; do
        if [ ! -s "${shared}" ]; then
            echo "::error file=${shared}::Missing or empty: the review needs it, imported from ipsocode/hypervel-packages."
            exit 1
        fi
    done
    if [ ! -s .github/review/package.md ]; then
        echo "::error file=.github/review/package.md::Missing or empty: the review needs this package's paragraph and focus."
        exit 1
    fi
    if [ -f .github/review/scan-skip ]; then
        skip=$(grep -v -E '^[[:space:]]*(#|$)' .github/review/scan-skip | head -n 1 || true)
    fi
    set_output skip "${skip}"
    echo "Review focus: .github/review/package.md ($(lines_in .github/review/package.md) lines); scan skips: ${skip:-nothing}."
}

# ------------------------------------------------------------------------------------- collect

# Everything the review reads goes into DIR, so it starts there and not with a round of gh
# calls: the template as filled in, with the commits the history-less checkout cannot show, the
# whole diff (rebuilt from the files API, which also serves a change too big for `gh pr diff`),
# its files, and the earlier review comments.
cmd_collect() {
    local bot='github-actions[bot]' raw pids='' pid failed=0
    mkdir -p "${dir}" "${work}"

    if [ -n "${from}" ]; then
        raw=${from}
    else
        need_repo
        need_pr
        raw=${work}
        # The four reads are collect's network time, about 2 s together, so they run side by
        # side; any one failing fails the step.
        read_all "repos/${repo}/pulls/${pr}/files" "${raw}/files.json" &
        pids="${pids} $!"
        read_all "repos/${repo}/pulls/${pr}/comments" "${raw}/review-comments.json" &
        pids="${pids} $!"
        read_all "repos/${repo}/issues/${pr}/comments" "${raw}/issue-comments.json" &
        pids="${pids} $!"
        gh pr view "${pr}" --repo "${repo}" --json title,labels,author,headRefName,baseRefName,commits,body \
            > "${raw}/pr.json" &
        pids="${pids} $!"
        for pid in ${pids}; do
            wait "${pid}" || failed=1
        done
        [ "${failed}" = 0 ] || die "could not read pull request #${pr} of ${repo} from GitHub."
    fi

    # pr.md: the title, the labels, who opened it from which branch, its commits (the checkout
    # has no history to show them), and the template as filled in.
    jq -r '
        "# \(.title)\n\n"
        + "Labels: \([.labels[].name] | if length == 0 then "none" else join(", ") end)\n"
        + "Opened by \(.author.login), from \(.headRefName) into \(.baseRefName).\n\n"
        + "Commits, oldest first:\n\([.commits[] | "- \(.oid[0:7]) \(.messageHeadline)"] | join("\n"))\n\n"
        + .body
    ' "${raw}/pr.json" > "${dir}/pr.md"

    # diff.patch: each files-API entry as a unified diff. GitHub leaves the patch out for a
    # binary or very large file; the entry then says so, and the review reads the file.
    jq -r '.[]
        | .filename as $new
        | (.previous_filename // .filename) as $old
        | "diff --git a/\($old) b/\($new)\n"
          + (if .status == "added" then "--- /dev/null" else "--- a/\($old)" end) + "\n"
          + (if .status == "removed" then "+++ /dev/null" else "+++ b/\($new)" end) + "\n"
          + (if .patch != null then .patch + "\n"
             elif .status == "renamed" and .changes == 0 then "(renamed from \($old); the content is unchanged)\n"
             else "(GitHub sent no patch for \($new), \(.status) +\(.additions) -\(.deletions); read the file.)\n"
             end)
    ' "${raw}/files.json" > "${dir}/diff.patch"
    jq -r '.[] | select(.patch == null) | "::notice::GitHub sent no patch for \(.filename); the review reads the file."' "${raw}/files.json"

    # The change's files for the prompt: each one's status, its lines added and removed, and the
    # line of diff.patch where its diff starts. diff.patch has one `diff --git` line per
    # files-API entry, in the same order, and no hunk line starts that way.
    paste \
        <(jq -r '.[] | "\(.filename)\t\(.status)\t+\(.additions) -\(.deletions)"' "${raw}/files.json") \
        <(grep -n '^diff --git ' "${dir}/diff.patch" | cut -d : -f 1) \
        | awk -F '\t' '{ printf "- %s: %s, %s, diff.patch line %s\n", $1, $2, $3, $4 }' > "${work}/index.md"

    # files.txt: the change's paths, one per line, which the review's notes have to name.
    jq -r '.[].filename' "${raw}/files.json" | LC_ALL=C sort -u > "${dir}/files.txt"

    # comments.md: what earlier reviews said, inline and in their summaries.
    {
        jq -r --arg bot "${bot}" '.[] | select(.user.login == $bot)
            | "- \(.path):\(.line // .original_line // "?")\(if .line == null then " (outdated)" else "" end) — \(.body | gsub("\n"; "\n  "))"
        ' "${raw}/review-comments.json"
        jq -r --arg bot "${bot}" '.[] | select(.user.login == $bot)
            | "- summary, \(.created_at) — \(.body | gsub("\n"; "\n  "))"
        ' "${raw}/issue-comments.json"
    } > "${dir}/comments.md"
    [ -s "${dir}/comments.md" ] || echo "No earlier review comments." > "${dir}/comments.md"

    wc -c "${dir}"/*
}

# A list endpoint, every page of it, as one JSON array: --paginate prints one array per page,
# and jq -s joins them.
read_all() {
    gh api "$1" --paginate | jq -s 'add // []' > "$2"
}

# ---------------------------------------------------------------------------------------- scan

# review-scan.sh over the diff, into DIR/scan.md, which the review reads as leads, and into the
# job summary. It leaves alone the files REVIEW_SCAN_SKIP matches, which focus read from the
# package's scan-skip. Like focus and collect, it runs without a credential too.
cmd_scan() {
    bash "${here}/review-scan.sh" < "${dir}/diff.patch" > "${dir}/scan.md"
    {
        echo "## review-scan"
        echo
        cat "${dir}/scan.md"
    } | summary
}

# ----------------------------------------------------------------------------------- gzip-only

# The CI image has no zstd, so the PHP 8.4 container saves the framework's cache entry with
# gzip. actions/cache puts the compression method in an entry's identity, and uses zstd when
# `zstd --version` prints anything, as it does on the ubuntu runner: a restore there asks for a
# zstd entry and misses the gzip one. So this puts a zstd that prints nothing and fails in
# $RUNNER_TEMP/gzip-only, and review-setup runs the framework's restore, and only that step,
# with the directory first in PATH; the Claude Code and memory caches keep zstd. Delete this,
# and the PATH, once the CI image ships zstd.
cmd_gzip_only() {
    local bin="${work}/gzip-only"
    mkdir -p "${bin}"
    printf '#!/bin/sh\nexit 1\n' > "${bin}/zstd"
    chmod +x "${bin}/zstd"
    echo "A zstd that fails, for the framework's restore: ${bin}/zstd"
}

# ----------------------------------------------------------------------------------- framework

# The review looks up Hypervel's API in vendor/hypervel/components/src/*/src/ and its docs in
# src/docs/, packages.md first for package conventions. The review job has no vendor/: it runs
# on a clean runner that never executes package or dependency code, so the PHP 8.4 job caches
# the framework it installed, and review-setup restores it here. Nothing restored is executed;
# the review only reads it.
#
# What is stripped first: other projects' agent instructions (CLAUDE.md, CLAUDE.local.md,
# AGENTS.md, .claude/, .mcp.json), which Claude Code would load when the review reads files
# there. hypervel/components ships a CLAUDE.md and an AGENTS.md at its root. The review takes
# its instructions from its skill and brief only. And every symlink: the entry was saved from a
# container that ran the pull request's code and its dependencies', and a link there could
# lead the review's reads out of the tree, to this runner's files. The installed tree has none.
cmd_framework() {
    local tree=vendor/hypervel/components found path what files bytes
    if [ "${restored}" != true ] || [ ! -d "${tree}" ]; then
        echo "::notice::No hypervel/components restored from the PHP 8.4 job's cache, so the review has no framework source this time."
        return 0
    fi
    found=$(find "${tree}" \( -type l -o -name CLAUDE.md -o -name CLAUDE.local.md -o -name AGENTS.md \
        -o -name .claude -o -name .mcp.json \) -prune -print | LC_ALL=C sort)
    if [ -n "${found}" ]; then
        while IFS= read -r path; do
            what=''
            [ ! -L "${path}" ] || what=', a symlink'
            rm -rf -- "${path}"
            echo "Removed ${path}${what}"
        done <<< "${found}"
    fi
    # Counted here, since du's sizes differ between filesystems: the bytes of the files.
    files=$(find "${tree}" -type f | wc -l | tr -d ' ')
    bytes=$(find "${tree}" -type f -exec cat {} + | wc -c | tr -d ' ')
    echo "The review can read ${tree}: ${files} files, $(awk -v b="${bytes}" 'BEGIN { printf "%.1f", b / 1048576 }') MB."
}

# -------------------------------------------------------------------------------- since-memory

# The review's memory: the notes the last review of this pull request left, which review-memory
# restores into DIR/memory.md. Their first line names the head that review reviewed, then there
# is a line per file of the change with what it checked and concluded. The review reads again
# in full what changed since that head, what those changes reach, and any file the notes leave
# out, and trusts the notes for the rest, then writes new notes. Without notes, it reads
# everything. Before notes, every push re-read the whole change: on hypervel-auditing#9, reviews
# of a growing change ran from 17 to 31 turns, 56 to 145 s and $0.57 to $0.81, mostly
# re-checking files the push never touched. With them, a push there that changed one file was
# reviewed in 77 s for $0.41, where the review before it, with no notes yet, took 148 s and
# $0.96.
#
# DIR/since-memory.md is what the review has to read again: what changed between the head the
# notes reviewed and this one, with the diff of it, and any file of the change the notes leave
# out. A merge of main shows up here too, and the review keeps to this pull request's files. No
# notes, or notes whose head is gone (a force-push), mean everything.
cmd_since_memory() {
    local reviewed='' changed='' trusted=false left_out file
    need_head
    [ -n "${from}" ] || need_repo
    export LC_ALL=C
    if [ -f "${dir}/memory.md" ]; then
        reviewed=$(sed -n '1s/^Reviewed head: \([0-9a-f]\{40\}\)$/\1/p' "${dir}/memory.md")
    fi
    {
        if [ -z "${reviewed}" ]; then
            echo "No notes from an earlier review of this pull request: review the whole change."
        elif [ "${reviewed}" = "${head_sha}" ]; then
            trusted=true
            echo "The notes already reviewed ${head_sha}, so nothing changed since: re-check what they left open."
        elif changed=$(compare_files "${reviewed}"); then
            trusted=true
            echo "Changed since ${reviewed}, the head the notes reviewed:"
            while IFS= read -r file; do echo "- ${file}"; done <<< "${changed:-(nothing)}"
            # The changes themselves, which diff.patch buries in the whole pull request's diff:
            # without them, a review on hypervel-auditing#9 spent ten turns on git fetch and
            # gh api, which the history-less checkout and the allowed tools refused. GitHub
            # declines a diff it finds too large; the review then reads the files.
            if compare_diff "${reviewed}" > "${dir}/since-memory.patch"; then
                echo "since-memory.patch is the diff of these changes."
            else
                rm -f "${dir}/since-memory.patch"
            fi
        else
            echo "The notes reviewed ${reviewed}, which is gone from this branch's history (a force-push?): review the whole change."
        fi

        # The files changed since the notes are listed above already, so they count as named.
        if [ "${trusted}" = true ]; then
            left_out=$(named_files "${dir}/memory.md" "${changed}" | comm -23 "${dir}/files.txt" -)
            if [ -n "${left_out}" ]; then
                echo "Not in the notes, so read these in full as well:"
                while IFS= read -r file; do echo "- ${file}"; done <<< "${left_out}"
            fi
        fi
    } > "${dir}/since-memory.md"
    cat "${dir}/since-memory.md"
}

# The files changed between the reviewed head, $1, and this one; fails when GitHub cannot
# compare the two, which means the reviewed head is gone. From DIR/compare.json with --from.
compare_files() {
    if [ -n "${from}" ]; then
        [ -f "${from}/compare.json" ] && jq -r '.files[].filename' "${from}/compare.json"
    else
        gh api "repos/${repo}/compare/${1}...${head_sha}" --jq '.files[].filename' 2> /dev/null
    fi
}

# The diff of those changes; fails when GitHub declines it. From DIR/compare.diff with --from.
compare_diff() {
    if [ -n "${from}" ]; then
        [ -f "${from}/compare.diff" ] && cat "${from}/compare.diff"
    else
        gh api -H 'Accept: application/vnd.github.diff' "repos/${repo}/compare/${1}...${head_sha}" 2> /dev/null
    fi
}

# ------------------------------------------------------------------------------ claude-version

# Claude Code, installed once and restored after that: the action installed it on every run,
# which took 13 s of hypervel-auditing#9's review. It has to be the version the action pins,
# which only the action's source names: src/entrypoints/run.ts, at the SHA
# .github/actions/review-claude/action.yml pins the action to, which installs it with
# https://claude.ai/install.sh. When that cannot be read, nothing is restored and the action
# installs Claude Code itself. warm.yml fills main's cache with the same version, which every
# pull request reads; a miss in review-claude installs it with the action's own installer, and
# saves it for this pull request's next review. The cache key is made here only, and both take
# it from the output.
#
# Not knowing never fails a job. In the review it costs the 13 s install, so it is a notice;
# warm.yml, whose only work this is, passes --strict and makes it a warning.
cmd_claude_version() {
    local sha version=''
    sha=$(grep -o -m 1 -E 'anthropics/claude-code-action@[0-9a-f]{40}' \
        .github/actions/review-claude/action.yml 2> /dev/null | cut -d @ -f 2) || sha=''
    if [ -n "${sha}" ]; then
        version=$(gh api -H 'Accept: application/vnd.github.raw' \
            "repos/anthropics/claude-code-action/contents/src/entrypoints/run.ts?ref=${sha}" 2> /dev/null \
            | sed -n 's/^ *const claudeCodeVersion = "\([0-9][0-9.]*\)";$/\1/p' | head -n 1) || version=''
    fi
    if [ -z "${version}" ] && [ "${strict}" = true ]; then
        echo "::warning::Could not tell which Claude Code the review's action installs, so there is nothing to cache."
        return 0
    elif [ -z "${version}" ]; then
        echo "::notice::Could not tell which Claude Code the review's action installs, so the action installs it itself, and warm.yml has nothing to cache."
        return 0
    fi
    set_output version "${version}"
    # runner.os and runner.arch, as the key has always read: claude-code-<version>-Linux-X64 on
    # the runner. Anywhere else, what uname says.
    set_output key "claude-code-${version}-${RUNNER_OS:-$(uname -s)}-${RUNNER_ARCH:-$(uname -m)}"
    echo "The review's action installs Claude Code ${version}."
}

# -------------------------------------------------------------------------------- claude-setup

# Restored, Claude Code is the binary ~/.local/share/claude/versions/<version>, and the
# installer also links ~/.local/bin/claude to it, which a restore has to recreate. On a miss,
# the installer the action runs, for the same version. A failure leaves no path, and the action
# then installs Claude Code itself, with retries; so here it never fails the job. warm.yml, whose
# only work this is, passes --strict and fails.
cmd_claude_setup() {
    ci_only claude-setup
    [ -n "${version}" ] || die "claude-setup needs --version V."
    if set_up_claude; then
        set_output path "${HOME}/.local/bin/claude"
    elif [ "${strict}" = true ]; then
        echo "::error::Could not set up Claude Code ${version}."
        exit 1
    else
        echo "::notice::Could not set up Claude Code ${version}, so the action installs it itself."
    fi
}

# Called as a condition, where set -e does not reach, so each step returns its own failure.
set_up_claude() {
    if [ "${restored}" = true ]; then
        mkdir -p "${HOME}/.local/bin" \
            && ln -sf "${HOME}/.local/share/claude/versions/${version}" "${HOME}/.local/bin/claude" \
            || return 1
    else
        curl -fsSL https://claude.ai/install.sh | bash -s -- "${version}" || return 1
    fi
    "${HOME}/.local/bin/claude" --version
}

# ------------------------------------------------------------------------------------- install

# The skill and the brief, into ~/.claude/, where Claude Code finds the user's skills and
# CLAUDE.md, and where the runner discards them with everything else when the job ends. The
# package keeps no .claude/ of its own, and a release leaves .github/ out, so neither reaches an
# application. The action swaps the checkout's .claude/ for the base branch's (so that a pull
# request cannot plant hooks or settings there), but leaves ~/.claude/ alone. The two come from
# this checkout, like package.md, so a pull request that changes them is reviewed under its own.
# That opens nothing new: a pull request from this repository runs its own workflow files
# anyway, and forks and Dependabot get no review.
cmd_install() {
    ci_only install
    mkdir -p "${HOME}/.claude/skills"
    cp -R .github/claude/skills/. "${HOME}/.claude/skills/"
    cp .github/claude/AGENTS.md "${HOME}/.claude/CLAUDE.md"
    find "${HOME}/.claude" -type f | sed "s|^${HOME}|~|"
}

# --------------------------------------------------------------------------------------- brief

# The prompt. The skill holds the instructions; the prompt hands the review what it would
# otherwise spend its first turns collecting, about half of them on hypervel-auditing's
# reviews: the package's focus, the change's files and where each one's diff starts, what
# changed since the last review and its notes, and that gh is ready. Only what the workflow and
# the package's own files produce goes in. What the pull request's author wrote (the template,
# the commit messages, the diff, the comments) stays in DIR, to be read as the material under
# review.
cmd_brief() {
    need_repo
    need_pr
    need_head
    mkdir -p "${work}"
    {
        echo "REPO: ${repo}"
        echo "PR NUMBER: ${pr}"
        echo "HEAD: ${head_sha}"
        echo
        echo "Review pull request #${pr} to ${repo}, at head ${head_sha}, with the pr-review skill"
        echo "(.github/claude/skills/pr-review/SKILL.md), in CI. This commit passed the conventions"
        echo "check, code style, PHPStan and the suite under the 100% coverage gate on PHP 8.4;"
        echo "PHP 8.5 runs alongside this review."
        echo "$(gh --version | head -n 1), signed in with this job's token: gh pr comment, gh pr view"
        echo "and gh pr diff work as they are."
        echo
        echo "## The package: .github/review/package.md"
        echo
        cat .github/review/package.md
        echo
        echo "## The change's $(lines_in "${dir}/files.txt") files, and where each one's diff starts"
        echo
        cat "${work}/index.md"
        echo
        echo "## Since the last review: ${dir}/since-memory.md"
        echo
        cat "${dir}/since-memory.md"
        if [ -f "${dir}/memory.md" ]; then
            echo
            echo "## Your notes from the last review: ${dir}/memory.md"
            echo
            cat "${dir}/memory.md"
        fi
    } > "${work}/prompt.md"

    set_output prompt "$(cat "${work}/prompt.md")"
    echo "::group::The prompt, $(lines_in "${work}/prompt.md") lines"
    cat "${work}/prompt.md"
    echo "::endgroup::"
}

# -------------------------------------------------------------------------------- check-memory

# Only notes this review wrote are kept: their first line names this head. Notes it left as
# they were still name an older head, and the next review then reads more, never less. Notes
# that leave out a file of the change are kept too, with a warning naming the file, and the
# next review reads that file in full. The session can write into .pr-review/ only
# (review-claude's --allowedTools), review.yml puts .github/ back from the commit before this
# runs, and the job's token cannot push: what the session wrote stays in .pr-review/, on this
# runner, and only the notes are kept.
cmd_check_memory() {
    local files left_out
    need_head
    export LC_ALL=C
    if [ ! -f "${dir}/memory.md" ] || [ "$(head -n 1 "${dir}/memory.md")" != "Reviewed head: ${head_sha}" ]; then
        echo "::notice::The review left no notes on ${head_sha}; the next one starts from the older notes, if any."
        return 0
    fi
    set_output save true

    mkdir -p "${work}"
    named_files "${dir}/memory.md" | comm -23 "${dir}/files.txt" - > "${work}/left-out.txt"
    files=$(lines_in "${dir}/files.txt")
    left_out=$(lines_in "${work}/left-out.txt")
    echo "The review left notes on ${head_sha}: $(lines_in "${dir}/memory.md") lines, naming $((files - left_out)) of the change's ${files} files."
    if [ "${left_out}" -gt 0 ]; then
        echo "::warning::The review's notes leave out ${left_out} of the change's ${files} files, which the next review reads in full: $(paste -s -d ' ' "${work}/left-out.txt")"
    fi

    # What the next review will trust, in the open.
    {
        echo "## Review notes"
        echo
        echo '````text'
        cat "${dir}/memory.md"
        echo '````'
        if [ "${left_out}" -gt 0 ]; then
            echo
            echo "Not in the notes, so the next review reads them in full:"
            echo
            sed 's/^/- /' "${work}/left-out.txt"
        fi
    } | summary
}

# ----------------------------------------------------------------------------------- summarize

# What the session did, from the action's record of it, which the log leaves out: its turns and
# cost, the tools it used, and every call it was refused. A refusal cost a turn and got nothing:
# allow the call in --allowedTools (.github/actions/review-claude/action.yml), or steer the
# skill away from it. Tool names and the refused calls' input only, never what a tool returned.
# The action always writes the record to $RUNNER_TEMP/claude-execution-output.json, so it is
# there even when the review step failed; without it (the review never started), nothing.
#
# The turns, cost and refusals come from the session's result message, which a session that
# failed does not always reach: the action writes the record without one when the SDK throws,
# or when the session ends with "No result message received". Those are the failed reviews
# this is most needed for, so the tools it used are still listed then.
cmd_summarize() {
    local execution=${file:-${work}/claude-execution-output.json}
    [ -f "${execution}" ] || return 0
    {
        echo "## Review session"
        echo
        jq -r '
            ([.[] | select(.type == "result")] | last) as $result
            | [.[] | select(.type == "assistant") | .message.content[]? | select(.type == "tool_use")
                | if .name == "Bash" then "Bash(" + (.input.command // "" | split(" ")[0:3] | join(" ")) + ")"
                  elif .name == "Skill" then "Skill(" + (.input.skill // .input.command // "?") + ")"
                  else .name end] as $calls
            | "- Tools: \($calls | group_by(.) | map("\(.[0]) ×\(length)") | join(", ") | if . == "" then "none" else . end)" as $tools
            | if $result == null then
                "- No result: the session ended before it reported its turns, cost and refusals.",
                $tools
              else
                ($result.permission_denials // []) as $refused
                | "- \($result.num_turns // 0) turns, \(($result.duration_ms // 0) / 1000 | floor) s, $\(($result.total_cost_usd // 0) * 100 | round / 100)",
                  $tools,
                  "- Refused: \($refused | length)",
                  ($refused[] | "  - \(.tool_name): \(.tool_input | tostring | .[0:200])")
              end
        ' "${execution}"
    } | summary --tee
}

# ------------------------------------------------------------------------------------ collapse

# Once the newest summary covers this head (its first line names the head it reviewed), the
# older ones are minimized as outdated: one current summary per pull request, with the history
# kept. Only then: a review that was cut short or skipped leaves the previous summary as the
# latest word, visible. GraphQL names this token's bot `github-actions`, where the REST API says
# `github-actions[bot]`.
cmd_collapse() {
    local owner name newest id minimized
    need_repo
    need_pr
    need_head
    mkdir -p "${work}"
    owner=${repo%%/*}
    name=${repo#*/}

    # The single-quoted $names here and in the mutation below are GraphQL and jq variables.
    # shellcheck disable=SC2016
    gh api graphql --paginate -F owner="${owner}" -F name="${name}" -F number="${pr}" -f query='
        query($owner: String!, $name: String!, $number: Int!, $endCursor: String) {
          repository(owner: $owner, name: $name) {
            pullRequest(number: $number) {
              comments(first: 100, after: $endCursor) {
                pageInfo { hasNextPage endCursor }
                nodes { id isMinimized body author { login } }
              }
            }
          }
        }' --jq '.data.repository.pullRequest.comments.nodes[]
          | select(.author.login == "github-actions")
          | (.body | capture("<!-- claude-review head=(?<sha>[0-9a-f]{40}) -->")?) as $marker
          | select($marker != null)
          | [.id, (.isMinimized | tostring), $marker.sha] | @tsv' > "${work}/summaries.tsv"

    newest=$(tail -n 1 "${work}/summaries.tsv" | cut -f 3)
    if [ "${newest}" != "${head_sha}" ]; then
        echo "The newest summary does not cover ${head_sha}; leaving the older ones as they are."
        return 0
    fi

    # Every summary but the newest: sed '$d' drops the last line, where `head -n -1` is GNU's.
    # shellcheck disable=SC2016
    sed '$d' "${work}/summaries.tsv" | while IFS=$'\t' read -r id minimized _; do
        [ "${minimized}" = "false" ] || continue
        if gh api graphql -f id="${id}" -f query='
            mutation($id: ID!) {
              minimizeComment(input: { subjectId: $id, classifier: OUTDATED }) { minimizedComment { isMinimized } }
            }' > /dev/null; then
            echo "Minimized ${id}."
        else
            echo "::warning::Could not minimize the summary ${id}."
        fi
    done
}

# ------------------------------------------------------------------------------ components-ref

# The hypervel/components commit Composer installed, for the PHP 8.4 job to cache
# vendor/hypervel/components under, for the review (see framework). php, not jq: this runs in
# the CI image, which has no jq. Composer 2's installed.json keeps the packages under
# `packages`, Composer 1's was the bare list. A dist's reference is the commit, and a source's
# when there is no dist; anything that is not a commit (a path repository's, say) caches
# nothing.
cmd_components_ref() {
    local program ref
    IFS= read -r -d '' program <<'PHP' || true
$file = 'vendor/composer/installed.json';
$installed = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
$packages = is_array($installed) ? ($installed['packages'] ?? $installed) : [];
foreach ($packages as $package) {
    if (is_array($package) && ($package['name'] ?? null) === 'hypervel/components') {
        echo $package['dist']['reference'] ?? $package['source']['reference'] ?? '';
        break;
    }
}
PHP
    ref=$(php -r "${program}") || ref=''
    if ! is_sha "${ref}"; then
        echo "::notice::vendor/composer/installed.json names no hypervel/components commit${ref:+ (its reference is ${ref})}, so the review gets no framework source this time."
        return 0
    fi
    set_output ref "${ref}"
    echo "The tests installed hypervel/components ${ref}."
}

# ------------------------------------------------------------------------------------ dispatch

command=${1:-}
[ $# -eq 0 ] || shift
case "${command}" in
    -h|--help|help)
        usage
        exit 0
        ;;
    '')
        usage >&2
        exit 2
        ;;
esac

repo=${GITHUB_REPOSITORY:-}
pr=''
head_sha=''
dir=.pr-review
from=''
version=''
restored=false
strict=false
file=''
while [ $# -gt 0 ]; do
    case "$1" in
        --repo|--pr|--head|--dir|--from|--version|--restored)
            [ $# -ge 2 ] || die "$1 needs a value."
            case "$1" in
                --repo) repo=$2 ;;
                --pr) pr=$2 ;;
                --head) head_sha=$2 ;;
                --dir) dir=$2 ;;
                --from) from=$2 ;;
                --version) version=$2 ;;
                --restored) restored=$2 ;;
            esac
            shift
            ;;
        --strict)
            strict=true
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        -*)
            die "no option $1. See --help."
            ;;
        *)
            if [ "${command}" != summarize ] || [ -n "${file}" ]; then
                die "unexpected argument $1. See --help."
            fi
            file=$1
            ;;
    esac
    shift
done
work=${RUNNER_TEMP:-${dir}/.work}

case "${command}" in
    credential) cmd_credential ;;
    focus) cmd_focus ;;
    collect) cmd_collect ;;
    scan) cmd_scan ;;
    gzip-only) cmd_gzip_only ;;
    framework) cmd_framework ;;
    since-memory) cmd_since_memory ;;
    claude-version) cmd_claude_version ;;
    claude-setup) cmd_claude_setup ;;
    install) cmd_install ;;
    brief) cmd_brief ;;
    check-memory) cmd_check_memory ;;
    summarize) cmd_summarize ;;
    collapse) cmd_collapse ;;
    components-ref) cmd_components_ref ;;
    *)
        echo "review.sh: no command ${command}." >&2
        usage >&2
        exit 2
        ;;
esac
