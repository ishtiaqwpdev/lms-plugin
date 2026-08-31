# Import flashcard JSON from user paste file into source + build deck.
# Usage: Save the approved JSON array to tools/user-flashcard-paste.json, then run:
#   powershell -ExecutionPolicy Bypass -File tools/import-user-flashcards.ps1

$ErrorActionPreference = 'Stop'
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Paste = Join-Path $PSScriptRoot 'user-flashcard-paste.json'
$Source = Join-Path $PSScriptRoot 'CTA_LCSW_LawEthics_Flashcards_833.json'

if (-not (Test-Path $Paste)) {
    throw "Missing $Paste — paste the approved 833-card JSON array into this file."
}

$raw = [IO.File]::ReadAllText($Paste)
if ($raw.StartsWith([char]0xFEFF)) { $raw = $raw.Substring(1) }
# Node parser preserves curly quotes in cue fields (PowerShell ConvertFrom-Json can fail).
& node (Join-Path $PSScriptRoot 'import-user-flashcards.mjs') $Paste
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
