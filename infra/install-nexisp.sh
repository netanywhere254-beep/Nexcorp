#!/usr/bin/env bash
#
# NexISP host preparation — Debian/Ubuntu reference installer.
#
# Usage from the Nexcorp repository root:
#   bash infra/install-nexisp.sh
#
# This installs Docker Engine from the distribution packages, Git, and
# Docker Compose (v2 when available). It does NOT deploy the NexISP API.
# Review infra/README.md before proceeding with the optional NestJS stack.

set -Eeuo pipefail

log() {
  printf '\n[NexISP installer] %s\n' "$1"
}

fail() {
  printf '\n[NexISP installer] ERROR: %s\n' "$1" >&2
  exit 1
}

if [[ ! -r /etc/os-release ]]; then
  fail "Cannot identify this Linux distribution. This script supports Debian/Ubuntu only."
fi

# shellcheck disable=SC1091
. /etc/os-release

case "${ID:-}" in
  ubuntu|debian) ;;
  *)
    case " ${ID_LIKE:-} " in
      *" debian "*) ;;
      *) fail "Unsupported distribution: ${PRETTY_NAME:-unknown}. Use Debian or Ubuntu." ;;
    esac
    ;;
esac

if [[ "$EUID" -eq 0 ]]; then
  SUDO=()
else
  command -v sudo >/dev/null 2>&1 || fail "Please run as root or install sudo first."
  SUDO=(sudo)
fi

export DEBIAN_FRONTEND=noninteractive

log "Updating package indexes..."
"${SUDO[@]}" apt-get update

log "Installing Docker Engine, Git, and supporting packages..."
"${SUDO[@]}" apt-get install -y docker.io git ca-certificates curl

log "Installing Docker Compose..."
if apt-cache show docker-compose-v2 >/dev/null 2>&1; then
  "${SUDO[@]}" apt-get install -y docker-compose-v2
elif apt-cache show docker-compose-plugin >/dev/null 2>&1; then
  "${SUDO[@]}" apt-get install -y docker-compose-plugin
elif apt-cache show docker-compose >/dev/null 2>&1; then
  log "The Compose v2 package is unavailable in the configured repositories; installing legacy docker-compose."
  "${SUDO[@]}" apt-get install -y docker-compose
else
  fail "No Docker Compose package was found in the configured APT repositories."
fi

log "Enabling and starting Docker..."
"${SUDO[@]}" systemctl enable --now docker

command -v docker >/dev/null 2>&1 || fail "Docker command was not found after installation."
"${SUDO[@]}" docker --version

if "${SUDO[@]}" docker compose version >/dev/null 2>&1; then
  "${SUDO[@]}" docker compose version
  COMPOSE_COMMAND="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
  docker-compose version
  COMPOSE_COMMAND="docker-compose"
else
  fail "Docker Compose installation could not be verified."
fi

if command -v git >/dev/null 2>&1; then
  git --version
fi

log "Docker host preparation completed."
printf '\nCompose command detected: %s\n' "$COMPOSE_COMMAND"
printf '%s\n' "Next: review infra/README.md and prepare infra/.env.nexisp from the example file."
printf '%s\n' "Do not start the optional NestJS Compose stack until its backend/Dockerfile and application configuration exist."

if [[ "$EUID" -ne 0 && -n "${SUDO_USER:-}" ]]; then
  printf '\nTo allow user %s to run Docker without sudo, you may run:\n' "$SUDO_USER"
  printf '  sudo usermod -aG docker %q\n' "$SUDO_USER"
  printf '%s\n' "WARNING: membership in the docker group grants root-equivalent privileges. Only do this for a trusted administrator, then sign out and back in."
fi

printf '\n%s\n' "Note: this script intentionally does not run apt-get upgrade -y. Apply OS upgrades separately during a planned maintenance window."
