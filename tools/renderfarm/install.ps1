# Installs topper. Render Farm for this Windows user (no admin rights needed).
# Called by Instalar.bat. Run it again to update: settings and keys live in
# %USERPROFILE%\.blenderfarm and are never touched.
$ErrorActionPreference = 'Stop'
$src  = Split-Path -Parent $MyInvocation.MyCommand.Path
$dest = Join-Path $env:LOCALAPPDATA 'TopperRenderFarm'
try {
    if (-not (Test-Path (Join-Path $src 'farmd\__main__.py'))) {
        throw 'Extract the whole ZIP first (right-click > Extract All), then run Instalar.bat from the extracted folder.'
    }

    Write-Host "  1/4  Copying the app to $dest"
    New-Item -ItemType Directory -Force -Path $dest | Out-Null
    robocopy $src $dest /E /XD .venv .git __pycache__ /NFL /NDL /NJH /NJS /NP | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "Could not copy the files to $dest" }
    # Files from a downloaded ZIP are marked as "from the internet"; clear that
    # so Windows does not stop the app when the Abrir button starts it.
    Get-ChildItem -LiteralPath $dest -Recurse -File | Unblock-File

    Write-Host '  2/4  Registering the topperfarm:// link (used by the Abrir button)'
    $bat = Join-Path $dest 'Blender Farm.bat'
    $cmd = '"' + $env:ComSpec + '" /c ""' + $bat + '""'
    $key = 'HKCU:\Software\Classes\topperfarm'
    New-Item -Path $key -Force | Out-Null
    Set-Item -Path $key -Value 'URL:topper. Render Farm'
    New-ItemProperty -Path $key -Name 'URL Protocol' -Value '' -PropertyType String -Force | Out-Null
    New-Item -Path "$key\shell\open\command" -Force | Out-Null
    Set-Item -Path "$key\shell\open\command" -Value $cmd
    $check = (Get-Item -Path "$key\shell\open\command").GetValue('')
    if ($check -ne $cmd) { throw 'The topperfarm:// link could not be registered.' }

    Write-Host '  3/4  Adding a Desktop shortcut'
    try {
        $lnk = (New-Object -ComObject WScript.Shell).CreateShortcut(
            (Join-Path ([Environment]::GetFolderPath('Desktop')) 'topper. Render Farm.lnk'))
        $lnk.TargetPath = $bat; $lnk.WorkingDirectory = $dest; $lnk.Save()
    } catch { Write-Host '       (could not create the shortcut - not needed, skipping)' }

    Write-Host '  4/4  Starting it through the topperfarm:// link, exactly like the Abrir button'
    Start-Process 'topperfarm://open'
} catch {
    Write-Host ''
    Write-Host "  ERROR: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}
