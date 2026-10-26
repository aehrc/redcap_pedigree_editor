#!/usr/bin/env bash
# Embeds a pinned open-pedigree release build in open-pedigree/, or checks the embed.
#
#   scripts/update-open-pedigree.sh <tag>   e.g. open-pedigree-v1.4.6
#       Downloads that release's open-pedigree-dist-<version>.zip and its .sha256 (no token
#       needed - aehrc/open-pedigree is public), verifies the zip against it, and replaces
#       open-pedigree/dist/ with the files this module uses, plus open-pedigree/LICENSE, and
#       records the release in open-pedigree/embedded.json. Everything is downloaded and
#       verified before open-pedigree/ changes, it refuses to start if open-pedigree/ has
#       anything git couldn't restore, and it finishes by running the same comparison as
#       --check. A failure part way prints the git commands that undo it. Refuses to re-pin a tag that is
#       already pinned to a different sha256 - a published asset should never change.
#
#   scripts/update-open-pedigree.sh --check
#       Used by CI: downloads the release named in open-pedigree/embedded.json and fails if
#       the zip's sha256 isn't the pinned one, or if open-pedigree/ isn't exactly that
#       release's files plus localEditor.html and embedded.json (any changed, missing, extra
#       or symlinked file or directory).
#
# open-pedigree/localEditor.html is this module's own host page and is never touched.
# Runs in a git checkout (scripts/ isn't in release zips). Needs bash, git, curl, sha256sum,
# unzip (with zipinfo), GNU diff and jq.
set -euo pipefail

REPO=aehrc/open-pedigree
# The files this module uses from the release's dist/.
FILES=(
  pedigree.min.js
  pedigree.min.js.LICENSE.txt
  defaultQuestionnaire.json
  assets/open-pedigree-icon.png
)

usage() {
  echo "usage: $0 <open-pedigree-vX.Y.Z> | --check" >&2
  exit 2
}

die() {
  echo "update-open-pedigree: $*" >&2
  exit 1
}

[ $# -eq 1 ] || usage
for cmd in git curl sha256sum unzip zipinfo diff jq; do
  command -v "$cmd" > /dev/null || die "needs $cmd, which isn't installed"
done

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
embed="$root/open-pedigree"
manifest="$embed/embedded.json"

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
  # Modified, untracked and ignored files all count: anything git couldn't restore.
  [ -z "$(git -C "$root" status --porcelain --ignored -- open-pedigree)" ] \
    || die "open-pedigree/ has uncommitted, untracked or ignored files; commit or remove them first, so a failed update can be undone with git"
fi
[[ "$tag" =~ ^open-pedigree-v[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "'$tag' is not an open-pedigree-v<major>.<minor>.<patch> tag"
asset="open-pedigree-dist-${tag#open-pedigree-v}.zip"

work=$(mktemp -d)
trap 'rm -rf "${work:?}"' EXIT

# Download the release asset, and verify the zip's own bytes against its published .sha256.
url="https://github.com/$REPO/releases/download/$tag"
fetch() {
  curl -fsSL --retry 3 --retry-all-errors -o "$work/$1" "$url/$1" || die "could not download $url/$1"
}
fetch "$asset"
fetch "$asset.sha256"
sha=$(sha256sum "$work/$asset" | cut -d' ' -f1)
published=$(head -n 1 "$work/$asset.sha256" | tr -d '\r' | cut -d' ' -f1 | tr 'A-F' 'a-f')
[ "$sha" = "$published" ] || die "$asset is $sha, but its published $asset.sha256 says $published"

# Only plain files and directories, with no absolute or .. paths, before anything is extracted.
entries=$(zipinfo -1 "$work/$asset") || die "could not read $asset"
# Entry lines start with a 10-character mode string (e.g. -rw-r--r--); header and footer lines don't.
types=$(zipinfo "$work/$asset" | awk 'length($1) == 10 { print substr($1, 1, 1) }') \
  || die "could not read the entry types in $asset"
[ "$(wc -l <<< "$entries")" = "$(wc -l <<< "$types")" ] || die "could not read the entry types in $asset"
grep -qv '^[-d]$' <<< "$types" && die "$asset contains entries that aren't plain files or directories"
grep -qE '(^/|(^|/)\.\.(/|$))' <<< "$entries" && die "$asset contains an absolute or '..' path"
unzip -q "$work/$asset" -d "$work/release" || die "could not unzip $asset"

# The release's part of the tree open-pedigree/ should hold.
expected="$work/expected"
for f in "${FILES[@]}"; do
  [ -f "$work/release/dist/$f" ] || die "$asset has no dist/$f"
  mkdir -p "$expected/dist/$(dirname "$f")"
  cp "$work/release/dist/$f" "$expected/dist/$f"
done
[ -f "$work/release/LICENSE" ] || die "$asset has no LICENSE"
cp "$work/release/LICENSE" "$expected/LICENSE"

# Succeeds only if open-pedigree/ is exactly the release's part plus this module's own
# localEditor.html and embedded.json (both regular files), at every depth; prints why not.
compare_embed() {
  local f line differs=0
  for f in localEditor.html embedded.json; do
    if [ -L "$embed/$f" ] || [ ! -f "$embed/$f" ]; then
      echo "open-pedigree/$f is missing or not a regular file" >&2
      differs=1
    else
      cp "$embed/$f" "$expected/$f"
    fi
  done
  if ! diff -rq --no-dereference "$expected" "$embed" > "$work/diff"; then
    while IFS= read -r line; do
      line=${line//"$expected"/$tag}
      echo "${line//"$embed"/open-pedigree}" >&2
    done < "$work/diff"
    differs=1
  fi
  rm -f "$expected/localEditor.html" "$expected/embedded.json"
  if [ $differs = 1 ]; then
    echo "open-pedigree/ isn't exactly $tag's files plus localEditor.html and embedded.json." >&2
    return 1
  fi
}

if [ "$mode" = check ]; then
  problems=0
  if [ "$pinned_asset" != "$asset" ]; then
    echo "open-pedigree/embedded.json names $pinned_asset, but $tag's asset is $asset" >&2
    problems=1
  fi
  if [ "$sha" != "$pinned_sha" ]; then
    echo "$asset is now $sha, but open-pedigree/embedded.json pins $pinned_sha." >&2
    echo "Published assets shouldn't change: find out why before re-pinning anything." >&2
    problems=1
  fi
  compare_embed || problems=1
  [ $problems = 0 ] || die "the embed doesn't match $tag"
  echo "open-pedigree/ matches $tag ($asset, sha256 $sha)"
  exit 0
fi

if [ "$tag" = "$pinned_tag" ] && [ "$sha" != "$pinned_sha" ]; then
  die "$tag is pinned to $pinned_sha, but its asset is now $sha. Published assets shouldn't change: find out why, and remove open-pedigree/embedded.json first if the new asset really is right."
fi

# Everything is verified and staged; now replace dist/, LICENSE and embedded.json. A failure
# from here on is undone with git, since open-pedigree/ had nothing git couldn't restore.
restore="git -C '$root' checkout -- open-pedigree && git -C '$root' clean -fdx -- open-pedigree"
on_exit() {
  rm -rf "${work:?}"
  [ "$done" = 1 ] || echo "update-open-pedigree: failed part way; to restore open-pedigree/, run: $restore" >&2
}
trap on_exit EXIT
done=0
rm -rf "${embed:?}/dist"
cp -R "$expected/dist" "$embed/dist"
cp "$expected/LICENSE" "$embed/LICENSE"
printf '{\n  "tag": "%s",\n  "asset": "%s",\n  "sha256": "%s"\n}\n' "$tag" "$asset" "$sha" > "$manifest"
compare_embed || die "the updated open-pedigree/ isn't what --check expects"
done=1
echo "open-pedigree/ now embeds $tag ($asset, sha256 $sha)"
