#!/bin/sh

# Downloads the phemc binary of the latest release to $BIN_DIR (default: the current directory).
#   curl -fsSL https://github.com/phemphp/phem/raw/refs/heads/main/install.sh | sh
# With Composer, you do not need it: `composer require phem/phem` gives vendor/bin/phemc.

set -e

REPO="phemphp/phem"

if [ -z "${BIN_DIR}" ]; then
	BIN_DIR=$(pwd)
fi

DEST=${BIN_DIR}/phemc
OS=$(uname -s)
ARCH=$(uname -m)
SUDO=""

if ! command -v curl >/dev/null 2>&1; then
	echo "Please install curl to download phemc."
	exit 1
fi

case ${OS} in
Linux*)
	case ${ARCH} in
	aarch64 | arm64) ASSET="phemc-linux-aarch64" ;;
	x86_64 | amd64) ASSET="phemc-linux-x86_64" ;;
	*) ASSET="" ;;
	esac
	;;
Darwin*)
	case ${ARCH} in
	arm64) ASSET="phemc-mac-arm64" ;;
	x86_64) ASSET="phemc-mac-x86_64" ;;
	*) ASSET="" ;;
	esac
	;;
CYGWIN_NT* | MSYS_NT* | MINGW*)
	ASSET="phemc-windows-x86_64.exe"
	DEST=${BIN_DIR}/phemc.exe
	;;
*)
	ASSET=""
	;;
esac

if [ -z "${ASSET}" ]; then
	echo "No prebuilt phemc binary for ${ARCH}-${OS}."
	echo "Build it from the sources: cargo build --release --manifest-path compiler/Cargo.toml -p phemc-cli"
	exit 1
fi

echo "Downloading phemc for ${OS} (${ARCH})..."

# Get sudo powers when $DEST is not writable.
if ! touch "${DEST}" 2>/dev/null; then
	echo "You do not have permission to write to ${DEST}. Enter your password to grant sudo powers."
	SUDO="sudo"
fi

${SUDO} curl -fL --progress-bar "https://github.com/${REPO}/releases/latest/download/${ASSET}" -o "${DEST}"
${SUDO} chmod +x "${DEST}"

echo
echo "phemc downloaded to ${DEST}."
case ":${PATH}:" in
*":${BIN_DIR}:"*) ;;
*)
	echo "Move it to /usr/local/bin/ or another directory in your PATH to use it globally:"
	echo "	sudo mv ${DEST} /usr/local/bin/"
	;;
esac
