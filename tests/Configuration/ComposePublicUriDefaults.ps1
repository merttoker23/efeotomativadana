$ErrorActionPreference = 'Stop'
$previousDefault = $env:DEFAULT_URI
$previousDevelopment = $env:DEV_DEFAULT_URI
$projectRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent

function Assert-PublicUri {
    param([string[]]$ComposeFiles, [string]$Expected)

    $arguments = @('compose')
    foreach ($file in $ComposeFiles) {
        $arguments += @('-f', $file)
    }
    $arguments += @('config', '--format', 'json')
    $json = & docker @arguments
    if ($LASTEXITCODE -ne 0) { throw 'Docker Compose configuration resolution failed.' }
    $configuration = $json | ConvertFrom-Json
    foreach ($service in @('app', 'worker')) {
        $actual = $configuration.services.$service.environment.DEFAULT_URI
        if ($actual -ne $Expected) {
            throw "$service public URI resolved to $actual; expected $Expected."
        }
    }
}

Push-Location $projectRoot
try {
    $env:DEFAULT_URI = $null
    $env:DEV_DEFAULT_URI = $null
    Assert-PublicUri @('compose.yaml') 'https://efeotomotivadana.com'
    Assert-PublicUri @('compose.yaml', 'compose.override.yaml') 'https://localhost:8443'

    $env:DEFAULT_URI = 'https://production-override.example.test'
    Assert-PublicUri @('compose.yaml') 'https://production-override.example.test'
    Assert-PublicUri @('compose.yaml', 'compose.override.yaml') 'https://localhost:8443'

    $env:DEV_DEFAULT_URI = 'https://development-override.example.test'
    Assert-PublicUri @('compose.yaml', 'compose.override.yaml') 'https://development-override.example.test'
    Assert-PublicUri @('compose.yaml') 'https://production-override.example.test'
    Write-Output 'PASS: resolved development and production public URI defaults and independent overrides.'
} finally {
    $env:DEFAULT_URI = $previousDefault
    $env:DEV_DEFAULT_URI = $previousDevelopment
    Pop-Location
}
