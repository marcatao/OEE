<#
.SYNOPSIS
  Instala (ou remove) o agendador do Laravel do OEE Alumbra como tarefa do Windows: roda "php artisan schedule:run"
  a cada 1 minuto, com ou sem usuário logado, e volta sozinho depois de reiniciar o servidor.

  O agendador dispara a sincronização com o Protheus pela periodicidade da tela Segurança › Banco de dados (coluna
  "Periodicidade"), as OS preventivas e a leitura dos pedidos de compra. A tela Banco de dados mostra se ele está
  rodando ("Agendador do Laravel (serviço)").

.EXAMPLE
  # PowerShell como Administrador, na pasta do portal (ex.: C:\OEE):
  powershell -ExecutionPolicy Bypass -File .\scripts\windows\instalar-agendador.ps1

.EXAMPLE
  # Com uma conta de serviço do domínio (em vez da conta SYSTEM):
  powershell -ExecutionPolicy Bypass -File .\scripts\windows\instalar-agendador.ps1 -Usuario "ALUMBRA\svc_oee" -Senha "..."

.EXAMPLE
  # Remover:
  powershell -ExecutionPolicy Bypass -File .\scripts\windows\instalar-agendador.ps1 -Remover
#>
param(
    [string] $Pasta = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,  # pasta do portal (onde está o "artisan")
    [string] $Php = '',                                                      # vazio = <pasta>\php8.3\php.exe
    [string] $Usuario = 'SYSTEM',
    [string] $Senha = '',
    [string] $NomeTarefa = 'OEE Alumbra - Agendador',
    [switch] $Remover
)

$ErrorActionPreference = 'Stop'

$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { throw 'Abra o PowerShell como Administrador.' }

if ($Remover) {
    if (Get-ScheduledTask -TaskName $NomeTarefa -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $NomeTarefa -Confirm:$false
        Write-Host "Tarefa '$NomeTarefa' removida." -ForegroundColor Green
    } else {
        Write-Host "Tarefa '$NomeTarefa' não existe." -ForegroundColor Yellow
    }
    return
}

if (-not $Php) { $Php = Join-Path $Pasta 'php8.3\php.exe' }
if (-not (Test-Path (Join-Path $Pasta 'artisan'))) { throw "Não achei o 'artisan' em $Pasta. Informe -Pasta C:\caminho\do\portal." }
if (-not (Test-Path $Php)) { throw "Não achei o PHP em $Php. Informe -Php C:\caminho\do\php.exe." }

# Confere se o PHP e o banco respondem antes de instalar.
Write-Host 'Conferindo o PHP e o banco...'
Push-Location $Pasta
try {
    & $Php artisan banco:testar
    if ($LASTEXITCODE -ne 0) { throw 'php artisan banco:testar falhou: corrija a conexão com o banco (.env) antes de instalar.' }
} finally { Pop-Location }

$acao = New-ScheduledTaskAction -Execute $Php -Argument 'artisan schedule:run' -WorkingDirectory $Pasta

# A cada 1 minuto, para sempre, e também ao ligar o servidor.
$aCadaMinuto = New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes 1)
$aoLigar = New-ScheduledTaskTrigger -AtStartup

# Uma rodada por vez (a sincronização em si roda em segundo plano e tem trava própria); no máximo 1 h por rodada;
# roda mesmo na bateria/sem rede ociosa e volta se falhar.
$config = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 1) `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
    -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)

if (Get-ScheduledTask -TaskName $NomeTarefa -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName $NomeTarefa -Confirm:$false
}

$descricao = 'OEE Alumbra: agendador do Laravel (php artisan schedule:run a cada minuto). Sincronização com o Protheus, preventivas e pedidos de compra.'
if ($Usuario -eq 'SYSTEM') {
    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    Register-ScheduledTask -TaskName $NomeTarefa -Action $acao -Trigger @($aCadaMinuto, $aoLigar) -Settings $config `
        -Principal $principal -Description $descricao | Out-Null
} else {
    if (-not $Senha) { throw 'Com -Usuario, informe também -Senha (a tarefa roda sem o usuário logado).' }
    Register-ScheduledTask -TaskName $NomeTarefa -Action $acao -Trigger @($aCadaMinuto, $aoLigar) -Settings $config `
        -User $Usuario -Password $Senha -RunLevel Highest -Description $descricao | Out-Null
}

Start-ScheduledTask -TaskName $NomeTarefa
Write-Host ''
Write-Host "Tarefa '$NomeTarefa' instalada (conta: $Usuario)." -ForegroundColor Green
Write-Host "  PHP:   $Php"
Write-Host "  Pasta: $Pasta"
Write-Host ''
Write-Host 'Confira:'
Write-Host "  1) em 1 ou 2 minutos, a tela Segurança > Banco de dados mostra 'Agendador do Laravel (serviço): Rodando';"
Write-Host "  2) & '$Php' artisan schedule:list   (lista o que o agendador roda e quando);"
Write-Host "  3) Agendador de Tarefas do Windows > '$NomeTarefa' > Histórico."
