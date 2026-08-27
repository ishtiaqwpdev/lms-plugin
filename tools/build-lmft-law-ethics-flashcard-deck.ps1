# Convert the approved LMFT Law & Ethics Master Flashcard Library DOCX
# into Flashcard Study Center JSON. Does not touch workbooks, banks, or Practice Exams.

$ErrorActionPreference = 'Stop'
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Docx = Join-Path $Root 'assets\course-materials\lmft-law-ethics\study-tools\CTA_LMFT_Law_and_Ethics_EP_Master_Flashcard_Library_Printable_Single_Sided_Study_Edition_v1.1.docx'
$Out = Join-Path $Root 'assets\course-materials\lmft-law-ethics\study-tools\flashcard-study-center.json'

$DomainOrder = @(
	'Informed Consent, Minors & Families'
	'Telehealth & Technology'
	'Professional Competence'
	'Professional Impairment'
	'Client Welfare & Harm Prevention'
	'Boundaries & Exploitation'
	'Cultural Humility & Bias'
	'Confidentiality & Information Sharing'
	'Documentation & Records'
)

$ExpectedCounts = @{
	'Informed Consent, Minors & Families'      = 166
	'Telehealth & Technology'                  = 102
	'Professional Competence'                  = 99
	'Professional Impairment'                  = 78
	'Client Welfare & Harm Prevention'         = 81
	'Boundaries & Exploitation'                = 88
	'Cultural Humility & Bias'                 = 53
	'Confidentiality & Information Sharing'    = 70
	'Documentation & Records'                  = 70
}

function Get-DocxPlainText([string]$Path) {
	$tmp = Join-Path $env:TEMP ('cta-docx-' + [guid]::NewGuid().ToString('N'))
	New-Item -ItemType Directory -Path $tmp | Out-Null
	try {
		Copy-Item -LiteralPath $Path -Destination (Join-Path $tmp 'bank.zip')
		Expand-Archive -LiteralPath (Join-Path $tmp 'bank.zip') -DestinationPath (Join-Path $tmp 'unz') -Force
		$xml = [IO.File]::ReadAllText((Join-Path $tmp 'unz\word\document.xml'))
		$text = [regex]::Replace($xml, '</w:p>', "`n")
		$text = [regex]::Replace($text, '<[^>]+>', '')
		$text = [System.Net.WebUtility]::HtmlDecode($text)
		$text = $text -replace [char]0x00A0, ' '
		return ($text -replace "`r`n", "`n")
	} finally {
		Remove-Item -LiteralPath $tmp -Recurse -Force -ErrorAction SilentlyContinue
	}
}

function Get-DomainKey([string]$Label) {
	$key = $Label.ToLowerInvariant()
	$key = [regex]::Replace($key, '[^a-z0-9]+', '-')
	return $key.Trim('-')
}

function Get-CleanText([string]$Value) {
	$Value = [string]$Value
	$Value = $Value -replace '[ \t]+', ' '
	$lines = @($Value -split "`n" | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })
	return ($lines -join "`n").Trim()
}

if (-not (Test-Path -LiteralPath $Docx)) {
	throw "Missing flashcard library: $Docx"
}

Write-Host 'Parsing Master Flashcard Library...'
$text = Get-DocxPlainText $Docx
$marks = [regex]::Matches($text, '(?m)^CTA-FC-(\d{4})\s*\|\s*(.+?)\s*$')
if ($marks.Count -ne 807) {
	throw ("Expected 807 CTA-FC markers, got {0}" -f $marks.Count)
}

$cards = New-Object System.Collections.Generic.List[object]
$prevEnd = 0

foreach ($mark in $marks) {
	$chunk = $text.Substring($prevEnd, $mark.Index - $prevEnd).Trim()
	$prevEnd = $mark.Index + $mark.Length
	$id = 'CTA-FC-' + $mark.Groups[1].Value
	$label = $mark.Groups[2].Value.Trim()

	if ($chunk -notmatch '(?s)ANSWER:\s*') {
		continue
	}

	$front = ''
	$back = ''
	$cue = ''
	if ($chunk -match '(?s)^(.*)(?:\n|\r)ANSWER:\s*(.*?)(?:(?:\n|\r)EXAM REMINDER:\s*(.*))?$') {
		$front = Get-CleanText $Matches[1]
		$back = Get-CleanText $Matches[2]
		if ($Matches.Count -ge 4) {
			$cue = Get-CleanText $Matches[3]
		}
	} else {
		throw ("Unparseable card before {0}" -f $id)
	}

	if ($front -match '(?s)Study Reminder.*') {
		$front = ($front -replace '(?s)^.*Study Reminder[^\n]*\n(?:Use[^\n]*\n)?', '').Trim()
		$front = Get-CleanText $front
	}

	if (-not $front -or -not $back) {
		throw ("Empty front/back for {0}" -f $id)
	}
	if (-not $ExpectedCounts.ContainsKey($label)) {
		throw ("Unknown domain label on {0}: {1}" -f $id, $label)
	}

	$cards.Add([ordered]@{
		id           = $id
		domain       = Get-DomainKey $label
		domain_label = $label
		front        = $front
		back         = $back
		memory_cue   = $cue
	})
}

if ($cards.Count -ne 807) {
	throw ("Parsed {0} cards; expected 807" -f $cards.Count)
}

$counts = $cards | Group-Object { $_.domain_label }
foreach ($row in $counts) {
	$want = [int]$ExpectedCounts[$row.Name]
	if ($row.Count -ne $want) {
		throw ("Domain '{0}' has {1} cards; expected {2}" -f $row.Name, $row.Count, $want)
	}
}

Write-Host ("Parsed {0} cards" -f $cards.Count)
Write-Host ("First front: {0}" -f $cards[0].front)
Write-Host ("First domain: {0}" -f $cards[0].domain_label)

$domainRows = @()
$order = 0
foreach ($label in $DomainOrder) {
	$order++
	$domainRows += [ordered]@{
		key   = Get-DomainKey $label
		label = $label
		order = $order
	}
}

$cardRows = @($cards.ToArray())

$payload = [ordered]@{
	program         = 'lmft-law-ethics'
	title           = 'LMFT California Law & Ethics — Flashcard Study Center'
	version         = '1.1'
	expected_total  = 807
	source          = 'CTA_LMFT_Law_and_Ethics_EP_Master_Flashcard_Library_Printable_Single_Sided_Study_Edition_v1.1.docx (Study Center only; workbooks/banks/Practice Exams unchanged)'
	domains         = $domainRows
	cards           = $cardRows
}

$json = $payload | ConvertTo-Json -Depth 8
[IO.File]::WriteAllText($Out, $json, [Text.UTF8Encoding]::new($false))
Write-Host ("Wrote {0} (cards={1}, domains={2}, bytes={3})" -f $Out, $cardRows.Count, $domainRows.Count, (Get-Item $Out).Length)
$counts | Sort-Object Name | ForEach-Object { Write-Host ("  {0}: {1}" -f $_.Name, $_.Count) }
