#!/usr/bin/env bash

# Releases a version from start to end:
#   0. Asks for the version (patch, minor or major bumps the latest stable tag) and to confirm it.
#      Without arguments, it asks with menus: select with the arrow keys, then press Enter.
#   1. Dispatches the Release workflow (version bump of the compiler, commit, tag, draft release).
#      See .github/workflows/release.yaml.
#   2. Watches it, then watches the build of the phemc binaries that it dispatches (phemc.yaml).
#   3. Checks that the draft release has all the phemc binaries and phemc-checksums.txt.
#   4. Publishes the draft release (bin/phemc downloads only from a published release).
#   5. Waits until Packagist has the version.
# Run it again with the same version after a failure: release.yaml resumes from the tag.

set -o nounset
set -o errexit
set -o errtrace # so the ERR trap fires inside functions and subshells too
set -o pipefail
trap 'echo "Aborting on line $LINENO. Exit: $?" >&2' ERR

REPO="phemphp/phem"
ASSETS=(
	phemc-checksums.txt
	phemc-linux-aarch64
	phemc-linux-x86_64
	phemc-mac-arm64
	phemc-mac-x86_64
	phemc-windows-x86_64.exe
)

usage() {
	echo "Usage: ./release.sh [--yes] [patch|minor|major|version]" >&2
	echo "  (no argument)      select the version in a menu" >&2
	echo "  patch|minor|major  bump the latest stable tag (v0.1.0 -> 0.1.1, 0.2.0 or 1.0.0)" >&2
	echo "  version            release this version (for example 0.2.0-beta.1, no v prefix)" >&2
	echo "  --yes              start and publish without a prompt" >&2
	exit 1
}

step() {
	printf '\n==> %s\n' "$*"
}

# Prints the ID of the first run of a workflow that a dispatch started at or after $since.
# $3 is an optional ref (a tag) that the run must have.
find_run() {
	local workflow="$1" since="$2" ref="${3:-}" id=""
	local filter=".[] | select(.createdAt >= \"${since}\")"
	if [[ -n "$ref" ]]; then
		filter="${filter} | select(.headBranch == \"${ref}\")"
	fi

	for _ in $(seq 1 60); do
		id="$(gh run list --repo "$REPO" --workflow="$workflow" --event=workflow_dispatch --limit 20 \
			--json databaseId,createdAt,headBranch --jq "[${filter}] | sort_by(.createdAt) | .[0].databaseId // empty")"
		if [[ -n "$id" ]]; then
			echo "$id"
			return 0
		fi

		sleep 5
	done

	echo "No run of $workflow started after $since." >&2
	return 1
}

watch_run() {
	local id="$1"
	echo "https://github.com/${REPO}/actions/runs/${id}"

	if ! gh run watch "$id" --repo "$REPO" --exit-status --interval 10; then
		echo "The run failed. See the logs: gh run view $id --repo $REPO --log-failed" >&2
		echo "Fix the cause, then run ./release.sh $VERSION again to resume." >&2
		exit 1
	fi
}

# Prints the latest stable tag without the "v" prefix, or 0.0.0 when there is none.
latest_version() {
	local tag
	tag="$(git tag --list 'v*' --sort=-v:refname | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | head -n 1 || true)"
	echo "${tag:-v0.0.0}" | sed 's/^v//'
}

# Prints the version after $1 for a bump of $2 (patch, minor or major).
bump_version() {
	local major minor patch
	IFS=. read -r major minor patch <<<"$1"

	case "$2" in
		patch) echo "${major}.${minor}.$((patch + 1))" ;;
		minor) echo "${major}.$((minor + 1)).0" ;;
		major) echo "$((major + 1)).0.0" ;;
	esac
}

# Shows the menu $1 with the options $2... on the terminal. Select an option with the arrow keys
# (or j and k), then press Enter. Sets CHOICE to the index of the selected option.
choose() {
	local prompt="$1" selected=0 key i
	shift
	local options=("$@")
	local count=${#options[@]}

	printf '\033[1m? %s\033[0m\n' "$prompt" >&2
	tput civis >&2 2>/dev/null || true

	while true; do
		for i in "${!options[@]}"; do
			if [[ $i -eq $selected ]]; then
				printf '\033[2K\033[36m> %s\033[0m\n' "${options[$i]}" >&2
			else
				printf '\033[2K  %s\n' "${options[$i]}" >&2
			fi
		done

		IFS= read -rsn1 key
		if [[ "$key" == $'\e' ]]; then
			IFS= read -rsn2 key
		fi

		case "$key" in
			'[A' | k) selected=$(((selected + count - 1) % count)) ;;
			'[B' | j) selected=$(((selected + 1) % count)) ;;
			'') break ;;
		esac

		printf '\033[%dA' "$count" >&2
	done

	# Replace the menu with the answer.
	printf '\033[%dA\033[J\033[1m? %s\033[0m \033[36m%s\033[0m\n' "$((count + 1))" "$prompt" "${options[$selected]}" >&2
	tput cnorm >&2 2>/dev/null || true
	CHOICE=$selected
}

confirm() {
	local answer
	[[ "$YES" != "true" ]] || return 0

	if [[ -t 0 ]]; then
		choose "$1" "Yes" "No"
		return "$CHOICE"
	fi

	read -r -p "$1 [y/N] " answer
	[[ "$answer" == [yY]* ]]
}

for cmd in git gh curl; do
	if ! command -v "$cmd" >/dev/null; then
		echo "The \"$cmd\" command must be installed." >&2
		exit 1
	fi
done

YES=false
TARGET=""
for arg in "$@"; do
	case "$arg" in
		--yes) YES=true ;;
		-*) usage ;;
		*) [[ -z "$TARGET" ]] || usage; TARGET="$arg" ;;
	esac
done

# Without a version, the menu asks for it.
[[ -n "$TARGET" || -t 0 ]] || usage
trap 'tput cnorm >&2 2>/dev/null || true' EXIT

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

# The tags are fetched: the latest stable tag is the one of origin.
latest="$(latest_version)"
echo "Latest release: v${latest}"

if [[ -z "$TARGET" ]]; then
	bumps=(patch minor major)
	options=()
	for bump in "${bumps[@]}"; do
		options+=("$(printf '%-6s v%s' "$bump" "$(bump_version "$latest" "$bump")")")
	done

	choose "Which release?" "${options[@]}" "other version"
	if [[ $CHOICE -lt ${#bumps[@]} ]]; then
		TARGET="${bumps[$CHOICE]}"
	else
		while [[ -z "$TARGET" ]]; do
			read -r -p "Version (for example 0.2.0-beta.1, no v prefix): " TARGET
		done
	fi
fi

case "$TARGET" in
	patch | minor | major) VERSION="$(bump_version "$latest" "$TARGET")" ;;
	*) VERSION="$TARGET" ;;
esac

TAG="v${VERSION}"

if ! confirm "Release ${TAG}?"; then
	echo "Not released."
	exit 0
fi

# A few seconds back, for the clock difference with GitHub.
since="$(date -u -v-30S +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u -d '-30 seconds' +%Y-%m-%dT%H:%M:%SZ)"

step "Dispatching the Release workflow for ${TAG}"
gh workflow run release.yaml --repo "$REPO" --ref main -f version="$VERSION"
run_id="$(find_run release.yaml "$since")"
watch_run "$run_id"

step "Watching the build of the phemc binaries"
run_id="$(find_run phemc.yaml "$since" "$TAG")"
watch_run "$run_id"

step "Checking the assets of the ${TAG} release"
assets="$(gh release view "$TAG" --repo "$REPO" --json assets --jq '.assets[].name')"
missing=()
for asset in "${ASSETS[@]}"; do
	if ! grep -qxF "$asset" <<<"$assets"; then
		missing+=("$asset")
	fi
done

if [[ ${#missing[@]} -gt 0 ]]; then
	echo "The ${TAG} release has no: ${missing[*]}" >&2
	exit 1
fi

echo "All ${#ASSETS[@]} assets are on the release."

step "Publishing the ${TAG} release"
if [[ "$(gh release view "$TAG" --repo "$REPO" --json isDraft --jq .isDraft)" != "true" ]]; then
	echo "The ${TAG} release is already published."
else
	if ! confirm "Publish the draft release ${TAG} now?"; then
		echo "Not published. Publish it later: gh release edit $TAG --repo $REPO --draft=false"
		exit 0
	fi

	# Pre-releases (a SemVer "-" suffix) do not become the latest release.
	latest=(--latest)
	if [[ "$VERSION" == *-* ]]; then
		latest=(--latest=false)
	fi

	gh release edit "$TAG" --repo "$REPO" --draft=false "${latest[@]}" >/dev/null
	echo "Published."
fi

step "Waiting for Packagist to have phem/phem ${TAG}"
for _ in $(seq 1 30); do
	if curl -fsS "https://repo.packagist.org/p2/phem/phem.json" 2>/dev/null | grep -qF "\"version\":\"${TAG}\""; then
		echo "Packagist has ${TAG}."
		echo
		echo "Released ${TAG}: https://github.com/${REPO}/releases/tag/${TAG}"
		exit 0
	fi

	sleep 10
done

echo "Packagist does not have ${TAG} after 5 minutes. Check its GitHub hook: https://packagist.org/packages/phem/phem" >&2
exit 1
