# Installs topper. Render Farm for this Windows user. No admin rights needed.
# Run again to update: settings and keys live in %USERPROFILE%\.blenderfarm and are kept.
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$self = $args[0]
$dest = Join-Path $env:LOCALAPPDATA 'TopperRenderFarm'
$pyVersion = '3.13.1'
$pyHome = Join-Path $env:LOCALAPPDATA 'Programs\Python\Python313'

function Find-Python {
    # A Python from 3.10 to 3.14 that really runs (the Microsoft Store
    # "python" placeholder does not count).
    $code = "import sys;v=sys.version_info[:2];print(sys.executable if (3,10)<=v<=(3,14) else '')"
    $tries = @(
        @('py', '-3.14'), @('py', '-3.13'), @('py', '-3.12'), @('py', '-3.11'), @('py', '-3.10'),
        @('python'), @((Join-Path $pyHome 'python.exe'))
    )
    foreach ($t in $tries) {
        $ErrorActionPreference = 'Continue'
        try {
            $exe = $t[0]; $rest = @($t | Select-Object -Skip 1)
            $out = & $exe @rest -c $code 2>$null
            if ($LASTEXITCODE -eq 0 -and $out -and "$out".Trim()) { return "$out".Trim() }
        } catch { }
    }
    return $null
}

try {
    Write-Host ''
    Write-Host '  1/5  A procurar o Python'
    $py = Find-Python
    if (-not $py) {
        Write-Host "       Nao encontrado - a instalar o Python $pyVersion (so para este utilizador)"
        $inst = Join-Path $env:TEMP "python-$pyVersion-amd64.exe"
        Invoke-WebRequest -UseBasicParsing -Uri "https://www.python.org/ftp/python/$pyVersion/python-$pyVersion-amd64.exe" -OutFile $inst
        $p = Start-Process -FilePath $inst -Wait -PassThru -ArgumentList @(
            '/quiet', 'InstallAllUsers=0', 'PrependPath=1', 'Include_launcher=0', 'Include_test=0', 'Include_doc=0')
        Remove-Item -LiteralPath $inst -ErrorAction SilentlyContinue
        if ($p.ExitCode -ne 0) { throw "O instalador do Python terminou com o codigo $($p.ExitCode)." }
        $py = Find-Python
        if (-not $py) { throw 'O Python foi instalado mas nao arranca.' }
    }
    Write-Host "       $py"

    Write-Host "  2/5  A copiar o programa para $dest"
    $raw = Get-Content -LiteralPath $self -Raw
    $b64 = (($raw -split ('#__' + 'ZIP__#'))[1]) -replace '\s', ''
    $tmp = Join-Path $env:TEMP ('topper-render-farm-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Force -Path $tmp | Out-Null
    $zip = Join-Path $tmp 'app.zip'
    [IO.File]::WriteAllBytes($zip, [Convert]::FromBase64String($b64))
    Expand-Archive -LiteralPath $zip -DestinationPath $tmp -Force
    New-Item -ItemType Directory -Force -Path $dest | Out-Null
    # The existing .venv in $dest is kept, so updates are quick.
    robocopy (Join-Path $tmp 'topper-render-farm') $dest /E /XD .venv /NFL /NDL /NJH /NJS /NP | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "Nao foi possivel copiar os ficheiros para $dest" }
    Remove-Item -LiteralPath $tmp -Recurse -Force -ErrorAction SilentlyContinue

    Write-Host '  3/5  A ligar o botao Abrir do site (link topperfarm://)'
    $open = Join-Path $dest 'Abrir.cmd'
    $cmd = '"' + $env:ComSpec + '" /c ""' + $open + '""'
    $key = 'HKCU:\Software\Classes\topperfarm'
    New-Item -Path $key -Force | Out-Null
    Set-Item -Path $key -Value 'URL:topper. Render Farm'
    New-ItemProperty -Path $key -Name 'URL Protocol' -Value '' -PropertyType String -Force | Out-Null
    New-Item -Path "$key\shell\open\command" -Force | Out-Null
    Set-Item -Path "$key\shell\open\command" -Value $cmd
    if ((Get-Item -Path "$key\shell\open\command").GetValue('') -ne $cmd) { throw 'Nao foi possivel registar o link topperfarm://' }

    Write-Host '  4/5  A criar um atalho no Ambiente de Trabalho'
    try {
        $lnk = (New-Object -ComObject WScript.Shell).CreateShortcut(
            (Join-Path ([Environment]::GetFolderPath('Desktop')) 'topper. Render Farm.lnk'))
        $lnk.TargetPath = $open; $lnk.WorkingDirectory = $dest; $lnk.Save()
    } catch { Write-Host '       (sem atalho - nao faz falta)' }

    Write-Host '  5/5  A abrir o render farm'
    Start-Process 'topperfarm://open'
    exit 0
} catch {
    Write-Host ''
    Write-Host "  ERRO: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}
