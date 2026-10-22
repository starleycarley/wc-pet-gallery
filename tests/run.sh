#!/bin/sh
# Every check, in the order of how much it would hurt to get wrong.
#
# Two holes used to make this script report success over a failure, and both
# are worth remembering, because the output looked fine either way:
#
#  - Three tests were piped through `tail -1` to trim their output. A POSIX
#    pipeline exits with the status of its LAST command, so the status came
#    from `tail` and was always 0 — `set -e` sailed straight past a failing
#    test. `set -o pipefail` would fix it but is not in POSIX sh (dash has no
#    such option), and this script is documented as `sh tests/run.sh`. Hence
#    run_trimmed(): capture, check the status, print everything on failure and
#    only the last line on success.
#  - The lint stage took its status from `grep`, then `|| true` discarded even
#    that, before an unconditional `echo "   clean"`. A parse error printed
#    itself on screen and the run still reported clean and exited 0.
set -e
cd "$(dirname "$0")/.."

# Run a test, showing only its last line when it passes and all of it when it
# does not — without a pipeline swallowing the exit status.
run_trimmed() {
	if out=$( php "$1" ); then
		printf '%s\n' "$out" | tail -1
	else
		printf '%s\n' "$out"
		echo "   FAILED: $1"
		exit 1
	fi
}

echo "== php -l =="
# Lint everything that ships, update/ included — those vendored files are
# installed on client sites, where a parse error in one of them is a fatal.
lint_errors=$( find . -name '*.php' -not -path './.git/*' -exec php -l {} \; | grep -v 'No syntax errors' || true )
if [ -n "$lint_errors" ]; then
	printf '%s\n' "$lint_errors"
	echo "   FAILED"
	exit 1
fi
echo "   clean"

echo "== js syntax =="
node --check asset/js/gallery.js && echo "   clean"

echo "== the UKM hard constraint =="
php tests/test-ukm-constraint.php

echo "== archive pagination =="
run_trimmed tests/test-pagination.php

echo "== pet-type schema =="
run_trimmed tests/test-schema.php

echo "== plugin loads, all tabs render =="
run_trimmed tests/test-load.php
