$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

Write-Host "== Install dependencies =="
python -m pip install --upgrade pip
python -m pip install -r requirements.txt

Write-Host "== Build exe (PyInstaller) =="
if (Test-Path dist) { Remove-Item -Recurse -Force dist }
if (Test-Path build) { Remove-Item -Recurse -Force build }
python -m PyInstaller --noconfirm bms-desktop.spec

Copy-Item -Force config.example.json "dist\config.json"

$exe = Join-Path (Get-Location) "dist\BMS Desktop.exe"
if (-not (Test-Path $exe)) {
  throw "Build failed: exe not found"
}
Write-Host "OK exe: $exe"

$isccCandidates = @(
  "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe",
  "$env:ProgramFiles\Inno Setup 6\ISCC.exe"
)
$iscc = $isccCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1

if ($iscc) {
  Write-Host "== Build installer (Inno Setup) =="
  & $iscc installer.iss
  Write-Host "OK installer: dist\BMS-Desktop-Setup.exe"
} else {
  Write-Host "Inno Setup not found. Use portable: dist\BMS Desktop.exe"
}

Write-Host "DONE"
