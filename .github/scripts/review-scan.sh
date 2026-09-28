#!/usr/bin/env bash
# Imported from ipsocode/hypervel-packages github/scripts/review-scan.sh. Edit it there.
#
# An advisory scan of a pull request's diff, for the automated review
# (.github/workflows/review.yml). The conventions check
# (.github/scripts/conventions.php) judges the whole tree and fails the build.
# This judges the change itself, what a pull request adds or removes, and only
# points: each finding is a lead for the review to check against the code, never
# a verdict.
#
# It reads a unified diff on stdin and prints Markdown, one finding per line:
#
#   - path:line **rule** — message
#
# A line number is the line's number in the new file; a finding marked (removed)
# gives the line's number in the old one. It always exits 0.
#
#   dependency    a vendor/package entry added to composer.json, or re-constrained
#   gate          a coverage-gate line (--min, MIN_COVERAGE) changed in composer.json
#                 or a workflow; phpunit.xml, phpstan*.neon, .php-cs-fixer*.php, the
#                 shared php-cs-fixer rules, or the conventions check or its settings
#                 changed, with each line removed
#   tests         src/ changed and tests/ did not; a test added that is skipped,
#                 incomplete, covers nothing or asserts nothing
#   raw SQL       a value interpolated or concatenated into a raw SQL call
#   secret        a literal that looks like a credential; the value is never printed
#   sensitive     an .env, private key or certificate file committed
#   static state  a new static property or function-static variable in src/ while
#                 src/Testing/TestState.php is unchanged
#
# REVIEW_SCAN_SKIP, if set, is an extended regular expression; files whose path
# matches it are not scanned.
#
# Local use, on a branch:
#
#   git diff origin/main...HEAD | bash .github/scripts/review-scan.sh
#
# The same file in every ipsocode/hypervel-* repository: what differs between them
# is REVIEW_SCAN_SKIP, which `review.sh focus` reads from the package's
# .github/review/scan-skip. Plain POSIX awk on purpose, so it runs the same under
# the runner's awk, busybox in the CI image and the BSD awk on a Mac.

set -u

# read, not $(cat <<…): the bash 3.2 a Mac ships cannot parse a heredoc holding
# backticks and unpaired quotes inside a command substitution. read returns 1 at
# the end of the input, which is expected here.
IFS= read -r -d '' program <<'AWK' || true
BEGIN {
    findings = ""
    statics = ""
    src_changed = 0
    tests_changed = 0
    teststate_changed = 0
    reset_file()
}

function reset_file() {
    path = ""
    old_path = ""
    is_new = 0
    is_deleted = 0
    skipped = 0
    saw_hunk = 0
    added = 0
    removed = 0
    removed_shown = 0
    removed_more = 0
    old_left = 0
    new_left = 0
    buf = ""
    deps = 0
    split("", dep_removed)
    split("", dep_name)
    split("", dep_where)
    split("", dep_text)
}

# The line in backticks, without its diff marker, trimmed and shortened.
function snippet(s) {
    s = substr(s, 2)
    gsub(/`/, "'", s)
    gsub(/^[[:blank:]]+/, "", s)
    gsub(/[[:blank:]]+$/, "", s)
    if (length(s) > 140) {
        s = substr(s, 1, 137) "..."
    }
    return "`" s "`"
}

function note(where, rule, message) {
    buf = buf "- " where " **" rule "** — " message "\n"
}

function gate_file(p) {
    return p ~ /^phpunit\.xml/ ||
        p ~ /^phpstan[^\/]*\.neon/ ||
        p ~ /^\.php-cs-fixer[^\/]*\.php$/ ||
        p == ".github/php-cs-fixer-rules.php" ||
        p == ".github/conventions.php" ||
        p == ".github/scripts/conventions.php"
}

function workflow(p) {
    return p ~ /^\.github\/workflows\/[^\/]+\.ya?ml$/
}

function coverage_line(s) {
    return index(s, "--min") > 0 || index(s, "MIN_COVERAGE") > 0
}

# The vendor/package a composer.json link line names, or "".
function package(s,   name) {
    if (!match(s, /^[-+][[:blank:]]*"[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+"[[:blank:]]*:[[:blank:]]*"/)) {
        return ""
    }
    name = substr(s, RSTART, RLENGTH)
    sub(/^[-+][[:blank:]]*"/, "", name)
    sub(/".*$/, "", name)
    return name
}

function raw_call(s) {
    return s ~ /DB::(raw|select|selectOne|selectFromWriteConnection|selectResultSets|scalar|cursor|insert|update|delete|statement|affectingStatement|unprepared)[[:blank:]]*\(/ ||
        s ~ /(->|::)[A-Za-z_]*Raw[[:blank:]]*\(/ ||
        s ~ /->(raw|statement|affectingStatement|unprepared)[[:blank:]]*\(/
}

# Whether the line builds a value into a string: a variable inside a
# double-quoted string, a concatenation with a variable, or sprintf().
function interpolated(s,   t, rest, seg) {
    t = s
    gsub(/'[^']*'/, "''", t)
    rest = t
    while (match(rest, /"[^"]*"/)) {
        seg = substr(rest, RSTART, RLENGTH)
        if (seg ~ /[$][A-Za-z_{]/) {
            return 1
        }
        rest = substr(rest, RSTART + RLENGTH)
    }
    gsub(/"[^"]*"/, "\"\"", t)
    t = " " t " "
    return t ~ /[^.][.][[:blank:]]*[$]/ ||
        t ~ /[$][A-Za-z_][A-Za-z0-9_>-]*[[:blank:]]*[.][^.=]/ ||
        t ~ /sprintf[[:blank:]]*\(/
}

# What kind of credential the line seems to carry, or "".
function secret_kind(s,   low, m) {
    if (s ~ /-----BEGIN [A-Z ]*PRIVATE KEY-----/) return "a private key"
    if (match(s, /gh[pousr]_[A-Za-z0-9]+/) && RLENGTH >= 40) return "a GitHub token"
    if (match(s, /github_pat_[A-Za-z0-9_]+/) && RLENGTH >= 40) return "a GitHub token"
    if (match(s, /sk-ant-[A-Za-z0-9_-]+/) && RLENGTH >= 40) return "an Anthropic API key"
    if (match(s, /AKIA[0-9A-Z]+/) && RLENGTH >= 20) return "an AWS access key"
    if (match(s, /xox[abeoprs]-[A-Za-z0-9-]+/) && RLENGTH >= 20) return "a Slack token"
    if (match(s, /AIza[0-9A-Za-z_-]+/) && RLENGTH >= 39) return "a Google API key"
    low = tolower(s)
    if (match(low, /(password|passwd|secret|token|api_?key|access_?key|private_?key)["']?[[:blank:]]*(=>|=|:)[[:blank:]]*["'][^"'[:blank:]]+["']/)) {
        m = substr(low, RSTART, RLENGTH)
        if (match(m, /["'][^"'[:blank:]]+["']$/) && RLENGTH - 2 >= 16) return "a hard-coded credential"
    }
    return ""
}

function sensitive(p,   base) {
    base = p
    sub(/^.*\//, "", base)
    if (base == ".env.example" || base == ".env.dist") return 0
    return base ~ /^\.env(\..+)?$/ ||
        base ~ /\.(pem|key|p12|pfx|jks|keystore)$/ ||
        base ~ /^id_(rsa|dsa|ecdsa|ed25519)$/
}

function static_state(s) {
    return s ~ /^[+][[:blank:]]*((public|protected|private|final|var)[[:blank:]]+)*static[[:blank:]]+([?]?[^[:blank:]$(]+[[:blank:]]+)?[$][A-Za-z_]/
}

function on_added(   where, name, kind) {
    added++
    if (skipped) return
    where = path ":" new_line
    if (path == "composer.json") {
        name = package($0)
        if (name != "") {
            deps++
            dep_name[deps] = name
            dep_where[deps] = where
            dep_text[deps] = snippet($0)
        }
    }
    if ((path == "composer.json" || workflow(path)) && coverage_line($0)) {
        note(where, "gate", "coverage gate line " snippet($0))
    }
    if (path ~ /\.php$/) {
        if ($0 ~ /markTestSkipped|markTestIncomplete|CoversNothing|coversNothing|DoesNotPerformAssertions|doesNotPerformAssertions/) {
            note(where, "tests", "a test that is skipped, incomplete, covers nothing or asserts nothing: " snippet($0))
        }
        if (raw_call($0) && interpolated($0)) {
            note(where, "raw SQL", "a value built into raw SQL; bind it, and never take an identifier from input: " snippet($0))
        }
        if (path ~ /^src\// && static_state($0)) {
            statics = statics "- " where " **static state** — a new static: reset it from src/Testing/TestState.php, or keep request state in CoroutineContext: " snippet($0) "\n"
        }
    }
    kind = secret_kind($0)
    if (kind != "") {
        note(where, "secret", "looks like " kind "; the value is not repeated here")
    }
}

function on_removed(   where, name) {
    removed++
    if (skipped) return
    where = path ":" old_line " (removed)"
    if (path == "composer.json") {
        name = package($0)
        if (name != "") dep_removed[name] = 1
    }
    if ((path == "composer.json" || workflow(path)) && coverage_line($0)) {
        note(where, "gate", "coverage gate line " snippet($0))
    } else if (gate_file(path)) {
        if (removed_shown < 15) {
            note(where, "gate", "removed " snippet($0))
            removed_shown++
        } else {
            removed_more++
        }
    }
}

function finish_file(   i, verb, head) {
    if (path != "" && !skipped) {
        if (!is_deleted && path ~ /^src\//) src_changed = 1
        if (path ~ /^tests\//) tests_changed = 1
        if (path == "src/Testing/TestState.php") teststate_changed = 1
        if (!is_deleted && sensitive(path)) {
            note(path, "sensitive", "a credential file is committed; it belongs in the environment or a secret store")
        }
        for (i = 1; i <= deps; i++) {
            if (dep_name[i] in dep_removed) {
                note(dep_where[i], "dependency", "constraint changed: " dep_text[i])
            } else {
                note(dep_where[i], "dependency", "new dependency " dep_text[i] ": is it needed, and does Hypervel not provide it already?")
            }
        }
        if (gate_file(path)) {
            verb = is_new ? "added" : (is_deleted ? "deleted" : "changed")
            head = "- " path " **gate** — " verb " (+" added "/-" removed "): check that no check, level or threshold got weaker\n"
            if (removed_more > 0) {
                buf = buf "- " path " **gate** — and " removed_more " more removed lines\n"
            }
            buf = head buf
        }
        findings = findings buf
    }
    reset_file()
}

{
    sub(/\r$/, "")
}

# Inside a hunk every line is content, whatever it starts with, until the
# header's line counts run out.
old_left > 0 || new_left > 0 {
    c = substr($0, 1, 1)
    if (c == "+") {
        on_added()
        new_line++
        new_left--
    } else if (c == "-") {
        on_removed()
        old_line++
        old_left--
    } else if (c != "\\") {
        old_line++
        new_line++
        old_left--
        new_left--
    }
    next
}

/^diff --git / {
    finish_file()
    p = substr($0, 12)
    i = index(p, " b/")
    path = i > 0 ? substr(p, i + 3) : p
    skipped = skip != "" && path ~ skip
    next
}

/^--- / {
    if (saw_hunk) finish_file()
    if ($0 ~ /^--- \/dev\/null/) {
        is_new = 1
    } else {
        old_path = substr($0, 7)
        sub(/\t.*$/, "", old_path)
    }
    next
}

/^\+\+\+ / {
    if ($0 ~ /^\+\+\+ \/dev\/null/) {
        is_deleted = 1
        if (path == "") path = old_path
    } else {
        path = substr($0, 7)
        sub(/\t.*$/, "", path)
    }
    skipped = skip != "" && path ~ skip
    next
}

/^@@ / {
    saw_hunk = 1
    split($0, parts, " ")
    split(substr(parts[2], 2), o, ",")
    split(substr(parts[3], 2), n, ",")
    old_line = o[1] + 0
    old_left = (2 in o) ? o[2] + 0 : 1
    new_line = n[1] + 0
    new_left = (2 in n) ? n[2] + 0 : 1
    next
}

END {
    finish_file()
    if (src_changed && !tests_changed) {
        findings = findings "- src/ **tests** — src/ changed and nothing under tests/ did: which test proves the change?\n"
    }
    if (!teststate_changed) {
        findings = findings statics
    }
    if (findings == "") {
        print "Nothing flagged."
    } else {
        print "Leads from .github/scripts/review-scan.sh, to check against the code. Line numbers are the new file's; (removed) numbers a line of the old file."
        print ""
        printf "%s", findings
    }
}
AWK

LC_ALL=C awk -v skip="${REVIEW_SCAN_SKIP:-}" "${program}"
exit 0
