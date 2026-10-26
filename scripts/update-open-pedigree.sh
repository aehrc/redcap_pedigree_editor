#!/usr/bin/env bash
# Embeds a pinned open-pedigree release build in open-pedigree/, or checks the embed.
#
#   scripts/update-open-pedigree.sh <tag>   e.g. open-pedigree-v1.4.6
#       Downloads that release's open-pedigree-dist-<version>.zip and its .sha256 (no token
#       needed - aehrc/open-pedigree is public), verifies the checksum, and replaces
#       open-pedigree/dist/ with the files this module uses, plus open-pedigree/LICENSE.
#       open-pedigree/embedded.json is written last, so any failure leaves the embed as it was.
#
#   scripts/update-open-pedigree.sh --check
#       Used by CI: downloads the release named in open-pedigree/embedded.json and fails if
#       the asset's sha256 differs from the manifest's, or open-pedigree/dist/ or LICENSE
#       differ from it in any way (changed, missing or extra files).
#
# open-pedigree/localEditor.html is this module's own host page and is never touched.
# Needs bash, curl, sha256sum and unzip (and jq for --check).
set -euo pipefail

REPO=aehrc/open-pedigree
# The files this module uses from the release's dist/.
FILES=(
  pedigree.min.js
  pedigree.min.js.LICENSE.txt
  defaultQuestionnaire.json
  assets/open-pedigree-icon.png
)

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

[ $# -eq 1 ] || usage
mode=update
if [ "$1" = --check ]; then
  mode=check
  [ -f "$manifest" ] || die "no open-pedigree/embedded.json to check against"
  tag=$(jq -er .tag "$manifest") || die "open-pedigree/embedded.json has no tag"
  pinned_sha=$(jq -er .sha256 "$manifest") || die "open-pedigree/embedded.json has no sha256"
else
  tag=$1
fi
[[ "$tag" =~ ^open-pedigree-v[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "'$tag' is not an open-pedigree-v<major>.<minor>.<patch> tag"
asset="open-pedigree-dist-${tag#open-pedigree-v}.zip"

work=$(mktemp -d)
trap 'rm -rf "${work:?}"' EXIT

# Download and verify the release asset.
url="https://github.com/$REPO/releases/download/$tag"
curl -fsSL -o "$work/$asset" "$url/$asset" || die "could not download $url/$asset"
curl -fsSL -o "$work/$asset.sha256" "$url/$asset.sha256" || die "could not download $url/$asset.sha256"
(cd "$work" && sha256sum --check --quiet "$asset.sha256") || die "$asset does not match its published $asset.sha256"
sha=$(cut -d' ' -f1 "$work/$asset.sha256")
unzip -q "$work/$asset" -d "$work/release" || die "could not unzip $asset"
for f in "${FILES[@]}" ; do
  [ -f "$work/release/dist/$f" ] || die "$asset has no dist/$f"
done
[ -f "$work/release/LICENSE" ] || die "$asset has no LICENSE"

if [ "$mode" = check ]; then
  problems=0
  if [ "$sha" != "$pinned_sha" ]; then
    echo "$asset is $sha, but open-pedigree/embedded.json pins $pinned_sha" >&2
    problems=1
  fi
  for f in "${FILES[@]}"; do
    if ! cmp -s "$work/release/dist/$f" "$embed/dist/$f"; then
      echo "open-pedigree/dist/$f differs from $tag's (or is missing)" >&2
      problems=1
    fi
  done
  if ! cmp -s "$work/release/LICENSE" "$embed/LICENSE"; then
    echo "open-pedigree/LICENSE differs from $tag's (or is missing)" >&2
    problems=1
  fi
  while IFS= read -r f; do
    keep=0
    for want in "${FILES[@]}"; do
      [ "$f" = "$want" ] && keep=1
    done
    if [ $keep = 0 ]; then
      echo "open-pedigree/dist/$f is not a file this module takes from $tag" >&2
      problems=1
    fi
  done < <(cd "$embed/dist" && find . -type f | sed 's|^\./||' | LC_ALL=C sort)
  if [ $problems = 1 ]; then
    die "the embed doesn't match $tag - run scripts/update-open-pedigree.sh $tag to restore it"
  fi
  echo "open-pedigree/ matches $tag ($asset, sha256 $sha)"
  exit 0
fi

# Stage the new dist/ next to the old one, then swap them over.
staged="$embed/.dist.new"
rm -rf "${staged:?}"
for f in "${FILES[@]}"; do
  mkdir -p "$staged/$(dirname "$f")"
  cp "$work/release/dist/$f" "$staged/$f"
done
rm -rf "${embed:?}/dist"
mv "$staged" "$embed/dist"
cp "$work/release/LICENSE" "$embed/LICENSE"
printf '{\n  "tag": "%s",\n  "asset": "%s",\n  "sha256": "%s"\n}\n' "$tag" "$asset" "$sha" > "$manifest.new"
mv "$manifest.new" "$manifest"
echo "open-pedigree/ now embeds $tag ($asset, sha256 $sha)"
