# Build LCSW ASWB Clinical Flashcard Study Center: 180 cards / 3 ASWB 2026 content areas.
# Source: Form A + Form B learner stems (Practice Exams / Forms A/B untouched).

$ErrorActionPreference = 'Stop'
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Seeds = Join-Path $Root 'includes\quiz-seeds'
$OutDir = Join-Path $Root 'assets\course-materials\lcsw-aswb\study-tools'
$OutFile = Join-Path $OutDir 'flashcard-study-center.json'

# Approved Study Center allocation (180 total): 65 / 58 / 57
$DomainDefs = @(
	@{ key = 'values-and-ethics'; label = 'Values and Ethics'; order = 1; target = 65 },
	@{ key = 'assessment-and-planning'; label = 'Assessment and Planning'; order = 2; target = 58 },
	@{ key = 'intervention-and-practice'; label = 'Intervention and Practice'; order = 3; target = 57 }
)

function Get-PhpStringValue([string]$Block, [string]$Key) {
	$pat = "'$Key'\s*=>\s*'((?:\\'|[^'])*)'"
	$m = [regex]::Match($Block, $pat, 'Singleline')
	if (-not $m.Success) { return '' }
	$v = $m.Groups[1].Value -replace "\\'", "'"
	$v = $v -replace '\\"', '"'
	return $v.Trim()
}

function Classify-Domain([string]$Text) {
	$t = $Text.ToLowerInvariant()
	$ethics = 0; $assess = 0; $interv = 0
	foreach ($w in @('ethic','confidential','consent','mandate','boundary','dual relationship','privilege','justice','self-determination','values','nasw','duty to warn','informed consent','discrimination','oppression','cultural humility','advocacy')) {
		if ($t.Contains($w)) { $ethics++ }
	}
	foreach ($w in @('assess','diagnos','biopsychosocial','intake','screen','risk','suicid','formul','goal','treatment plan','triage','person-in-environment','developmental','strengths','collateral')) {
		if ($t.Contains($w)) { $assess++ }
	}
	foreach ($w in @('interven','therap','cbt','modality','session','group','family system','crisis response','termination','referral','case manag','supervis','discharge','engage the client','motivational')) {
		if ($t.Contains($w)) { $interv++ }
	}
	if ($ethics -ge $assess -and $ethics -ge $interv -and $ethics -gt 0) { return 'values-and-ethics' }
	if ($assess -ge $interv -and $assess -gt 0) { return 'assessment-and-planning' }
	if ($interv -gt 0) { return 'intervention-and-practice' }
	return ''
}

function Read-FormItems([string]$FileName) {
	$path = Join-Path $Seeds $FileName
	$raw = [IO.File]::ReadAllText($path)
	$items = New-Object System.Collections.Generic.List[object]
	$matches = [regex]::Matches($raw, "array\s*\(\s*'question_text'\s*=>\s*'((?:\\'|[^'])*)'([\s\S]*?)(?=\n\tarray\s*\(|\n\);)")
	$i = 0
	foreach ($m in $matches) {
		$i++
		$q = ($m.Groups[1].Value -replace "\\'", "'").Trim()
		$block = "array(`n`t`t'question_text' => '$($m.Groups[1].Value)'" + $m.Groups[2].Value
		$items.Add(@{
			idx = $i
			question_text = $q
			option_a = (Get-PhpStringValue $block 'option_a')
			option_b = (Get-PhpStringValue $block 'option_b')
			option_c = (Get-PhpStringValue $block 'option_c')
			option_d = (Get-PhpStringValue $block 'option_d')
			correct_option = (Get-PhpStringValue $block 'correct_option').ToLower()
			explanation = (Get-PhpStringValue $block 'explanation')
		})
	}
	return $items
}

function Compress-Front([string]$Text) {
	$t = ($Text -replace "`r`n", "`n").Trim()
	if ($t.Length -gt 700) { return $t.Substring(0, 700).Trim() + [char]0x2026 }
	return $t
}

function Compress-Expl([string]$Expl) {
	if (-not $Expl) { return '' }
	$t = ($Expl -replace "`r`n", "`n").Trim()
	$first = ($t -split "`n`n+")[0].Trim()
	if ($first.Length -gt 420) { $first = $first.Substring(0, 420).Trim() + [char]0x2026 }
	return $first
}

Write-Host 'Loading LCSW ASWB Form A/B...'
$formA = Read-FormItems 'lcsw-aswb-form-a.php'
$formB = Read-FormItems 'lcsw-aswb-form-b.php'
Write-Host ("Form A={0} Form B={1}" -f $formA.Count, $formB.Count)

$built = New-Object System.Collections.Generic.List[hashtable]
$rr = 0
foreach ($pack in @(@{ items = $formA; tag = 'A' }, @{ items = $formB; tag = 'B' })) {
	foreach ($item in $pack.items) {
		$front = Compress-Front ([string]$item.question_text)
		if (-not $front) { continue }
		$letter = [string]$item.correct_option
		$opt = ''
		if ($letter -match '^[a-d]$') { $opt = [string]$item["option_$letter"] }
		$backParts = @()
		if ($letter -and $opt) { $backParts += ('{0}. {1}' -f $letter.ToUpper(), $opt) }
		$expl = Compress-Expl ([string]$item.explanation)
		if ($expl) { $backParts += $expl }
		$back = ($backParts -join "`n").Trim()
		if (-not $back) { continue }

		$key = Classify-Domain ($front + ' ' + $back)
		if (-not $key) {
			$key = $DomainDefs[$rr % 3].key
			$rr++
		}
		$def = $DomainDefs | Where-Object { $_.key -eq $key } | Select-Object -First 1
		$built.Add(@{
			id = ('aswb-{0}-{1:D3}' -f $pack.tag, $item.idx)
			front = $front
			back = $back
			domain = $def.key
			domain_label = $def.label
			domain_order = [int]$def.order
		})
	}
}

# Group classified cards by domain, then fill exact approved targets (65/58/57).
$byDomain = @{}
foreach ($d in $DomainDefs) { $byDomain[$d.key] = New-Object System.Collections.Generic.List[hashtable] }
foreach ($c in $built) { $byDomain[$c.domain].Add($c) }

$counts = @{}
foreach ($d in $DomainDefs) { $counts[$d.key] = 0 }

$final = New-Object System.Collections.Generic.List[hashtable]
$used = @{}

# Pass 1: take up to target from each domain's natural pool.
foreach ($d in $DomainDefs) {
	$pool = $byDomain[$d.key]
	$need = [int]$d.target
	$take = [Math]::Min($need, $pool.Count)
	for ($i = 0; $i -lt $take; $i++) {
		$c = $pool[$i]
		$final.Add($c)
		$used[$c.id] = $true
		$counts[$d.key]++
	}
}

# Pass 2: reassign leftovers into domains still below target.
$leftover = @($built | Where-Object { -not $used.ContainsKey($_.id) })
$li = 0
while ($li -lt $leftover.Count -and $final.Count -lt 180) {
	$short = $null
	foreach ($d in $DomainDefs) {
		if ($counts[$d.key] -lt [int]$d.target) {
			$short = $d
			break
		}
	}
	if ($null -eq $short) { break }

	$c = $leftover[$li]
	$li++
	$final.Add(@{
		id           = $c.id
		front        = $c.front
		back         = $c.back
		domain       = $short.key
		domain_label = $short.label
		domain_order = [int]$short.order
	})
	$used[$c.id] = $true
	$counts[$short.key]++
}

# Safety: if still under a target, continue filling shortfalls from any unused card.
while ($final.Count -lt 180 -and $li -lt $leftover.Count) {
	$short = $DomainDefs | Sort-Object { $counts[$_.key] - [int]$_.target } | Select-Object -First 1
	$c = $leftover[$li]
	$li++
	$final.Add(@{
		id           = $c.id
		front        = $c.front
		back         = $c.back
		domain       = $short.key
		domain_label = $short.label
		domain_order = [int]$short.order
	})
	$counts[$short.key]++
}

if ($final.Count -ne 180) {
	throw ("Expected 180 cards, got {0}" -f $final.Count)
}
foreach ($d in $DomainDefs) {
	$got = [int]$counts[$d.key]
	if ($got -ne [int]$d.target) {
		throw ("Domain {0}: expected {1}, got {2}" -f $d.label, $d.target, $got)
	}
}

# Stable sort for Study Center display.
$sorted = New-Object System.Collections.Generic.List[hashtable]
foreach ($d in $DomainDefs) {
	$domainCards = @($final | Where-Object { $_.domain -eq $d.key })
	$i = 0
	foreach ($c in $domainCards) {
		$i++
		$sorted.Add(@{
			id           = ('ASWB-{0}-{1:D3}' -f $d.order, $i)
			front        = $c.front
			back         = $c.back
			domain       = $d.key
			domain_label = $d.label
			domain_order = [int]$d.order
			sort_order   = ($d.order * 100) + $i
			memory_cue   = ''
		})
	}
}
$final = $sorted

if (-not (Test-Path $OutDir)) { New-Item -ItemType Directory -Path $OutDir | Out-Null }

$domainArr = @()
foreach ($d in $DomainDefs) {
	$domainArr += @{
		key            = $d.key
		label          = $d.label
		order          = $d.order
		expected_count = [int]$d.target
	}
}

$payload = @{
	title          = 'LCSW ASWB Clinical - Flashcard Study Center'
	version        = '1.1'
	expected_total = 180
	program        = 'lcsw-aswb'
	source         = 'Form A/B stems classified to 2026 ASWB Clinical content areas; approved allocation Values and Ethics 65 / Assessment and Planning 58 / Intervention and Practice 57 (Practice Exams untouched)'
	domains        = $domainArr
	cards          = @($final)
}

$json = $payload | ConvertTo-Json -Depth 8
[IO.File]::WriteAllText($OutFile, $json, [Text.UTF8Encoding]::new($false))

Write-Host ("Wrote {0} cards to {1}" -f $final.Count, $OutFile)
foreach ($d in $DomainDefs) {
	$c = @($final | Where-Object { $_.domain -eq $d.key }).Count
	Write-Host ("  {0}: {1}" -f $d.label, $c)
}
