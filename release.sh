#!/usr/bin/env bash

# Dispatches the Release workflow, which does the real work (version bump of the compiler,
# commit, tag, draft release, build of the phemc binaries). See .github/workflows/release.yaml.

set -o nounset
set -o errexit
set -o errtrace # so the ERR trap fires inside functions and subshells too
set -o pipefail
trap 'echo "Aborting on line $LINENO. Exit: $?" >&2' ERR

for cmd in git gh; do
	if ! command -v "$cmd" >/dev/null; then
		echo "The \"$cmd\" command must be installed." >&2
		exit 1
	fi
done

if [[ $# -ne 1 ]]; then
	echo "Usage: ./release.sh version (for example 0.1.0, no v prefix)" >&2
	exit 1
fi

# Fast checks before the dispatch. release.yaml checks the version again.
if [[ "$(git branch --show-current 2>/dev/null)" != "main" ]]; then
	echo "You must be on the main branch to dispatch a release." >&2
	exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
	echo "The working tree is not clean. Commit or stash your changes first." >&2
	exit 1
fi

git fetch --quiet --tags origin main
local_head="$(git rev-parse HEAD)"
remote_head="$(git rev-parse origin/main)"
if [[ "$local_head" != "$remote_head" ]]; then
	if git merge-base --is-ancestor HEAD origin/main; then
		echo "Local main is behind origin/main. Pull first." >&2
	elif git merge-base --is-ancestor origin/main HEAD; then
		echo "Local main is ahead of origin/main. Push your commits first." >&2
	else
		echo "Local main and origin/main have diverged. Reconcile them first." >&2
	fi
	exit 1
fi

gh workflow run release.yaml --ref main -f version="$1"
echo "Release workflow dispatched for v$1."
echo "Watch the runs: gh run list --workflow=release.yaml --event=workflow_dispatch"
echo "Then publish the draft release on GitHub: bin/phemc downloads only from a published release."
