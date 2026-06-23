<#
.SYNOPSIS
    Builds the wp.org distribution zip for Logscope.

.DESCRIPTION
    Produces dist/logscope.zip containing exactly what ships to wordpress.org —
    no dev files, no node_modules, no tests. Mirrors the CI "Assemble
    distribution" step so the local artifact matches what Plugin Check sees.

    Pipeline:
      1. Clean build/ and dist/.
      2. Build admin assets via wp-scripts DIRECTLY (skips the `pnpm build`
         postbuild FTP deploy).
      3. git archive --format=zip — honours .gitattributes `export-ignore`,
         so tests/, .github/, dev dotfiles, assets/src/, *.md docs, etc. are
         dropped automatically.
      4. Layer in the built assets/build/ and a prod-only vendor/ (composer
         install --no-dev into the staging dir — your local dev vendor/ is
         left untouched).
      5. Compress-Archive -> dist/logscope.zip, then report size.

    Run with:  pnpm package   (or)   powershell -File bin/build-zip.ps1
#>
$ErrorActionPreference = 'Stop'

$slug = 'logscope'
$root = (git rev-parse --show-toplevel).Trim()
$build = Join-Path $root 'build'
$staging = Join-Path $build $slug
$dist = Join-Path $root 'dist'
$zip = Join-Path $dist "$slug.zip"

Write-Host "Packaging $slug ..." -ForegroundColor Cyan

# 1. Clean previous artifacts so a stale file can never sneak into the zip.
foreach ($d in @($build, $dist)) {
    if (Test-Path $d) { Remove-Item -Recurse -Force $d }
}
New-Item -ItemType Directory -Force -Path $staging | Out-Null
New-Item -ItemType Directory -Force -Path $dist | Out-Null

# 2. Build assets. Call wp-scripts directly, NOT `pnpm build` — the latter
#    fires a postbuild FTP deploy we don't want during packaging.
Write-Host '> Building admin assets...' -ForegroundColor DarkGray
& pnpm exec wp-scripts build --webpack-src-dir=assets/src --output-path=assets/build
if ($LASTEXITCODE -ne 0) { throw 'Asset build failed.' }

# 3. Export tracked, non-export-ignored files. --format=zip writes a file
#    (no binary pipe, which PowerShell would corrupt), then we expand it.
Write-Host '> Exporting tracked files (git archive)...' -ForegroundColor DarkGray
$srcZip = Join-Path $build '_src.zip'
& git archive --format=zip -o $srcZip HEAD
if ($LASTEXITCODE -ne 0) { throw 'git archive failed.' }
Expand-Archive -Path $srcZip -DestinationPath $staging -Force
Remove-Item $srcZip

# 4. Layer in the gitignored runtime pieces: built assets + prod vendor.
Write-Host '> Adding built assets + prod vendor...' -ForegroundColor DarkGray
New-Item -ItemType Directory -Force -Path (Join-Path $staging 'assets') | Out-Null
Copy-Item -Recurse (Join-Path $root 'assets\build') (Join-Path $staging 'assets\build')

Copy-Item (Join-Path $root 'composer.json') $staging
Copy-Item (Join-Path $root 'composer.lock') $staging
& composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --working-dir=$staging
if ($LASTEXITCODE -ne 0) { throw 'composer install (prod) failed.' }
# composer.json/lock are dev manifests — not needed by the shipped plugin.
Remove-Item (Join-Path $staging 'composer.json'), (Join-Path $staging 'composer.lock')

# 5. Zip the slug folder (wp.org expects `logscope/` at the archive root).
Write-Host '> Compressing...' -ForegroundColor DarkGray
Compress-Archive -Path $staging -DestinationPath $zip -Force

# 6. Report.
$sizeMb = (Get-Item $zip).Length / 1MB
Write-Host ''
Write-Host ("Built {0}" -f $zip) -ForegroundColor Green
Write-Host ("Size:  {0:N2} MB" -f $sizeMb) -ForegroundColor Green
if ($sizeMb -gt 10) {
    Write-Warning 'Zip exceeds the wp.org 10 MB guideline — investigate what got bundled.'
}
