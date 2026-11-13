#!/usr/bin/env bash
# Writes Anchor's shared OAuth app into each site's wp-config.php so the
# Connectors tab shows a one-click "Sign in" button. The credentials never go
# in this (public) repo or the release ZIP.
#
# Usage:
#   bin/push-connector-credentials.sh SITES_FILE            # push credentials, print callback URLs
#   bin/push-connector-credentials.sh --urls-only SITES_FILE # only print callback URLs
#
# SITES_FILE: one site per line, "<ssh-target> <port> <wordpress-path>", e.g.
#   tmjint@35.1.2.3 22451 /www/tmjint_123/public
# Blank lines and lines starting with # are skipped. Keep this file outside the repo.
#
# Credentials come from the environment (each one optional; unset = not touched):
#   ANCHOR_GOOGLE_CLIENT_ID  ANCHOR_GOOGLE_CLIENT_SECRET  ANCHOR_MICROSOFT_CLIENT_ID
#
# Values travel over ssh stdin, not the command line. Collect the printed
# callback URLs and add them all to the Google OAuth client's
# "Authorized redirect URIs" (and the Entra app's redirect URIs) in one go.
set -euo pipefail

urls_only=0
if [[ "${1:-}" == "--urls-only" ]]; then
  urls_only=1
  shift
fi
sites="${1:?usage: $0 [--urls-only] SITES_FILE}"
[[ -r "$sites" ]] || { echo "Cannot read $sites" >&2; exit 1; }

names=(ANCHOR_GOOGLE_CLIENT_ID ANCHOR_GOOGLE_CLIENT_SECRET ANCHOR_MICROSOFT_CLIENT_ID)
if [[ $urls_only -eq 0 ]]; then
  any=0
  for n in "${names[@]}"; do [[ -n "${!n:-}" ]] && any=1; done
  [[ $any -eq 1 ]] || { echo "Set at least one of: ${names[*]}" >&2; exit 1; }
fi

failed=0
while read -r target port path _rest; do
  [[ -z "${target:-}" || "$target" == \#* ]] && continue
  # Remote script on stdin: values never appear in ssh's argv.
  script="set -e; cd $(printf '%q' "$path")"$'\n'
  if [[ $urls_only -eq 0 ]]; then
    for n in "${names[@]}"; do
      v="${!n:-}"
      [[ -z "$v" ]] && continue
      script+="wp config set $n $(printf '%q' "$v") --type=constant --quiet"$'\n'
    done
  fi
  script+="wp eval 'echo rest_url(\"anchor-tools/v1/connectors/callback\"), PHP_EOL;'"$'\n'
  if out=$(ssh -o BatchMode=yes -o ConnectTimeout=15 -p "$port" "$target" 'bash -s' 2>&1 <<<"$script"); then
    echo "$out" | tail -n 1
  else
    echo "FAILED $target: $(echo "$out" | tail -n 1)" >&2
    failed=1
  fi
done <"$sites"
exit $failed
