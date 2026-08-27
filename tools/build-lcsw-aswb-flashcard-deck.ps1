# Build LCSW ASWB Clinical Flashcard Study Center: 180 cards / 3 ASWB 2026 content areas.
# Source: Form A + Form B learner stems (Practice Exams / Forms A/B untouched).

$ErrorActionPreference = 'Stop'
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Seeds = Join-Path $Root 'includes\quiz-seeds'
$OutDir = Join-Path $Root 'assets\course-materials\lcsw-aswb\study-tools'
$OutFile = Join-Path $OutDir 'flashcard-study-center.json'

$DomainDefs = @(
	@{ key = 'values-and-ethics'; label = 'Values and Ethics'; order = 1 },
	@{ key = 'assessment-and-planning'; label = 'Assessment and Planning'; order = 2 },
	@{ key = 'intervention-and-practice'; label = 'Intervention and Practice'; order = 3 }
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

# Balance to 60/60/60 = 180 by round-robin take from each domain pool.
$byDomain = @{}
foreach ($d in $DomainDefs) { $byDomain[$d.key] = New-Object System.Collections.Generic.List[hashtable] }
foreach ($c in $built) { $byDomain[$c.domain].Add($c) }

$final = New-Object System.Collections.Generic.List[hashtable]
$per = 60
foreach ($d in $DomainDefs) {
	$pool = $byDomain[$d.key]
	$take = [Math]::Min($per, $pool.Count)
	for ($i = 0; $i -lt $take; $i++) { $final.Add($pool[$i]) }
}

# Top up to 180 from remaining cards in any pool.
if ($final.Count -lt 180) {
	foreach ($d in $DomainDefs) {
		$pool = $byDomain[$d.key]
		for ($i = $per; $i -lt $pool.Count -and $final.Count -lt 180; $i++) {
			$final.Add($pool[$i])
		}
	}
}

# If still short, reassign leftovers into short domains.
if ($final.Count -lt 180) {
	$used = @{}
	foreach ($c in $final) { $used[$c.id] = $true }
	$leftover = @($built | Where-Object { -not $used.ContainsKey($_.id) })
	$li = 0
	while ($final.Count -lt 180 -and $li -lt $leftover.Count) {
		$c = $leftover[$li]
		$short = $DomainDefs | Sort-Object { @($final | Where-Object domain -eq $_.key).Count } | Select-Object -First 1
		$final.Add(@{
			id = $c.id
			front = $c.front
			back = $c.back
			domain = $short.key
			domain_label = $short.label
			domain_order = [int]$short.order
		})
		$li++
	}
}

if ($final.Count -gt 180) {
	$trimmed = New-Object System.Collections.Generic.List[hashtable]
	for ($i = 0; $i -lt 180; $i++) { $trimmed.Add($final[$i]) }
	$final = $trimmed
}

if (-not (Test-Path $OutDir)) { New-Item -ItemType Directory -Path $OutDir | Out-Null }

$domainArr = @()
foreach ($d in $DomainDefs) {
	$domainArr += @{ key = $d.key; label = $d.label; order = $d.order }
}

$payload = @{
	title = 'LCSW ASWB Clinical — Flashcard Study Center'
	expected_total = 180
	program = 'lcsw-aswb'
	source = 'Form A/B stems classified to 2026 ASWB Clinical content areas (Practice Exams untouched)'
	domains = $domainArr
	cards = @($final)
}

$json = $payload | ConvertTo-Json -Depth 8
[IO.File]::WriteAllText($OutFile, $json, [Text.UTF8Encoding]::new($false))

Write-Host ("Wrote {0} cards to {1}" -f $final.Count, $OutFile)
foreach ($d in $DomainDefs) {
	$c = @($final | Where-Object { $_.domain -eq $d.key }).Count
	Write-Host ("  {0}: {1}" -f $d.label, $c)
}
