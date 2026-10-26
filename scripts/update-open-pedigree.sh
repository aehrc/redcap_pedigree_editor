#!/usr/bin/env bash
# Embeds a pinned open-pedigree release build in open-pedigree/, or checks the embed.
#
#   scripts/update-open-pedigree.sh <tag>   e.g. open-pedigree-v1.4.6
#       Downloads that release's open-pedigree-dist-<version>.zip and its .sha256 (no token
#       needed - aehrc/open-pedigree is public), verifies the zip against it, and replaces
#       open-pedigree/dist/ with the files this module uses, plus open-pedigree/LICENSE, and
#       records the release in open-pedigree/embedded.json. Everything is downloaded, verified
#       and staged before anything in open-pedigree/ changes; the swap is then a few renames,
#       and the old dist/ is put back if one of them fails.
#
#   scripts/update-open-pedigree.sh --check
#       Used by CI: downloads the release named in open-pedigree/embedded.json and fails if
#       the zip's sha256 isn't the pinned one, if open-pedigree/dist/ or LICENSE differ from it
#       in any way (changed, missing or extra files), or if open-pedigree/ holds anything else.
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
mode=update
if [ "$1" = --check ]; then
  mode=check
  [ -f "$manifest" ] || die "no open-pedigree/embedded.json to check against"
  tag=$(jq -er .tag "$manifest") || die "open-pedigree/embedded.json has no tag"
  pinned_asset=$(jq -er .asset "$manifest") || die "open-pedigree/embedded.json has no asset"
  pinned_sha=$(jq -er .sha256 "$manifest") || die "open-pedigree/embedded.json has no sha256"
else
  tag=$1
fi
[[ "$tag" =~ ^open-pedigree-v[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "'$tag' is not an open-pedigree-v<major>.<minor>.<patch> tag"
asset="open-pedigree-dist-${tag#open-pedigree-v}.zip"

work=$(mktemp -d)
staged="$embed/.update-staging"
cleanup() {
  rm -rf "${work:?}" "${staged:?}"
}
trap cleanup EXIT

# Download the release asset, and verify the zip's own bytes against its published .sha256.
url="https://github.com/$REPO/releases/download/$tag"
curl -fsSL -o "$work/$asset" "$url/$asset" || die "could not download $url/$asset"
curl -fsSL -o "$work/$asset.sha256" "$url/$asset.sha256" || die "could not download $url/$asset.sha256"
sha=$(sha256sum "$work/$asset" | cut -d' ' -f1)
published=$(cut -d' ' -f1 "$work/$asset.sha256")
[ "$sha" = "$published" ] || die "$asset is $sha, but its published $asset.sha256 says $published"
unzip -q "$work/$asset" -d "$work/release" || die "could not unzip $asset"
for f in "${FILES[@]}"; do
  [ -f "$work/release/dist/$f" ] || die "$asset has no dist/$f"
done
[ -f "$work/release/LICENSE" ] || die "$asset has no LICENSE"

if [ "$mode" = check ]; then
  problems=0
  if [ "$pinned_asset" != "$asset" ]; then
    echo "open-pedigree/embedded.json names $pinned_asset, but $tag's asset is $asset" >&2
    problems=1
  fi
  if [ "$sha" != "$pinned_sha" ]; then
    echo "$asset is $sha, but open-pedigree/embedded.json pins $pinned_sha" >&2
    problems=1
  fi
  for f in "${FILES[@]}"; do
    if [ -L "$embed/dist/$f" ] || ! cmp -s "$work/release/dist/$f" "$embed/dist/$f"; then
      echo "open-pedigree/dist/$f differs from $tag's (or is missing)" >&2
      problems=1
    fi
  done
  if [ -L "$embed/LICENSE" ] || ! cmp -s "$work/release/LICENSE" "$embed/LICENSE"; then
    echo "open-pedigree/LICENSE differs from $tag's (or is missing)" >&2
    problems=1
  fi
  # Anything else in dist/ (files, symlinks, ...) is not from the release.
  while IFS= read -r f; do
    if ! contains "$f" "${FILES[@]}"; then
      echo "open-pedigree/dist/$f is not a file this module takes from $tag" >&2
      problems=1
    fi
  done < <(cd "$embed/dist" && find . ! -type d | sed 's|^\./||' | LC_ALL=C sort)
  while IFS= read -r entry; do
    if ! contains "$entry" "${EMBED_ENTRIES[@]}"; then
      echo "open-pedigree/$entry doesn't belong in the embed (only ${EMBED_ENTRIES[*]})" >&2
      problems=1
    fi
  done < <(cd "$embed" && find . -mindepth 1 -maxdepth 1 | sed 's|^\./||' | LC_ALL=C sort)
  if [ $problems = 1 ]; then
    die "the embed doesn't match $tag - run scripts/update-open-pedigree.sh $tag to restore it"
  fi
  echo "open-pedigree/ matches $tag ($asset, sha256 $sha)"
  exit 0
fi

# Stage the new dist/, LICENSE and manifest before changing anything in open-pedigree/.
rm -rf "${staged:?}"
for f in "${FILES[@]}"; do
  mkdir -p "$staged/dist/$(dirname "$f")"
  cp "$work/release/dist/$f" "$staged/dist/$f"
done
cp "$work/release/LICENSE" "$staged/LICENSE"
printf '{\n  "tag": "%s",\n  "asset": "%s",\n  "sha256": "%s"\n}\n' "$tag" "$asset" "$sha" > "$staged/embedded.json"

# Swap them in; if the new dist/ can't be moved into place, put the old one back.
if [ -e "$embed/dist" ]; then
  mv "$embed/dist" "$staged/dist.old"
fi
if ! mv "$staged/dist" "$embed/dist"; then
  [ -e "$staged/dist.old" ] && mv "$staged/dist.old" "$embed/dist"
  die "could not move the new dist/ into place; the old one has been restored"
fi
mv "$staged/LICENSE" "$embed/LICENSE"
mv "$staged/embedded.json" "$manifest"
echo "open-pedigree/ now embeds $tag ($asset, sha256 $sha)"
