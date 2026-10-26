#!/usr/bin/env bash
# Embeds a pinned open-pedigree release build in open-pedigree/, or checks the embed.
#
#   scripts/update-open-pedigree.sh <tag>   e.g. open-pedigree-v1.4.6
#       Downloads that release's open-pedigree-dist-<version>.zip and its .sha256 (no token
#       needed - aehrc/open-pedigree is public), verifies the zip against it, and replaces
#       open-pedigree/dist/ with the files this module uses, plus open-pedigree/LICENSE, and
#       records the release in open-pedigree/embedded.json. Everything is downloaded, verified
#       and staged before anything in open-pedigree/ changes; the swap is then a few renames,
#       all undone if one fails. Refuses to re-pin a tag that is already pinned to a different
#       sha256 - a published asset should never change, so that needs investigating.
#
#   scripts/update-open-pedigree.sh --check
#       Used by CI: downloads the release named in open-pedigree/embedded.json and fails if
#       the zip's sha256 isn't the pinned one, if open-pedigree/dist/ or LICENSE differ from it
#       in any way (changed, missing, extra or symlinked), or if open-pedigree/ holds anything
#       else.
#
# open-pedigree/localEditor.html is this module's own host page and is never touched.
# Needs bash, curl, sha256sum, unzip and jq.
set -euo pipefail

REPO=aehrc/open-pedigree
# The files this module uses from the release's dist/.
FILES=(
  pedigree.min.js
  pedigree.min.js.LICENSE.txt
  defaultQuestionnaire.json
  assets/open-pedigree-icon.png
)
# Everything open-pedigree/ may contain.
EMBED_ENTRIES=(LICENSE dist embedded.json localEditor.html)
# The files the update replaces.
SWAPPED=(dist LICENSE embedded.json)

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
embed="$root/open-pedigree"
manifest="$embed/embedded.json"

die() {
  echo "update-open-pedigree: $*" >&2
  exit 1
}

usage() {
  echo "usage: $0 <open-pedigree-vX.Y.Z> | --check" >&2
  exit 2
}

contains() {
  local want=$1 item
  shift
  for item in "$@"; do
    [ "$item" = "$want" ] && return 0
  done
  return 1
}

for cmd in curl sha256sum unzip jq; do
  command -v "$cmd" > /dev/null || die "needs $cmd, which isn't installed"
done

[ $# -eq 1 ] || usage
pinned_tag='' pinned_asset='' pinned_sha=''
if [ -f "$manifest" ]; then
  pinned_tag=$(jq -er .tag "$manifest") || die "open-pedigree/embedded.json has no tag"
  pinned_asset=$(jq -er .asset "$manifest") || die "open-pedigree/embedded.json has no asset"
  pinned_sha=$(jq -er .sha256 "$manifest") || die "open-pedigree/embedded.json has no sha256"
fi
mode=update
if [ "$1" = --check ]; then
  mode=check
  [ -n "$pinned_tag" ] || die "no open-pedigree/embedded.json to check against"
  tag=$pinned_tag
else
  tag=$1
fi
[[ "$tag" =~ ^open-pedigree-v[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "'$tag' is not an open-pedigree-v<major>.<minor>.<patch> tag"
asset="open-pedigree-dist-${tag#open-pedigree-v}.zip"

work=$(mktemp -d)
staged="$embed/.update-staging"
keep_staged=0
cleanup() {
  rm -rf "${work:?}"
  [ $keep_staged = 1 ] || rm -rf "${staged:?}"
}
trap cleanup EXIT

# Download the release asset, and verify the zip's own bytes against its published .sha256.
url="https://github.com/$REPO/releases/download/$tag"
fetch() {
  curl -fsSL --retry 3 --retry-all-errors -o "$work/$1" "$url/$1" || die "could not download $url/$1"
}
fetch "$asset"
fetch "$asset.sha256"
sha=$(sha256sum "$work/$asset" | cut -d' ' -f1)
published=$(cut -d' ' -f1 "$work/$asset.sha256")
[ "$sha" = "$published" ] || die "$asset is $sha, but its published $asset.sha256 says $published"
unzip -q "$work/$asset" -d "$work/release" || die "could not unzip $asset"
odd=$(cd "$work/release" && find . ! -type f ! -type d)
[ -z "$odd" ] || die "$asset contains entries that aren't plain files or directories: $odd"
for f in "${FILES[@]}"; do
  [ -f "$work/release/dist/$f" ] || die "$asset has no dist/$f"
done
[ -f "$work/release/LICENSE" ] || die "$asset has no LICENSE"

if [ "$mode" = check ]; then
  changed_upstream=0 stray=0 differs=0
  if [ "$pinned_asset" != "$asset" ]; then
    echo "open-pedigree/embedded.json names $pinned_asset, but $tag's asset is $asset" >&2
    differs=1
  fi
  if [ "$sha" != "$pinned_sha" ]; then
    echo "$asset is now $sha, but open-pedigree/embedded.json pins $pinned_sha" >&2
    changed_upstream=1
  fi
  if [ -L "$embed/dist" ] || [ ! -d "$embed/dist" ]; then
    echo "open-pedigree/dist is not a plain directory" >&2
    differs=1
  else
    for f in "${FILES[@]}"; do
      if [ -L "$embed/dist/$f" ] || ! cmp -s "$work/release/dist/$f" "$embed/dist/$f"; then
        echo "open-pedigree/dist/$f differs from $tag's (or is missing)" >&2
        differs=1
      fi
    done
    # Anything else in dist/ (files, symlinks, empty directories, ...) is not from the release.
    allowed=("${FILES[@]}")
    for f in "${FILES[@]}"; do
      [ "$(dirname "$f")" = . ] || allowed+=("$(dirname "$f")")
    done
    while IFS= read -r f; do
      if ! contains "$f" "${allowed[@]}"; then
        echo "open-pedigree/dist/$f is not something this module takes from $tag" >&2
        differs=1
      fi
    done < <(cd "$embed/dist" && find . -mindepth 1 | sed 's|^\./||' | LC_ALL=C sort)
  fi
  if [ -L "$embed/LICENSE" ] || ! cmp -s "$work/release/LICENSE" "$embed/LICENSE"; then
    echo "open-pedigree/LICENSE differs from $tag's (or is missing)" >&2
    differs=1
  fi
  while IFS= read -r entry; do
    if ! contains "$entry" "${EMBED_ENTRIES[@]}"; then
      echo "open-pedigree/$entry doesn't belong in the embed (only ${EMBED_ENTRIES[*]})" >&2
      stray=1
    fi
  done < <(cd "$embed" && find . -mindepth 1 -maxdepth 1 | sed 's|^\./||' | LC_ALL=C sort)
  if [ $changed_upstream = 1 ]; then
    echo "  -> $tag's published asset has changed since it was pinned. Published assets shouldn't change: find out why before re-pinning anything." >&2
  fi
  if [ $stray = 1 ]; then
    echo "  -> remove the entries that don't belong in open-pedigree/." >&2
  fi
  if [ $differs = 1 ] && [ $changed_upstream = 0 ]; then
    echo "  -> run scripts/update-open-pedigree.sh $tag to restore dist/, LICENSE and embedded.json." >&2
  fi
  if [ $((changed_upstream + stray + differs)) -gt 0 ]; then
    die "the embed doesn't match $tag"
  fi
  echo "open-pedigree/ matches $tag ($asset, sha256 $sha)"
  exit 0
fi

if [ "$tag" = "$pinned_tag" ] && [ "$sha" != "$pinned_sha" ]; then
  die "$tag is pinned to $pinned_sha, but its asset is now $sha. Published assets shouldn't change: find out why, and remove open-pedigree/embedded.json first if the new asset really is right."
fi

# Stage the new dist/, LICENSE and manifest before changing anything in open-pedigree/.
rm -rf "${staged:?}"
mkdir -p "$staged/new" "$staged/old"
for f in "${FILES[@]}"; do
  mkdir -p "$staged/new/dist/$(dirname "$f")"
  cp "$work/release/dist/$f" "$staged/new/dist/$f"
done
cp "$work/release/LICENSE" "$staged/new/LICENSE"
printf '{\n  "tag": "%s",\n  "asset": "%s",\n  "sha256": "%s"\n}\n' "$tag" "$asset" "$sha" > "$staged/new/embedded.json"

# Swap them in: move each current one aside, then each new one into place. If any rename
# fails, undo them all; if even that fails, keep the staging directory so nothing is lost.
moved_out=() moved_in=()
rollback() {
  local name ok=1
  for name in "${moved_in[@]}"; do
    rm -rf "${embed:?}/$name" || ok=0
  done
  for name in "${moved_out[@]}"; do
    mv "$staged/old/$name" "$embed/$name" || ok=0
  done
  if [ $ok = 1 ]; then
    die "could not swap in $tag; open-pedigree/ has been put back as it was"
  fi
  keep_staged=1
  die "could not swap in $tag, nor fully put open-pedigree/ back; the previous files are in $staged/old"
}
for name in "${SWAPPED[@]}"; do
  if [ -e "$embed/$name" ] || [ -L "$embed/$name" ]; then
    mv "$embed/$name" "$staged/old/$name" || rollback
    moved_out+=("$name")
  fi
done
for name in "${SWAPPED[@]}"; do
  mv "$staged/new/$name" "$embed/$name" || rollback
  moved_in+=("$name")
done
echo "open-pedigree/ now embeds $tag ($asset, sha256 $sha)"
