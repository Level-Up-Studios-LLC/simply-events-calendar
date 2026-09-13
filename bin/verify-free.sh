#!/usr/bin/env bash
#
# Verifies a generated FREE build before it is pushed to WordPress.org SVN.
# Runs against the shipped artifact, not the source tree.
#
# Usage: npm run verify:free -- path/to/free-build.zip

set -u

ZIP="${1:-}"

if [ -z "$ZIP" ] || [ ! -f "$ZIP" ]; then
    echo "verify:free: usage: npm run verify:free -- <path-to-zip>" >&2
    exit 2
fi

FAILED=0

fail() {
    echo "verify:free: FAIL - $1" >&2
    FAILED=1
}

# Every file in the archive except the vendored SDK. The SDK legitimately
# defines is__premium_only() and its siblings in class-freemius-abstract.php,
# so scanning it would fail every build. The exclusion is scoped to that one
# directory; all plugin code is still covered.
FILES=$(unzip -Z1 "$ZIP" | grep -vE '(^|/)freemius/' | grep -v '\.mo$' | grep -v '/$')

# 1. No premium directory.
if unzip -Z1 "$ZIP" | grep -q 'includes/pro/'; then
    fail "includes/pro/ is present in the free build"
fi

# 2. No premium gate left in plugin code.
for f in $FILES; do
    if unzip -p "$ZIP" "$f" 2>/dev/null | grep -q 'is__premium_only'; then
        fail "is__premium_only found outside freemius/: $f"
    fi
done

# 3. No secret key anywhere in plugin code.
for f in $FILES; do
    if unzip -p "$ZIP" "$f" 2>/dev/null | grep -q 'sk_'; then
        fail "possible Freemius secret key (sk_) found in: $f"
    fi
done

# 4. Correct text domain in the main file header.
MAIN=$(unzip -Z1 "$ZIP" | grep -E 'simply-events-calendar\.php$' | head -1)
if [ -z "$MAIN" ]; then
    fail "main file simply-events-calendar.php not found in the archive"
else
    DOMAIN=$(unzip -p "$ZIP" "$MAIN" | grep -m1 '^ \* Text Domain:' | sed 's/.*Text Domain:[[:space:]]*//' | tr -d '\r')
    if [ "$DOMAIN" != "simply-events-calendar" ]; then
        fail "Text Domain header is '$DOMAIN', expected 'simply-events-calendar'"
    fi
fi

# 5. A compiled .mo beside every .po.
for po in $(unzip -Z1 "$ZIP" | grep '\.po$'); do
    mo="${po%.po}.mo"
    if ! unzip -Z1 "$ZIP" | grep -qx "$mo"; then
        fail "no compiled .mo for $po"
    fi
done

if [ "$FAILED" -eq 0 ]; then
    echo "verify:free: PASS - $ZIP"
fi

exit "$FAILED"
