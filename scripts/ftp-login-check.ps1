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

# A typo in the DOMAIN SUFFIX of the login is the worst kind, because a wrong suffix and a wrong
# password both produce `530 Login authentication failed` - so the error sends you to reset a
# password that was never the problem. The cPanel login for this project is
# `aitirikuesform@itrequest.mwstay.com`; a `.my` or `.net` suffix looks plausible and is wrong.
if ($Username -match '@' ) {
    $suffix = ($Username -split '@')[-1]

    if ($suffix -ne 'itrequest.mwstay.com') {
        Write-Host ''
        Write-Host "WARNING: the login suffix is '$suffix'." -ForegroundColor Yellow
        Write-Host '  Expected: itrequest.mwstay.com' -ForegroundColor Yellow
        Write-Host '  A wrong suffix fails with 530, exactly like a wrong password, so it is worth' -ForegroundColor Yellow
        Write-Host '  copying the username straight out of cPanel rather than retyping it.' -ForegroundColor Yellow
    }
}

Write-Host ''
Write-Host "Server   : $Server" -ForegroundColor Cyan
Write-Host "Username : $Username" -ForegroundColor Cyan
Write-Host ''

$secure = Read-Host -Prompt 'FTP password (input hidden)' -AsSecureString
$bstr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)

try {
    $password = [System.Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)

    # ---- 0. Where does this account LAND? ----
    #
    # Asked FIRST, because it is the one answer that cannot be inferred from a listing. A listing
    # showing `artisan, app, public` might be the application root or one level above it, and the
    # difference decides whether a deploy uploads into the app or beside it.
    #
    # `PrintWorkingDirectory` is the FTP `PWD` command: the server replies with its own absolute
    # path, so there is nothing to deduce.
    Write-Host ''
    Write-Host '--- server-side working directory (FTP PWD) ---' -ForegroundColor Yellow

    $pwdReq = [System.Net.FtpWebRequest]::Create("ftp://$Server/")
    $pwdReq.Method = [System.Net.WebRequestMethods+Ftp]::PrintWorkingDirectory
    $pwdReq.Credentials = New-Object System.Net.NetworkCredential($Username, $password)
    $pwdReq.UsePassive = $true
    $pwdReq.EnableSsl = $false
    $pwdReq.KeepAlive = $false
    $pwdReq.Timeout = 30000

    try {
        $pwdResp = $pwdReq.GetResponse()
        $pwdReader = New-Object System.IO.StreamReader($pwdResp.GetResponseStream())
        $remoteRoot = $pwdReader.ReadToEnd().Trim()
        $pwdReader.Close(); $pwdResp.Close()

        Write-Host "  $remoteRoot" -ForegroundColor White
        Write-Host ''
        Write-Host '  A deploy with `server-dir: ./` uploads into EXACTLY this path.' -ForegroundColor Cyan
        Write-Host '  The application root must be this path, or the deploy lands beside it.' -ForegroundColor Cyan
    }
    catch {
        Write-Host '  Could not read it: ' -NoNewline -ForegroundColor Yellow
        Write-Host $_.Exception.Message
    }

    # ---- 1. Does the password authenticate, and what is in that directory? ----
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

        # The FTP account's root should BE the application root, because server-dir is `./`.
        # `laravel-app` is this project's app root, so its contents are what we expect here.
        $expect = @('artisan', 'app', 'public')
        $found = @($expect | Where-Object { $names -contains $_ })

        if ($found.Count -eq 3) {
            Write-Host '  This is the APPLICATION ROOT.' -ForegroundColor Green
            Write-Host '  .env, artisan and vendor.zip belong here, and so does the extracted vendor/.' -ForegroundColor Green
        } elseif ($names -contains 'laravel-app') {
            Write-Host '  NESTING PROBLEM: the listing shows the laravel-app folder itself.' -ForegroundColor Red
            Write-Host '  The account is rooted ONE LEVEL TOO HIGH, so a deploy would upload to' -ForegroundColor Red
            Write-Host '  the level ABOVE the application and nothing would change on the site.' -ForegroundColor Red
            Write-Host '  Fix the Directory on the FTP account in cPanel, or set server-dir to match.' -ForegroundColor Red
        } elseif ($names -contains 'itrequest.mwstay.com') {
            Write-Host '  NESTING PROBLEM: the listing shows the domain folder itself.' -ForegroundColor Red
            Write-Host '  Expected this account to land in laravel-app.' -ForegroundColor Red
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
