#!/usr/bin/env bash
# Imported from ipsocode/hypervel-packages github/scripts/review.sh. Edit it there.

set -euo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
nl=$'\n'

usage() {
    cat <<'USAGE'
The automated review's code. .github/workflows/review.yml runs the review as one job, whose
steps, in the four actions under .github/actions/review-*/, each run one command of this
script.

  bash .github/scripts/review.sh <command> [options]
  bash .github/scripts/review.sh --help

Run it from the repository root. The commands, in the order the job runs them:

  credential          whether review.yml was handed a Claude credential (HAS_CREDENTIAL);
                      outputs present
  focus               checks that the review's skill, brief and package.md are here, and
                      reads .github/review/scan-skip; outputs skip
  collect             fills DIR: pr.md, diff.patch, files.txt and comments.md; and the file
                      index for the prompt
  scan                review-scan.sh over DIR/diff.patch, into DIR/scan.md and the summary
  scope               whether Claude reviews the pull request: not when every file in
                      DIR/files.txt is imported from ipsocode/hypervel-packages; outputs review
  gzip-only          a zstd that fails, for the framework's restore (see there)
  framework --restored true|false
                      strips other projects' agent files from the restored framework
  since-memory        DIR/since-memory.md (and .patch): what changed since the notes
  claude-version [--strict]
                      the Claude Code version the review's action installs; outputs version
                      and key. Not knowing is a notice, or with --strict (warm.yml) a warning
  claude-setup --version V [--restored true|false] [--strict]
                      links a restored Claude Code, or installs it; outputs path
  install             the review's skill and brief, into ~/.claude/
  brief               the prompt; outputs prompt
  check-memory        whether the review left notes on this head; outputs save
  summarize [FILE]    the review session's turns, cost, tools and refusals, and whether it
                      finished (REVIEW_OUTCOME)
  collapse            minimizes the older summaries once the newest covers this head
  components-ref      the hypervel/components commit Composer installed; outputs ref. It is
                      for the PHP 8.4 job in tests.yml, and needs php, not jq

Options, after the command, in any order:

  --repo OWNER/NAME   the repository; default $GITHUB_REPOSITORY
  --pr N              the pull request; default the one $GITHUB_EVENT_PATH is about
  --head SHA          its head; default the event's
  --dir DIR           the bundle the review reads; default .pr-review
  --from DIR          collect and since-memory read saved API responses from DIR instead of
                      asking GitHub: files.json, review-comments.json, issue-comments.json,
                      pr.json (`gh pr view --json`), compare.json and compare.diff

Outputs go to $GITHUB_OUTPUT, and the job summary's part to $GITHUB_STEP_SUMMARY; without
them, both are printed. What is not the review's reading material (the file index, the
prompt, the API's raw responses, scratch) goes to $RUNNER_TEMP, or to DIR/.work without it.
So it runs locally too, with gh and jq; to see what a review of a pull request would be
handed, from a package's root:

  export GITHUB_REPOSITORY=ipsocode/hypervel-cin7
  dir=$(mktemp -d)/pr-review
  bash .github/scripts/review.sh collect --pr 3 --dir "${dir}"
  bash .github/scripts/review.sh since-memory --head <sha> --dir "${dir}"
  bash .github/scripts/review.sh brief --pr 3 --head <sha> --dir "${dir}"

--dir keeps all of it out of the checkout: the default, .pr-review/, is the job's, and no
package's .gitignore names it, so a `git add -A` there would stage the pull request's data.

install and claude-setup write to the home directory, so they run in Actions only. The script
keeps to what bash 3.2 and a Mac's BSD tools run as well as the runner's bash and GNU tools:
github-tests/run.sh in ipsocode/hypervel-packages runs it on both.
USAGE
}

die() {
    echo "review.sh: $*" >&2
    exit 1
}

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

summary() {
    if [ -z "${GITHUB_STEP_SUMMARY:-}" ]; then
        cat
    elif [ "${1:-}" = --tee ]; then
        tee -a "${GITHUB_STEP_SUMMARY}"
    else
        cat >> "${GITHUB_STEP_SUMMARY}"
    fi
}

lines_in() {
    wc -l < "$1" | tr -d ' '
}

is_sha() {
    [ "${#1}" -eq 40 ] && [ -z "$(printf '%s' "$1" | LC_ALL=C tr -d '0-9a-f')" ]
}

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

ci_only() {
    [ "${GITHUB_ACTIONS:-}" = true ] || die "$1 writes to ${HOME}, so it runs in GitHub Actions only."
}

named_files() {
    local notes=$1
    shift
    {
        { LC_ALL=C grep -oE '[[:alnum:]_./@+-]+' "${notes}" || true; } | sed 's/\.*$//'
        [ $# -eq 0 ] || printf '%s\n' "$@"
    } | LC_ALL=C sort -u
}

cmd_credential() {
    set_output present "${HAS_CREDENTIAL:-}"
    if [ "${HAS_CREDENTIAL:-}" != true ]; then
        echo "::notice::No CLAUDE_CODE_OAUTH_TOKEN or ANTHROPIC_API_KEY secret; collecting and scanning only."
    fi
}

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

cmd_collect() {
    local bot='github-actions[bot]' raw pids='' pid failed=0
    mkdir -p "${dir}" "${work}"

    if [ -n "${from}" ]; then
        raw=${from}
    else
        need_repo
        need_pr
        raw=${work}
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

    jq -r '
        "# \(.title)\n\n"
        + "Labels: \([.labels[].name] | if length == 0 then "none" else join(", ") end)\n"
        + "Opened by \(.author.login), from \(.headRefName) into \(.baseRefName).\n\n"
        + "Commits, oldest first:\n\([.commits[] | "- \(.oid[0:7]) \(.messageHeadline)"] | join("\n"))\n\n"
        + .body
    ' "${raw}/pr.json" > "${dir}/pr.md"

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

    paste \
        <(jq -r '.[] | "\(.filename)\t\(.status)\t+\(.additions) -\(.deletions)"' "${raw}/files.json") \
        <(grep -n '^diff --git ' "${dir}/diff.patch" | cut -d : -f 1) \
        | awk -F '\t' '{ printf "- %s: %s, %s, diff.patch line %s\n", $1, $2, $3, $4 }' > "${work}/index.md"

    jq -r '.[].filename' "${raw}/files.json" | LC_ALL=C sort -u > "${dir}/files.txt"

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

read_all() {
    gh api "$1" --paginate | jq -s 'add // []' > "$2"
}

cmd_scan() {
    bash "${here}/review-scan.sh" < "${dir}/diff.patch" > "${dir}/scan.md"
    {
        echo "## review-scan"
        echo
        cat "${dir}/scan.md"
    } | summary
}

imported() {
    case "$1" in
        .github/PULL_REQUEST_TEMPLATE.md) return 0 ;;
        .github/conventions.php | .github/review/*) return 1 ;;
    esac
    if [ -L "$1" ]; then
        return 1
    elif [ -f "$1" ]; then
        head -n 10 "$1" | grep -q -F "Imported from ipsocode/hypervel-packages ${1#.}. Edit it there."
    else
        case "$1" in
            .github/*) return 0 ;;
            *) return 1 ;;
        esac
    fi
}

cmd_scope() {
    local path shared=0 own=''
    [ -f "${dir}/files.txt" ] || die "no ${dir}/files.txt; run collect first."
    while IFS= read -r path; do
        [ -n "${path}" ] || continue
        if imported "${path}"; then
            shared=$((shared + 1))
        else
            own="${own}${own:+, }${path}"
        fi
    done < "${dir}/files.txt"
    if [ -z "${own}" ] && [ "${shared}" -gt 0 ]; then
        set_output review false
        echo "::notice::Every file this pull request changes is imported from ipsocode/hypervel-packages, and checked there; Claude does not review it."
        printf '## Scope\n\nAll %s changed files are imported from ipsocode/hypervel-packages, so Claude does not review this pull request.\n' "${shared}" | summary
    else
        set_output review true
        echo "Claude reviews this pull request for the package's own files: ${own:-none}."
    fi
}

cmd_gzip_only() {
    local bin="${work}/gzip-only"
    mkdir -p "${bin}"
    printf '#!/bin/sh\nexit 1\n' > "${bin}/zstd"
    chmod +x "${bin}/zstd"
    echo "A zstd that fails, for the framework's restore: ${bin}/zstd"
}

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
    files=$(find "${tree}" -type f | wc -l | tr -d ' ')
    bytes=$(find "${tree}" -type f -exec cat {} + | wc -c | tr -d ' ')
    echo "The review can read ${tree}: ${files} files, $(awk -v b="${bytes}" 'BEGIN { printf "%.1f", b / 1048576 }') MB."
}

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
            if compare_diff "${reviewed}" > "${dir}/since-memory.patch"; then
                echo "since-memory.patch is the diff of these changes."
            else
                rm -f "${dir}/since-memory.patch"
            fi
        else
            echo "The notes reviewed ${reviewed}, which is gone from this branch's history (a force-push?): review the whole change."
        fi

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

compare_files() {
    if [ -n "${from}" ]; then
        [ -f "${from}/compare.json" ] && jq -r '.files[].filename' "${from}/compare.json"
    else
        gh api "repos/${repo}/compare/${1}...${head_sha}" --jq '.files[].filename' 2> /dev/null
    fi
}

compare_diff() {
    if [ -n "${from}" ]; then
        [ -f "${from}/compare.diff" ] && cat "${from}/compare.diff"
    else
        gh api -H 'Accept: application/vnd.github.diff' "repos/${repo}/compare/${1}...${head_sha}" 2> /dev/null
    fi
}

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
    set_output key "claude-code-${version}-${RUNNER_OS:-$(uname -s)}-${RUNNER_ARCH:-$(uname -m)}"
    echo "The review's action installs Claude Code ${version}."
}

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

cmd_install() {
    ci_only install
    mkdir -p "${HOME}/.claude/skills"
    cp -R .github/claude/skills/. "${HOME}/.claude/skills/"
    cp .github/claude/AGENTS.md "${HOME}/.claude/CLAUDE.md"
    find "${HOME}/.claude" -type f | sed "s|^${HOME}|~|"
}

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

cmd_summarize() {
    local execution=${file:-${work}/claude-execution-output.json} unfinished=''
    if [ "${REVIEW_OUTCOME:-}" = failure ]; then
        unfinished="- Did not finish: the session failed, or ran past its time limit. claude / review passes without it."
        echo "::warning::The review did not finish: it failed, or ran past its time limit. Re-run the job for a review."
    fi
    [ -f "${execution}" ] || [ -n "${unfinished}" ] || return 0
    {
        echo "## Review session"
        echo
        [ -z "${unfinished}" ] || echo "${unfinished}"
        [ ! -f "${execution}" ] || jq -r '
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

cmd_collapse() {
    local owner name newest id minimized
    need_repo
    need_pr
    need_head
    mkdir -p "${work}"
    owner=${repo%%/*}
    name=${repo#*/}

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
    scope) cmd_scope ;;
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
