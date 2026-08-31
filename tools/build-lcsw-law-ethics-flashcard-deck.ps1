# Build LCSW California Law & Ethics Flashcard Study Center JSON (833 cards / 10 sections).
# Source: CTA_LCSW_LawEthics_Flashcards_833.json — approved client export from
# CTA_LCSW_Law_and_Ethics_EP_Master_Flashcard_Study_Center_v1.0.html (const cards array).

$ErrorActionPreference = 'Stop'

$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$DefaultSource = Join-Path $PSScriptRoot 'CTA_LCSW_LawEthics_Flashcards_833.json'
$SourceFile = if ($args.Count -gt 0) { (Resolve-Path $args[0]).Path } else { $DefaultSource }
$OutDir = Join-Path $Root 'assets\course-materials\lcsw-law-ethics\study-tools'
$OutFile = Join-Path $OutDir 'flashcard-study-center.json'

if (-not (Test-Path $SourceFile)) {
	throw "Missing source JSON: $SourceFile`nPlace CTA_LCSW_LawEthics_Flashcards_833.json in tools/ or pass the path as an argument."
}

function Get-DomainKey([string]$Label) {
	$s = $Label.ToLowerInvariant()
	$s = [regex]::Replace($s, '[^a-z0-9]+', '-')
	$s = $s.Trim('-')
	if (-not $s) { return 'general' }
	return $s
}

function Get-SortOrderFromId([string]$Id) {
	if ([regex]::Match($Id, 'FC-(\d+)$').Success) {
		return [int][regex]::Match($Id, 'FC-(\d+)$').Groups[1].Value
	}
	return 0
}

function Get-DomainLabel([object]$Card) {
	$section = [string]$Card.section
	if ($section -eq 'License-Specific Module') {
		return 'License-Specific Module'
	}
	$topic = ([string]$Card.topic).Trim()
	if ($topic) { return $topic }
	return $section
}

function Build-Front([object]$Card) {
	$concept = ([string]$Card.concept).Trim()
	$prompt = ([string]$Card.prompt).Trim()
	if ($concept -and $prompt -and $concept -ne $prompt) {
		return ($concept + "`n`n" + $prompt)
	}
	if ($prompt) { return $prompt }
	return $concept
}

# Approved section/topic breakdown (10 groups).
$DomainOrder = @(
	@{ label = 'Informed Consent, Minors & Families'; expected = 167 },
	@{ label = 'Telehealth & Technology'; expected = 102 },
	@{ label = 'Professional Competence'; expected = 100 },
	@{ label = 'Professional Impairment'; expected = 78 },
	@{ label = 'Client Welfare & Harm Prevention'; expected = 81 },
	@{ label = 'Boundaries & Exploitation'; expected = 88 },
	@{ label = 'Cultural Humility & Bias'; expected = 52 },
	@{ label = 'Confidentiality & Information Sharing'; expected = 70 },
	@{ label = 'Documentation & Records'; expected = 70 },
	@{ label = 'License-Specific Module'; expected = 25 }
)

Write-Host "Loading source JSON: $SourceFile"
$raw = [IO.File]::ReadAllText($SourceFile)
if ($raw.StartsWith([char]0xFEFF)) { $raw = $raw.Substring(1) }
$source = $raw | ConvertFrom-Json

if (-not $source) { throw 'Source JSON is empty or invalid.' }

# Accept either a top-level array or { cards: [...] }.
$sourceCards = @()
if ($source -is [System.Array]) {
	$sourceCards = @($source)
} elseif ($source.cards) {
	$sourceCards = @($source.cards)
} else {
	throw 'Source JSON must be an array of cards or an object with a cards array.'
}

Write-Host ("Source records: {0}" -f $sourceCards.Count)

$seenIds = @{}
$cards = New-Object System.Collections.Generic.List[object]
$domainCounts = @{}

foreach ($src in $sourceCards) {
	$id = ([string]$src.id).Trim()
	if (-not $id) { throw 'Card missing id field.' }
	if ($seenIds.ContainsKey($id)) { throw "Duplicate card id: $id" }
	$seenIds[$id] = $true

	$domainLabel = Get-DomainLabel $src
	$domainKey = Get-DomainKey $domainLabel
	$front = Build-Front $src
	$back = ([string]$src.back).Trim()
	$cue = ([string]$src.cue).Trim()

	if (-not $front -or -not $back) {
		throw "Card $id is missing prompt/concept front or back text."
	}

	if (-not $domainCounts.ContainsKey($domainKey)) {
		$domainCounts[$domainKey] = 0
	}
	$domainCounts[$domainKey]++

	$meta = [ordered]@{}
	if ($src.section) { $meta.section = [string]$src.section }
	if ($src.topic) { $meta.topic = [string]$src.topic }
	if ($null -ne $src.workbook -and "$($src.workbook)" -ne '') {
		$meta.workbook = [int]$src.workbook
	}
	if ($src.chapter) { $meta.chapter = [int]$src.chapter }
	if ($src.chapterTitle) { $meta.chapterTitle = [string]$src.chapterTitle }
	if ($src.type) { $meta.type = [string]$src.type }

	$cards.Add([ordered]@{
		id           = $id
		domain       = $domainKey
		domain_label = $domainLabel
		front        = $front
		back         = $back
		memory_cue   = $cue
		sort_order   = Get-SortOrderFromId $id
		meta         = $meta
	})
}

$sorted = @($cards | Sort-Object { $_.sort_order }, { $_.id })

if ($sorted.Count -ne 833) {
	throw ("Expected 833 cards, got {0}" -f $sorted.Count)
}

$domains = New-Object System.Collections.Generic.List[object]
$order = 0
foreach ($def in $DomainOrder) {
	$key = Get-DomainKey $def.label
	$count = if ($domainCounts.ContainsKey($key)) { [int]$domainCounts[$key] } else { 0 }
	if ($count -ne [int]$def.expected) {
		throw ("Domain '{0}': expected {1}, got {2}" -f $def.label, $def.expected, $count)
	}
	$order++
	$domains.Add([ordered]@{
		key            = $key
		label          = $def.label
		order          = $order
		expected_count = $count
	})
}

if (-not (Test-Path $OutDir)) {
	New-Item -ItemType Directory -Path $OutDir -Force | Out-Null
}

$deck = [PSCustomObject]@{
	program        = 'lcsw-law-ethics'
	title          = 'LCSW California Law & Ethics - Flashcard Study Center'
	version        = '1.0'
	expected_total = 833
	source         = 'CTA_LCSW_LawEthics_Flashcards_833.json (CTA_LCSW_Law_and_Ethics_EP_Master_Flashcard_Study_Center_v1.0.html)'
	domains        = @($domains)
	cards          = @($sorted)
}

$json = $deck | ConvertTo-Json -Depth 10 -Compress:$false
[IO.File]::WriteAllText($OutFile, $json, [Text.UTF8Encoding]::new($false))

Write-Host ("Wrote {0} cards / {1} domains -> {2}" -f $sorted.Count, $domains.Count, $OutFile)
foreach ($d in $domains) {
	Write-Host ("  {0}: {1}" -f $d.label, $d.expected_count)
}
