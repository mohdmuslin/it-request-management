# Local FTP login and landing-directory check.
#
# Asks for the password interactively (never stored, never printed, never written to a file),
# then tries a PLAIN FTP login to the host and lists the directory. This separates problems that
# otherwise all look the same from GitHub:
#
#   - login SUCCEEDS here but GitHub says 530       -> the GitHub secret is wrong or stale
#   - login FAILS here too                          -> the FTP account or password is wrong
#   - login succeeds but the listing is NOT the app -> the FTP account's root is wrong, and the
#                                                      deploy would upload into the wrong place
#
# Plain FTP, not FTPS, matching the workflow: the host's TLS data socket fails during transfer
# with `SSL alert number 50`, so the workflow uses plain FTP deliberately. Testing over TLS here
# would prove something the deploy does not do.
#
# Run:  powershell -NoProfile -ExecutionPolicy Bypass -File scripts\ftp-login-check.ps1
#
# The password is prompted for rather than passed as an argument, because an argument lands in
# the PowerShell history file and in the process list.

param(
    [string]$Server = 'ftp.mwstay.com',
    [string]$Username = ''
)

Write-Host ''
Write-Host 'IT Request Management - FTP login check' -ForegroundColor White
Write-Host ''

if (-not $Username) {
    $Username = Read-Host -Prompt 'FTP username'
}

Write-Host ''
Write-Host "Server   : $Server" -ForegroundColor Cyan
Write-Host "Username : $Username" -ForegroundColor Cyan
Write-Host ''

$secure = Read-Host -Prompt 'FTP password (input hidden)' -AsSecureString
$bstr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)

try {
    $password = [System.Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)

    # ---- 1. Does the password authenticate, and what is the account's root? ----
    Write-Host ''
    Write-Host '--- login test (plain FTP, port 21) ---' -ForegroundColor Yellow

    $req = [System.Net.FtpWebRequest]::Create("ftp://$Server/")
    $req.Method = [System.Net.WebRequestMethods+Ftp]::ListDirectory
    $req.Credentials = New-Object System.Net.NetworkCredential($Username, $password)
    $req.UsePassive = $true
    $req.UseBinary = $true
    $req.EnableSsl = $false          # plain FTP, matching the workflow
    $req.KeepAlive = $false
    $req.Timeout = 30000

    try {
        $resp = $req.GetResponse()
        $sr = New-Object System.IO.StreamReader($resp.GetResponseStream())
        $listing = $sr.ReadToEnd()
        $sr.Close(); $resp.Close()

        Write-Host 'LOGIN OK' -ForegroundColor Green
        Write-Host ''
        Write-Host '--- directory listing (this is where the deploy will land) ---' -ForegroundColor Yellow

        $names = @()
        foreach ($line in ($listing -split "`n")) {
            if ($line.Trim()) {
                Write-Host ('  ' + $line.Trim())
                $names += $line.Trim().Split('/')[-1]
            }
        }

        Write-Host ''
        Write-Host '--- what this means ---' -ForegroundColor Cyan

        # The FTP account's root IS the application root, because server-dir is `./`.
        $expect = @('artisan', 'app', 'public')
        $found = @($expect | Where-Object { $names -contains $_ })

        if ($found.Count -eq 3) {
            Write-Host '  This looks like the APPLICATION ROOT.' -ForegroundColor Green
            Write-Host '  vendor.zip and .env belong here, and so does the extracted vendor/.' -ForegroundColor Green
        } elseif ($names -contains 'itrequest.mwstay.com') {
            Write-Host '  NESTING PROBLEM: the listing shows the subdomain folder itself.' -ForegroundColor Red
            Write-Host '  The FTP account is rooted one level too high, so a deploy would upload' -ForegroundColor Red
            Write-Host '  into a folder BESIDE the application and nothing would change on the site.' -ForegroundColor Red
            Write-Host "  Fix the FTP account directory in cPanel > FTP Accounts." -ForegroundColor Red
        } elseif ($names.Count -eq 0) {
            Write-Host '  EMPTY. The account points at an empty directory.' -ForegroundColor Red
        } else {
            Write-Host "  Unrecognised. Found: $($names -join ', ')" -ForegroundColor Yellow
            Write-Host '  Expected at least: artisan, app, public' -ForegroundColor Yellow
        }

        Write-Host ''
        Write-Host '  Check for vendor/ in the listing above.' -ForegroundColor Cyan
        Write-Host '  If vendor.zip is there but vendor/ is NOT, the archive has not been extracted' -ForegroundColor Cyan
        Write-Host '  and every page will 500 with an empty body.' -ForegroundColor Cyan
    }
    catch {
        $we = $_.Exception
        Write-Host 'LOGIN FAILED' -ForegroundColor Red
        Write-Host ('  message: ' + $we.Message)
        if ($we.Response -is [System.Net.FtpWebResponse]) {
            Write-Host ('  FTP status: ' + [int]$we.Response.StatusCode + ' ' + $we.Response.StatusDescription)
        }
        if ($we -is [System.Net.WebException]) {
            Write-Host ('  WebException status: ' + $we.Status)
        }

        Write-Host ''
        Write-Host '  A 530 here means the same 530 GitHub reports: the credentials are wrong,' -ForegroundColor Yellow
        Write-Host '  not the workflow. Reset the password in cPanel > FTP Accounts, then update' -ForegroundColor Yellow
        Write-Host '  the FTP_PASSWORD secret in GitHub.' -ForegroundColor Yellow
    }
}
finally {
    # Wipe the plaintext password from memory.
    [System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    $password = $null
}

Write-Host ''
Write-Host 'Done. Nothing above contains your password.' -ForegroundColor Cyan
