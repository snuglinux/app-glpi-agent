#!/usr/bin/env bash
#
# build-rpm.sh - build app-glpi-agent RPM from the current source tree.
#
# Works when placed/run from either:
#   1) repository root: ./build-rpm.sh
#   2) packaging dir   : ./build-rpm.sh
#
# Important:
#   For local builds this script always creates Source0 as:
#     %{name}-%{version}.tar.gz
#   and writes a temporary copy of the spec with local Source0.
#   This avoids the GitHub URL Source0 problem:
#     https://github.com/.../%{version}.tar.gz -> 0.1.13.tar.gz
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TOPDIR="${RPM_TOPDIR:-$HOME/rpmbuild}"
NODEPS=0
SKIP_TESTS=0
CLEAN_TMP=1

usage() {
    cat <<USAGE
Usage:
  $0 [options]

Options:
  --topdir DIR       rpmbuild topdir, default: $TOPDIR
  --nodeps           Pass --nodeps to rpmbuild
  --skip-tests       Skip PHP/Bash syntax checks
  --keep-tmp         Keep temporary source directory
  -h, --help         Show this help

Examples:
  ./build-rpm.sh
  ./build-rpm.sh --nodeps
  ./build-rpm.sh --topdir /root/rpmbuild
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --topdir)
            TOPDIR="$2"
            shift 2
            ;;
        --nodeps)
            NODEPS=1
            shift
            ;;
        --skip-tests)
            SKIP_TESTS=1
            shift
            ;;
        --keep-tmp)
            CLEAN_TMP=0
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "❌ Невідомий параметр: $1" >&2
            usage
            exit 1
            ;;
    esac
done

# Detect repository root and spec file.
if [[ -f "$SCRIPT_DIR/packaging/app-glpi-agent.spec" ]]; then
    PROJECT_ROOT="$SCRIPT_DIR"
    SPEC_FILE="$SCRIPT_DIR/packaging/app-glpi-agent.spec"
elif [[ -f "$SCRIPT_DIR/app-glpi-agent.spec" && "$(basename "$SCRIPT_DIR")" == "packaging" ]]; then
    PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
    SPEC_FILE="$SCRIPT_DIR/app-glpi-agent.spec"
else
    echo "❌ Не знайдено app-glpi-agent.spec." >&2
    echo "   Запусти скрипт з кореня репозиторію або з каталогу packaging/." >&2
    exit 1
fi

if ! command -v rpmbuild >/dev/null 2>&1; then
    echo "❌ rpmbuild не знайдено." >&2
    echo
    if command -v pacman >/dev/null 2>&1; then
        echo "Для Arch/SnugLinux встанови:" >&2
        echo "  sudo pacman -S rpm-tools" >&2
    elif command -v yum >/dev/null 2>&1; then
        echo "Для ClearOS/CentOS встанови:" >&2
        echo "  yum install -y rpm-build" >&2
    elif command -v dnf >/dev/null 2>&1; then
        echo "Для Fedora/RHEL встанови:" >&2
        echo "  dnf install -y rpm-build" >&2
    fi
    exit 1
fi

NAME="$(awk '$1 == "Name:" { print $2; exit }' "$SPEC_FILE")"
VERSION="$(awk '$1 == "Version:" { print $2; exit }' "$SPEC_FILE")"
SOURCE0_ORIGINAL="$(awk '$1 == "Source0:" { print $2; exit }' "$SPEC_FILE")"
SPEC_BASENAME="$(basename "$SPEC_FILE")"

if [[ -z "$NAME" || -z "$VERSION" ]]; then
    echo "❌ Не вдалося прочитати Name/Version зі spec-файла." >&2
    exit 1
fi

LOCAL_TARBALL_NAME="${NAME}-${VERSION}.tar.gz"

SOURCES_DIR="$TOPDIR/SOURCES"
SPECS_DIR="$TOPDIR/SPECS"
RPMS_DIR="$TOPDIR/RPMS"
SRPMS_DIR="$TOPDIR/SRPMS"
BUILD_DIR="$TOPDIR/BUILD"
BUILDROOT_DIR="$TOPDIR/BUILDROOT"

TMP_PARENT="$(mktemp -d "/tmp/${NAME}-rpmbuild.XXXXXX")"
SOURCE_DIR="$TMP_PARENT/${NAME}-${VERSION}"
SOURCE_PATH="$SOURCES_DIR/$LOCAL_TARBALL_NAME"
SPEC_WORK="$SPECS_DIR/$SPEC_BASENAME"

cleanup() {
    if [[ "$CLEAN_TMP" -eq 1 ]]; then
        rm -rf "$TMP_PARENT"
    else
        echo "ℹ Тимчасовий каталог залишено: $TMP_PARENT"
    fi
}
trap cleanup EXIT

print_header() {
    echo "============================================================"
    echo " Build RPM: ${NAME} ${VERSION}"
    echo "============================================================"
    echo "SCRIPT_DIR        : $SCRIPT_DIR"
    echo "PROJECT_ROOT      : $PROJECT_ROOT"
    echo "SPEC_FILE         : $SPEC_FILE"
    echo "TOPDIR            : $TOPDIR"
    echo "SOURCE0 original  : ${SOURCE0_ORIGINAL:-<none>}"
    echo "SOURCE0 local     : $LOCAL_TARBALL_NAME"
    echo "SOURCE            : $SOURCE_PATH"
    echo "SPEC copy         : $SPEC_WORK"
    echo "NODEPS            : $NODEPS"
    echo "SKIP_TESTS        : $SKIP_TESTS"
    echo "============================================================"
}

verify_spec_dependencies() {
    echo
    echo "🔎 Перевіряю RPM-залежності у spec ..."

    if grep -Eq '^Requires:[[:space:]]+glpi-agent[[:space:]]*>=[[:space:]]*1\.17([[:space:]]|$)' "$SPEC_FILE"; then
        echo "✅ Requires: glpi-agent >= 1.17"
    else
        echo "❌ У spec має бути залежність: Requires: glpi-agent >= 1.17" >&2
        exit 1
    fi
}

run_syntax_tests() {
    echo
    echo "🔎 Перевіряю PHP/Bash синтаксис ..."

    if command -v php >/dev/null 2>&1; then
        while IFS= read -r -d '' file; do
            php -l "$file" >/dev/null
        done < <(find "$PROJECT_ROOT" \
            -path "$PROJECT_ROOT/.git" -prune -o \
            -type f -name '*.php' -print0)
        echo "✅ PHP syntax OK"
    else
        echo "⚠ php не знайдено, пропускаю PHP syntax check."
    fi

    local bash_files=()
    [[ -f "$PROJECT_ROOT/install-app-tree-dev.sh" ]] && bash_files+=("$PROJECT_ROOT/install-app-tree-dev.sh")
    [[ -f "$PROJECT_ROOT/deploy/install" ]] && bash_files+=("$PROJECT_ROOT/deploy/install")
    [[ -f "$PROJECT_ROOT/deploy/glpi-agent-helper.sh" ]] && bash_files+=("$PROJECT_ROOT/deploy/glpi-agent-helper.sh")
    [[ -f "$PROJECT_ROOT/build-rpm.sh" ]] && bash_files+=("$PROJECT_ROOT/build-rpm.sh")
    [[ -f "$PROJECT_ROOT/packaging/build-rpm.sh" ]] && bash_files+=("$PROJECT_ROOT/packaging/build-rpm.sh")

    local file
    for file in "${bash_files[@]}"; do
        bash -n "$file"
    done
    echo "✅ Bash syntax OK"
}

prepare_rpmbuild_tree() {
    echo
    echo "📁 Готую rpmbuild дерево ..."
    mkdir -p "$SOURCES_DIR" "$SPECS_DIR" "$RPMS_DIR" "$SRPMS_DIR" "$BUILD_DIR" "$BUILDROOT_DIR"
}

verify_source_tree() {
    echo
    echo "🔎 Перевіряю обов'язкові файли у source tree ..."

    local required_files=(
        "deploy/install"
        "deploy/glpi-agent-helper.sh"
        "libraries/Glpi_Agent.php"
    )

    local missing=0
    local file
    for file in "${required_files[@]}"; do
        if [[ -f "$SOURCE_DIR/$file" ]]; then
            echo "✅ $file"
        else
            echo "❌ Не знайдено у Source0: $file" >&2
            missing=1
        fi
    done

    if [[ "$missing" -ne 0 ]]; then
        echo "❌ Source0 неповний. RPM не збираю." >&2
        exit 1
    fi
}

create_source_tarball() {
    echo
    echo "📦 Створюю Source0 tarball ..."

    mkdir -p "$SOURCE_DIR"

    (
        cd "$PROJECT_ROOT"
        tar \
            --exclude='./.git' \
            --exclude='./.github' \
            --exclude='./rpmbuild' \
            --exclude='./build' \
            --exclude='./dist' \
            --exclude='./tmp' \
            --exclude='./*.rpm' \
            --exclude='./*.src.rpm' \
            --exclude='./*.tar.gz' \
            --exclude='./*.tgz' \
            --exclude='./*.zip' \
            --exclude='./*.log' \
            -cf - .
    ) | (
        cd "$SOURCE_DIR"
        tar -xf -
    )

    verify_source_tree

    tar -C "$TMP_PARENT" -czf "$SOURCE_PATH" "${NAME}-${VERSION}"

    # Copy spec and force local Source0.
    # We do this in the rpmbuild SPECS copy only, so the git working tree is not modified.
    awk -v local_source="$LOCAL_TARBALL_NAME" '
        BEGIN { replaced = 0 }
        /^Source0:[[:space:]]*/ {
            print "Source0:        " local_source
            replaced = 1
            next
        }
        { print }
        END {
            if (replaced == 0)
                print "Source0:        " local_source
        }
    ' "$SPEC_FILE" > "$SPEC_WORK"

    echo "✅ Source: $SOURCE_PATH"
    echo "✅ Spec  : $SPEC_WORK"

    echo
    echo "🔎 Перевіряю Source0 у робочому spec:"
    grep -n '^Source0:' "$SPEC_WORK" || true
}

build_rpm() {
    echo
    echo "🏗️ Запускаю rpmbuild ..."

    local args=(-ba --define "_topdir $TOPDIR")
    if [[ "$NODEPS" -eq 1 ]]; then
        args+=(--nodeps)
    fi

    rpmbuild "${args[@]}" "$SPEC_WORK"
}

verify_built_rpm() {
    echo
    echo "🔎 Перевіряю зібраний RPM ..."

    local rpm_file
    rpm_file="$(find "$RPMS_DIR" -type f -name "${NAME}-${VERSION}-*.noarch.rpm" -print | sort | tail -n 1 || true)"

    if [[ -z "$rpm_file" ]]; then
        echo "❌ RPM не знайдено після збірки." >&2
        exit 1
    fi

    echo "RPM для перевірки: $rpm_file"

    if ! command -v rpm >/dev/null 2>&1; then
        echo "⚠ Команду rpm не знайдено, пропускаю перевірку вмісту пакета."
        return 0
    fi

    local listing
    listing="$(rpm -qplv "$rpm_file")"

    echo
    echo "🔎 Важливі файли у RPM:"
    printf '%s\n' "$listing" | grep -E '/usr/sbin/clearos-glpi-agent-helper|/usr/clearos/apps/glpi_agent/deploy/install|/usr/clearos/apps/glpi_agent/deploy/glpi-agent-helper.sh' || true

    if ! printf '%s\n' "$listing" | grep -q '/usr/sbin/clearos-glpi-agent-helper$'; then
        echo "❌ У RPM немає /usr/sbin/clearos-glpi-agent-helper" >&2
        exit 1
    fi

    if ! printf '%s\n' "$listing" | grep -q '/usr/clearos/apps/glpi_agent/deploy/install$'; then
        echo "❌ У RPM немає /usr/clearos/apps/glpi_agent/deploy/install" >&2
        exit 1
    fi

    if ! printf '%s\n' "$listing" | awk '/\/usr\/sbin\/clearos-glpi-agent-helper$/ { if ($1 ~ /^-rwxr-xr-x/) found=1 } END { exit found ? 0 : 1 }'; then
        echo "❌ Helper у RPM не має прав 0755 (-rwxr-xr-x)." >&2
        exit 1
    fi

    if ! printf '%s\n' "$listing" | awk '/\/usr\/clearos\/apps\/glpi_agent\/deploy\/install$/ { if ($1 ~ /^-rwxr-xr-x/) found=1 } END { exit found ? 0 : 1 }'; then
        echo "❌ deploy/install у RPM не має прав 0755 (-rwxr-xr-x)." >&2
        exit 1
    fi

    echo "✅ RPM містить helper і deploy/install з правильними правами."
}

show_result() {
    echo
    echo "============================================================"
    echo " Результат збірки"
    echo "============================================================"

    echo "RPM:"
    find "$RPMS_DIR" -type f -name "${NAME}-${VERSION}-*.rpm" -print | sort || true

    echo
    echo "SRPM:"
    find "$SRPMS_DIR" -type f -name "${NAME}-${VERSION}-*.src.rpm" -print | sort || true

    echo
    echo "Для встановлення на ClearOS:"
    echo "  yum localinstall /path/to/${NAME}-${VERSION}-*.noarch.rpm"
    echo
    echo "Після встановлення можна швидко перевірити:"
    echo "  ls -l /usr/sbin/clearos-glpi-agent-helper"
    echo "  ls -l /usr/clearos/apps/glpi_agent/deploy/install"
    echo "  sudo -n /usr/sbin/clearos-glpi-agent-helper check-certificate"
    echo "============================================================"
}

print_header
verify_spec_dependencies

if [[ "$SKIP_TESTS" -eq 0 ]]; then
    run_syntax_tests
else
    echo
    echo "⚠ Перевірки пропущено (--skip-tests)."
fi

prepare_rpmbuild_tree
create_source_tarball
build_rpm
verify_built_rpm
show_result
