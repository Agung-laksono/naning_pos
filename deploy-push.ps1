param(
    [string]$Message = ""
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($Message)) {
    $Message = "Update POS build $(Get-Date -Format 'yyyy-MM-dd HH:mm')"
}

Write-Host "1/4 Memastikan public/hot tidak ikut ter-track..." -ForegroundColor Cyan
$trackedHot = git ls-files public/hot
if ($trackedHot) {
    git rm --cached public/hot
}

Write-Host "2/4 Menjalankan npm run build..." -ForegroundColor Cyan
npm run build
if ($LASTEXITCODE -ne 0) {
    Write-Error "Build gagal. Push dibatalkan."
    exit 1
}

Write-Host "3/4 Menyiapkan commit ke branch main..." -ForegroundColor Cyan
git add -A

git diff --cached --quiet
if ($LASTEXITCODE -eq 0) {
    Write-Host "Tidak ada perubahan baru untuk di-commit." -ForegroundColor Yellow
} else {
    git commit -m $Message
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Commit gagal."
        exit 1
    }
}

Write-Host "4/4 Push ke origin/main..." -ForegroundColor Cyan
git push origin main
if ($LASTEXITCODE -ne 0) {
    Write-Error "Git push gagal."
    exit 1
}

Write-Host "Selesai! Kode dan public/build/ terbaru sudah ada di GitHub (main)." -ForegroundColor Green

