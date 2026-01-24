param (
    [Parameter(Mandatory=$true)]
    [ValidateSet("test", "live")]
    [string]$Environment,
    [switch]$BuildOnly
)

$envFile = Join-Path $PSScriptRoot ".env"
if (Test-Path $envFile) {
    Get-Content $envFile | Where-Object { $_ -match '=' -and $_ -notmatch '^#' } | ForEach-Object {
        $name, $value = $_.Split('=', 2); Set-Variable -Name "FTP_$($name.Trim())" -Value $value.Trim() -Scope Script
    }
}

$Timestamp = Get-Date -Format "yyyy-MM-dd"
$ZipName = "public-$Environment-$Timestamp.zip"
$SourceDir = Resolve-Path "$PSScriptRoot\..\public"
$DestPath = Join-Path $PSScriptRoot $ZipName

Write-Host "📦 Zipping: $SourceDir" -ForegroundColor Cyan
Add-Type -AssemblyName System.IO.Compression.FileSystem
if (Test-Path $DestPath) { Remove-Item $DestPath }
[System.IO.Compression.ZipFile]::CreateFromDirectory($SourceDir, $DestPath)

if ($BuildOnly) { exit }

$RemoteRoot = if ($Environment -eq "live") { $FTP_FTP_ROOT_LIVE } else { $FTP_FTP_ROOT_TEST }
$FTPUri = "ftp://$($FTP_FTP_HOST)$($RemoteRoot)/$ZipName"

Write-Host "🚀 Uploading to $FTPUri" -ForegroundColor Cyan
$webclient = New-Object System.Net.WebClient
$webclient.Credentials = New-Object System.Net.NetworkCredential($FTP_FTP_USER, $FTP_FTP_PASS)
$webclient.UploadFile($FTPUri, $DestPath)
Write-Host "✅ Done!" -ForegroundColor Green