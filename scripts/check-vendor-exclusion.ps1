# Verify the vendor.zip exclusion guard in deploy.yml.
#
# The guard is a `grep -qE '^[[:space:]]*vendor\.zip[[:space:]]*$'` in the workflow. This runs the
# equivalent locally so the pattern is proven rather than assumed — the previous version of the
# guard was written blind (awk is not on this machine) and proved ineffective the first time it
# was tested.

$workflow = '.github/workflows/deploy.yml'
$pattern = '^\s*vendor\.zip\s*$'

Write-Output '=== 1. the real workflow: the guard must NOT fire ==='
$hits = Select-String -Path $workflow -Pattern $pattern

if ($hits) {
    Write-Output "  FALSE POSITIVE - would block every deploy:"
    $hits | ForEach-Object { Write-Output "    line $($_.LineNumber): $($_.Line)" }
    exit 1
}

Write-Output '  no match -> guard passes, correct'

Write-Output ''
Write-Output '=== 2. an exclude list that DOES contain it: the guard must fire ==='

$bad = Join-Path $env:TEMP 'bad-exclude.txt'
@(
    'exclude: |',
    '  **/.git*',
    '  **/vendor/**',
    '  **/.env',
    '  vendor.zip',
    '  phpunit.xml'
) | Set-Content -Path $bad -Encoding ascii

$caught = Select-String -Path $bad -Pattern $pattern
Remove-Item $bad -Force

if (-not $caught) {
    Write-Output '  GUARD BROKEN - a bad exclude list would not be caught'
    exit 1
}

Write-Output "  matched line $($caught.LineNumber) -> guard fails the deploy, correct"

Write-Output ''
Write-Output 'Both directions verified. The guard fires when it should and not when it should not.'
