# F1 Betting Deployment Script for Windows
# Usage: .\deploy.ps1 -Environment test|live

param(
    [ValidateSet('test', 'live')]
    [string]$Environment = 'test',
    
    [switch]$BuildOnly
)

# Load .env file
function Load-EnvFile {
    $envPath = Join-Path $PSScriptRoot ".env"
    if (-not (Test-Path $envPath)) {
        Write-Host "❌ .env file not found. Please create it from .env.example" -ForegroundColor Red
        exit 1
    }
    
    $envContent = Get-Content $envPath
    foreach ($line in $envContent) {
        if ($line -and -not $line.StartsWith('#')) {
            $key, $value = $line.Split('=', 2)
            if ($key -and $value) {
                Set-Item "env:$($key.Trim())" $value.Trim()
            }
        }
    }
}

# Get ignore patterns
function Get-IgnorePatterns {
    $ignorePath = Join-Path $PSScriptRoot ".deployignore"
    $patterns = @()
    
    $content = Get-Content $ignorePath
    foreach ($line in $content) {
        if ($line -and -not $line.StartsWith('#')) {
            $patterns += $line.Trim()
        }
    }
    
    return $patterns
}

# Check if file should be ignored
function Test-ShouldIgnore {
    param([string]$RelativePath)
    
    $patterns = Get-IgnorePatterns
    
    foreach ($pattern in $patterns) {
        if ($RelativePath -like "*$pattern*") {
            return $true
        }
    }
    return $false
}

# Create ZIP file
function New-DeploymentZip {
    param([string]$Environment)
    
    Write-Host "📦 Creating ZIP file for $($Environment.ToUpper()) environment..." -ForegroundColor Cyan
    
    $publicDir = Join-Path $PSScriptRoot "public"
    $zipFile = Join-Path $PSScriptRoot "public-build.zip"
    
    # Remove existing zip
    if (Test-Path $zipFile) {
        Remove-Item $zipFile -Force
    }
    
    # Create zip using built-in compression
    $compressionLevel = [System.IO.Compression.CompressionLevel]::Optimal
    $zipArchive = [System.IO.Compression.ZipFile]::Open($zipFile, 'Create', [System.Text.Encoding]::UTF8)
    
    # Add files recursively
    function Add-FilesRecursive {
        param(
            [string]$Dir,
            [string]$ArcPath,
            $ZipArchive
        )
        
        Get-ChildItem -Path $Dir -Force | ForEach-Object {
            $fullPath = $_.FullName
            $arcFullPath = if ($ArcPath) { "$ArcPath/$($_.Name)" } else { $_.Name }
            $relativePath = $fullPath.Substring($publicDir.Length + 1)
            
            if (Test-ShouldIgnore $relativePath) {
                Write-Host "  ⊘ Skipped: $relativePath" -ForegroundColor Gray
                return
            }
            
            if ($_.PSIsContainer) {
                Add-FilesRecursive -Dir $fullPath -ArcPath $arcFullPath -ZipArchive $ZipArchive
            } else {
                $entry = $ZipArchive.CreateEntry($arcFullPath)
                $stream = $entry.Open()
                $fileStream = [System.IO.File]::OpenRead($fullPath)
                $fileStream.CopyTo($stream)
                $fileStream.Dispose()
                $stream.Dispose()
            }
        }
    }
    
    Add-FilesRecursive -Dir $publicDir -ArcPath "public" -ZipArchive $zipArchive
    $zipArchive.Dispose()
    
    $zipSize = (Get-Item $zipFile).Length / 1MB
    Write-Host "✅ ZIP created: $zipFile ($([math]::Round($zipSize, 2)) MB)" -ForegroundColor Green
    
    return $zipFile
}

# Upload to FTP
function Publish-ToFTP {
    param(
        [string]$ZipFile,
        [string]$Environment
    )
    
    $ftpHost = $env:FTP_HOST
    $ftpUser = $env:FTP_USER
    $ftpPass = $env:FTP_PASS
    $ftpRoot = if ($Environment -eq 'live') { $env:FTP_ROOT_LIVE } else { $env:FTP_ROOT_TEST }
    $dryRun = $env:DRY_RUN -eq 'true'
    
    if (-not $ftpHost -or -not $ftpUser -or -not $ftpPass) {
        Write-Host "❌ Missing FTP credentials in .env file" -ForegroundColor Red
        return $false
    }
    
    Write-Host "`n🚀 Connecting to FTP: $ftpHost" -ForegroundColor Cyan
    Write-Host "📍 Deployment path: $ftpRoot" -ForegroundColor Cyan
    Write-Host "🌐 Environment: $($Environment.ToUpper())" -ForegroundColor Cyan
    
    if ($dryRun) {
        Write-Host "`n⚠️  DRY_RUN mode enabled - no files will be uploaded" -ForegroundColor Yellow
        return $true
    }
    
    try {
        $credential = New-Object System.Net.NetworkCredential($ftpUser, $ftpPass)
        $ftpUri = "ftp://$ftpHost$ftpRoot/"
        
        Write-Host "📤 Uploading $((Get-Item $ZipFile).Name)..." -ForegroundColor Cyan
        
        $webClient = New-Object System.Net.WebClient
        $webClient.Credentials = $credential
        
        $timestamp = (Get-Date).ToString('yyyy-MM-dd')
        $fileName = "public-$Environment-$timestamp.zip"
        $uploadUri = "$ftpUri$fileName"
        
        $webClient.UploadFile($uploadUri, $ZipFile)
        
        Write-Host "✅ Uploaded: $fileName" -ForegroundColor Green
        Write-Host "`n📋 Manual next steps:" -ForegroundColor Yellow
        Write-Host "  1. SSH into $ftpHost" -ForegroundColor Gray
        Write-Host "  2. Navigate to $ftpRoot" -ForegroundColor Gray
        Write-Host "  3. Extract: unzip $fileName" -ForegroundColor Gray
        Write-Host "  4. Move files from public/ to root: mv public/* ./" -ForegroundColor Gray
        Write-Host "  5. Clean up: rm -rf public $fileName" -ForegroundColor Gray
        
        return $true
    } catch {
        Write-Host "❌ Upload failed: $_" -ForegroundColor Red
        return $false
    }
}

# Main execution
function Main {
    Write-Host "🎯 F1 Betting Deployment Script" -ForegroundColor Cyan
    Write-Host "================================`n" -ForegroundColor Cyan
    
    Load-EnvFile
    
    $zipFile = New-DeploymentZip -Environment $Environment
    
    if ($BuildOnly) {
        Write-Host "`n✅ Build complete! ZIP ready for manual deployment." -ForegroundColor Green
        return
    }
    
    $success = Publish-ToFTP -ZipFile $zipFile -Environment $Environment
    
    if ($success) {
        Write-Host "`n🎉 Deployment completed successfully!" -ForegroundColor Green
        
        # Cleanup
        if (Test-Path $zipFile) {
            Remove-Item $zipFile -Force
            Write-Host "🗑️  ZIP file cleaned up" -ForegroundColor Gray
        }
    }
}

Main
