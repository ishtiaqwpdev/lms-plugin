<<<<<<< HEAD
# Build LMFT Law & Ethics Flashcard Study Center JSON from the approved
# Master Flashcard Library DOCX (807 cards / 9 topic domains).
# Does not touch workbook lessons, Practice Banks, or Practice Exams.

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem

$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Docx = Join-Path $Root 'assets\course-materials\lmft-law-ethics\study-tools\CTA_LMFT_Law_and_Ethics_EP_Master_Flashcard_Library_Printable_Single_Sided_Study_Edition_v1.1.docx'
$OutFile = Join-Path $Root 'assets\course-materials\lmft-law-ethics\study-tools\flashcard-study-center.json'

if (-not (Test-Path $Docx)) { throw "Missing flashcard DOCX: $Docx" }

function Get-DocxPlainText([string]$DocxPath) {
	$tmp = Join-Path $env:TEMP ("cta-lmft-le-fc-" + [Guid]::NewGuid().ToString('N'))
	if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
	[System.IO.Compression.ZipFile]::ExtractToDirectory((Resolve-Path $DocxPath), $tmp)
	$xml = [IO.File]::ReadAllText((Join-Path $tmp 'word\document.xml'))
	Remove-Item $tmp -Recurse -Force
	$t = [regex]::Replace($xml, '</w:p>', "`n")
	$t = [regex]::Replace($t, '<[^>]+>', '')
	$t = [System.Net.WebUtility]::HtmlDecode($t)
	return (($t -replace "`r`n", "`n").Trim())
}

function Get-DomainKey([string]$Label) {
	$s = $Label.ToLowerInvariant()
	$s = [regex]::Replace($s, '[^a-z0-9]+', '-')
	$s = $s.Trim('-')
	if (-not $s) { return 'general' }
	return $s
}

$DomainOrder = @(
	'Informed Consent, Minors & Families',
	'Telehealth & Technology',
	'Professional Competence',
	'Professional Impairment',
	'Client Welfare & Harm Prevention',
	'Boundaries & Exploitation',
	'Cultural Humility & Bias',
	'Confidentiality & Information Sharing',
	'Documentation & Records'
)

Write-Host 'Loading Master Flashcard Library DOCX...'
$text = Get-DocxPlainText $Docx

$markers = [regex]::Matches($text, 'CTA-FC-(\d{4})\s*\|\s*([^\r\n]+)')
if ($markers.Count -lt 1) { throw 'No CTA-FC markers found' }

$cards = New-Object System.Collections.Generic.List[object]
$domainCounts = @{}
$domainLabels = @{}

for ($i = 0; $i -lt $markers.Count; $i++) {
	$marker = $markers[$i]
	$idNum = $marker.Groups[1].Value
	$domainLabel = ($marker.Groups[2].Value -replace '\s+', ' ').Trim()

	$blockStart = if ($i -eq 0) { 0 } else { $markers[$i - 1].Index + $markers[$i - 1].Length }
	$blockEnd = $marker.Index
	$block = $text.Substring($blockStart, $blockEnd - $blockStart).Trim()

	$am = [regex]::Match($block, '(?ms)^(.*?)(?:\r?\n)ANSWER:\s*(.*?)(?:\r?\nEXAM REMINDER:\s*(.*?))?$')
	if (-not $am.Success) { continue }

	$promptBlock = $am.Groups[1].Value.Trim()
	$answer = ($am.Groups[2].Value -replace '\s+', ' ').Trim()
	$cue = if ($am.Groups[3].Success) { ($am.Groups[3].Value -replace '\s+', ' ').Trim() } else { '' }

	$lines = @($promptBlock -split "`n" | ForEach-Object { $_.Trim() } | Where-Object { $_ })
	$clean = New-Object System.Collections.Generic.List[string]
	foreach ($line in $lines) {
		if ($line -match '^(CTA LMFT|Printable|LMFT California|807 flashcards|HOW TO USE|Library Summary|Topic Organization|Study Reminder|Flashcards$|Source |Unique |Version |Copyright|Teaching Clinicians|Boundaries &|Client Welfare|Confidentiality|Cultural Humility|Documentation &|Informed Consent,|Professional Competence|Professional Impairment|Telehealth &)') {
			continue
		}
		if ($line -match '^\d+$' -and $line.Length -le 3) { continue }
		if ($line -match 'cards$' -and $line.Length -lt 40) { continue }
		$clean.Add($line)
	}
	if ($clean.Count -eq 0 -or -not $answer) { continue }

	$title = $clean[0]
	$question = if ($clean.Count -gt 1) { ($clean[1..($clean.Count - 1)] -join ' ').Trim() } else { '' }
	$front = if ($question) { ($title + "`n`n" + $question).Trim() } else { $title }

	$key = Get-DomainKey $domainLabel
	if (-not $domainLabels.ContainsKey($key)) {
		$domainLabels[$key] = $domainLabel
		$domainCounts[$key] = 0
	}
	$domainCounts[$key]++

	$cards.Add([ordered]@{
		id           = ('LMFT-LE-{0}' -f $idNum)
		domain       = $key
		domain_label = $domainLabel
		front        = $front
		back         = $answer
		memory_cue   = $cue
		sort_order   = [int]$idNum
	})
}

$domains = New-Object System.Collections.Generic.List[object]
$order = 0
foreach ($label in $DomainOrder) {
	$key = Get-DomainKey $label
	if (-not $domainCounts.ContainsKey($key)) { continue }
	$order++
	$domains.Add([ordered]@{
		key            = $key
		label          = $domainLabels[$key]
		order          = $order
		expected_count = [int]$domainCounts[$key]
	})
}
foreach ($key in ($domainCounts.Keys | Sort-Object)) {
	$already = $false
	foreach ($d in $domains) {
		if ($d.key -eq $key) { $already = $true; break }
	}
	if ($already) { continue }
	$order++
	$domains.Add([ordered]@{
		key            = $key
		label          = $domainLabels[$key]
		order          = $order
		expected_count = [int]$domainCounts[$key]
	})
}

$sorted = @($cards | Sort-Object { [int]$_.sort_order })

$cardRows = @()
foreach ($c in $sorted) {
	$cardRows += [PSCustomObject]@{
		id           = [string]$c.id
		domain       = [string]$c.domain
		domain_label = [string]$c.domain_label
		front        = [string]$c.front
		back         = [string]$c.back
		memory_cue   = [string]$c.memory_cue
		sort_order   = [int]$c.sort_order
	}
}

$domainRows = @()
foreach ($d in $domains) {
	$domainRows += [PSCustomObject]@{
		key            = [string]$d.key
		label          = [string]$d.label
		order          = [int]$d.order
		expected_count = [int]$d.expected_count
	}
}

$deck = [PSCustomObject]@{
	program         = 'lmft-law-ethics'
	title           = 'LMFT California Law & Ethics - Flashcard Study Center'
	version         = '1.1'
	expected_total  = $cardRows.Count
	source          = 'CTA_LMFT_Law_and_Ethics_EP_Master_Flashcard_Library_Printable_Single_Sided_Study_Edition_v1.1.docx'
=======
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
>>>>>>> 1dcdd55b430ec7b912f0b502b3878173ec976d47
	domains         = $domainRows
	cards           = $cardRows
}

<<<<<<< HEAD
$json = $deck | ConvertTo-Json -Depth 8
[IO.File]::WriteAllText($OutFile, $json, [Text.UTF8Encoding]::new($false))

Write-Host ("Wrote {0} cards / {1} domains -> {2}" -f $cardRows.Count, $domainRows.Count, $OutFile)
foreach ($d in $domainRows) {
	Write-Host ("  {0}: {1}" -f $d.label, $d.expected_count)
}
if ($cardRows.Count -ne 807) {
	throw ("Expected 807 cards from library summary, got {0}" -f $cardRows.Count)
}
=======
$json = $payload | ConvertTo-Json -Depth 8
[IO.File]::WriteAllText($Out, $json, [Text.UTF8Encoding]::new($false))
Write-Host ("Wrote {0} (cards={1}, domains={2}, bytes={3})" -f $Out, $cardRows.Count, $domainRows.Count, (Get-Item $Out).Length)
$counts | Sort-Object Name | ForEach-Object { Write-Host ("  {0}: {1}" -f $_.Name, $_.Count) }
>>>>>>> 1dcdd55b430ec7b912f0b502b3878173ec976d47
