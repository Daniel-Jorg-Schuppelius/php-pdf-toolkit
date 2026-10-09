#!/usr/bin/env bash
# Stellt aeltere MuPDF-Versionen fuer die Tests bereit (ADR-0034 E5).
#
# Der Server hat eine aeltere MuPDF-Version als der Entwicklungsrechner und
# der CI-Runner; die JS-Schnittstelle von mutool unterscheidet sich zwischen
# 1.17 (Debian 11), 1.21 (Debian 12) und 1.25 (Debian 13). Die Skripte unter
# data/mupdf laufen deshalb zusaetzlich gegen 1.17 und 1.21.
#
# Die Pakete kommen aus dem Debian-Pool (mutool ist dort gegen libmupdf
# statisch gelinkt), werden gegen feste Pruefsummen geprueft und nur
# entpackt, nicht installiert. Je Version entsteht ein Wrapper
#   <ziel>/bin-<version>/mutool
# der mutool mit passendem LD_LIBRARY_PATH startet; davor in den PATH
# gestellt, sieht die Toolkit-Konfiguration ("path": "mutool") die alte
# Version.
#
# Aufruf: scripts/ci/mupdf-alt.sh <zielordner>

set -euo pipefail

TARGET="${1:?Zielordner fehlt}"
POOL="https://deb.debian.org/debian/pool/main"

# Datei | Adresse | SHA-256 | Zielversion
PACKAGES=(
  "mupdf-tools_1.17.0+ds1-2_amd64.deb|$POOL/m/mupdf/mupdf-tools_1.17.0+ds1-2_amd64.deb|8a676269b3696be9a511c4f7e6a595f2ee7e8d5b93e908d6d3b18f35b2ed32ce|1.17.0"
  "libmujs1_1.1.0-1+deb11u3_amd64.deb|$POOL/m/mujs/libmujs1_1.1.0-1+deb11u3_amd64.deb|463508fbc89a3c518918733c27a9441e3e45c681be4a2b0ee1d330455c21c7c3|1.17.0"
  "mupdf-tools_1.21.1+ds2-1+deb12u1_amd64.deb|$POOL/m/mupdf/mupdf-tools_1.21.1+ds2-1+deb12u1_amd64.deb|b8ae49989425efa41502adb16ad7dba00371234c6ea538c98cb8c0066455f9c2|1.21.1"
  "libmujs2_1.3.2-1_amd64.deb|$POOL/m/mujs/libmujs2_1.3.2-1_amd64.deb|c7d881e18a6390224424a87ca73767cc239336f8e4b3763db06842fc84d4337c|1.21.1"
  "libgumbo1_0.10.1+dfsg-5_amd64.deb|$POOL/g/gumbo-parser/libgumbo1_0.10.1+dfsg-5_amd64.deb|8c0dca6206f6cfe7a36399e89924cc5a71853f72b312271a10d4a5a13e5da8d8|1.21.1"
)

mkdir -p "$TARGET/deb"

for entry in "${PACKAGES[@]}"; do
  IFS='|' read -r file url sum version <<< "$entry"
  deb="$TARGET/deb/$file"
  if [[ ! -f "$deb" ]]; then
    echo "Lade $file"
    curl -fsSL --retry 3 -o "$deb" "$url"
  fi
  echo "$sum  $deb" | sha256sum --check --quiet
  root="$TARGET/root-$version"
  mkdir -p "$root"
  dpkg-deb -x "$deb" "$root"
done

for version in 1.17.0 1.21.1; do
  root="$TARGET/root-$version"
  bin="$TARGET/bin-$version"
  mkdir -p "$bin"
  cat > "$bin/mutool" <<EOF
#!/bin/sh
LD_LIBRARY_PATH="$root/usr/lib/x86_64-linux-gnu\${LD_LIBRARY_PATH:+:\$LD_LIBRARY_PATH}" exec "$root/usr/bin/mutool" "\$@"
EOF
  chmod +x "$bin/mutool"
  echo "$version: $("$bin/mutool" -v 2>&1 | head -1)"
done
