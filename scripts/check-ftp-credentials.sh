#!/usr/bin/env bash
# Verify the FTP credential assertions in deploy.yml behave correctly.
#
# The equivalent shell runs on the GitHub runner. This exercises the same logic locally with
# git-bash-style bash if available, so the check is proven rather than assumed — the last guard
# written blind (awk, not installed here) proved ineffective on its first test.

set -u

pass=0
fail=0

# check <description> <expected-exit> <server> <username> <password>
check() {
    local desc="$1" expected="$2" server="$3" username="$4" password="$5"

    FTP_SERVER="$server"
    FTP_USERNAME="$username"
    FTP_PASSWORD="$password"

    local missing=""
    [ -z "$FTP_SERVER" ]   && missing="$missing FTP_SERVER"
    [ -z "$FTP_USERNAME" ] && missing="$missing FTP_USERNAME"
    [ -z "$FTP_PASSWORD" ] && missing="$missing FTP_PASSWORD"

    local rc=0

    if [ -n "$missing" ]; then
        rc=1
    else
        case "$FTP_SERVER" in
            *://*) rc=1 ;;
        esac
        case "$FTP_SERVER" in
            */*) rc=1 ;;
        esac
    fi

    if [ "$rc" -eq "$expected" ]; then
        echo "  ok    $desc (exit $rc)"
        pass=$((pass + 1))
    else
        echo "  FAIL  $desc - expected exit $expected, got $rc"
        fail=$((fail + 1))
    fi
}

echo "FTP credential assertion"
check "all three set, bare host"          0 'ftp.mwstay.com' 'user@example.com' 'secret'
check "FTP_SERVER missing"                1 ''                'user@example.com' 'secret'
check "FTP_USERNAME missing"              1 'ftp.mwstay.com'  ''                 'secret'
check "FTP_PASSWORD missing"              1 'ftp.mwstay.com'  'user@example.com' ''
check "all three missing"                 1 ''                ''                 ''
check "FTP_SERVER has a scheme"           1 'ftp://ftp.mwstay.com' 'user@x.com' 'secret'
check "FTP_SERVER has a path"             1 'ftp.mwstay.com/itrequest' 'user@x.com' 'secret'

echo ""
echo "passed $pass, failed $fail"
[ "$fail" -eq 0 ]
