# Build LPCC NCMHCE Flashcard Study Center: 180 cards / 5 current-exam domains.
# Source: Form A/B v2 learner stems + admin-only answer keys (domain-tagged).
# Does not modify Form A/B, checkpoints, or workbook content.

$ErrorActionPreference = 'Stop'
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Seeds = Join-Path $Root 'includes\quiz-seeds'
$OutDir = Join-Path $Root 'assets\course-materials\lpcc-ncmhce\study-tools'
$OutFile = Join-Path $OutDir 'flashcard-study-center.json'

# Official current NCMHCE content areas (Areas of Clinical Focus is embedded, not a 6th tab).
# Approved Study Center allocation = NBCC content-area weights × 180:
# PPE 15% / IAD 25% / TP 15% / CSI 30% / CCA 15% => 27 / 45 / 27 / 54 / 27
$DomainDefs = @(
	@{ code = 'PPE'; key = 'professional-practice-and-ethics'; label = 'Professional Practice and Ethics'; order = 1; target = 27 },
	@{ code = 'IAD'; key = 'intake-assessment-and-diagnosis'; label = 'Intake, Assessment, and Diagnosis'; order = 2; target = 45 },
	@{ code = 'TP';  key = 'treatment-planning'; label = 'Treatment Planning'; order = 3; target = 27 },
	@{ code = 'CSI'; key = 'counseling-skills-and-interventions'; label = 'Counseling Skills and Interventions'; order = 4; target = 54 },
	@{ code = 'CCA'; key = 'core-counseling-attributes'; label = 'Core Counseling Attributes'; order = 5; target = 27 }
)

function Get-PhpStringValue([string]$Block, [string]$Key) {
	$pat = "'$Key'\s*=>\s*'((?:\\'|[^'])*)'"
	$m = [regex]::Match($Block, $pat, 'Singleline')
	if (-not $m.Success) { return '' }
	$v = $m.Groups[1].Value -replace "\\'", "'"
	$v = $v -replace '\\"', '"'
	return $v.Trim()
}

function Compress-FlashFront([string]$Text) {
	$t = ($Text -replace "`r`n", "`n").Trim()
	if (-not $t) { return '' }

	$paras = @($t -split "`n`n+" | ForEach-Object { $_.Trim() } | Where-Object { $_ })
	if ($paras.Count -le 1) {
		if ($t.Length -gt 520) { return ($t.Substring(0, 520).Trim() + '…') }
		return $t
	}

	$header = ''
	$bodyStart = 0
	if ($paras[0] -match '^CASE\s+\d+') {
		$header = ($paras[0] -replace '\s*\|\s*', ' — ').Trim()
		$bodyStart = 1
	}

	$question = $paras[-1]
	$contextParas = @()
	if ($paras.Count -gt ($bodyStart + 1)) {
		$contextParas = $paras[$bodyStart..($paras.Count - 2)]
	}
	$context = ($contextParas -join ' ').Trim()
	if ($context.Length -gt 280) {
		$context = $context.Substring(0, 280).Trim()
		# Prefer ending on a sentence boundary when possible.
		$cut = $context.LastIndexOf('. ')
		if ($cut -ge 120) { $context = $context.Substring(0, $cut + 1) }
		else { $context = $context + '…' }
	}

	$parts = New-Object System.Collections.Generic.List[string]
	if ($header) { $parts.Add($header) }
	if ($context) { $parts.Add($context) }
	$parts.Add($question)
	$front = ($parts -join "`n`n").Trim()
	if ($front.Length -gt 700) { $front = $front.Substring(0, 700).Trim() + '…' }
	return $front
}

function Compress-Explanation([string]$Expl) {
	if (-not $Expl) { return '' }
	$t = ($Expl -replace "`r`n", "`n").Trim()
	# Prefer the "Why the keyed answer is best" lead paragraph.
	$m = [regex]::Match($t, '(?is)Why the keyed answer is best:\s*(.+?)(?:\n\s*\n|Why [A-D] is less|Transfer rule:)')
	if ($m.Success) {
		$lead = $m.Groups[1].Value.Trim()
		if ($lead.Length -gt 420) { $lead = $lead.Substring(0, 420).Trim() + '…' }
		return $lead
	}
	$first = ($t -split "`n`n+")[0].Trim()
	if ($first.Length -gt 420) { $first = $first.Substring(0, 420).Trim() + '…' }
	return $first
}

function Read-ItemStems([string]$Path) {
	$map = @{}
	$raw = [IO.File]::ReadAllText($Path)
	$matches = [regex]::Matches($raw, "array\s*\(\s*'question_code'\s*=>\s*'([^']+)'([\s\S]*?)(?=\n\tarray\s*\(|\n\);)")
	foreach ($m in $matches) {
		$code = $m.Groups[1].Value
		$block = "array(`n`t`t'question_code' => '$code'" + $m.Groups[2].Value
		$map[$code] = @{
			question_text = (Get-PhpStringValue $block 'question_text')
			option_a      = (Get-PhpStringValue $block 'option_a')
			option_b      = (Get-PhpStringValue $block 'option_b')
			option_c      = (Get-PhpStringValue $block 'option_c')
			option_d      = (Get-PhpStringValue $block 'option_d')
		}
	}
	return $map
}

function Read-AnswerKeys([string]$Path) {
	$map = @{}
	$raw = [IO.File]::ReadAllText($Path)
	$matches = [regex]::Matches(
		$raw,
		"'(CTA-LPCC-NCMHCE-F[AB]-V2-\d{3})'\s*=>\s*array\s*\(([\s\S]*?)(?=\n\t'(?:CTA-LPCC-NCMHCE-F[AB]-V2-\d{3})'\s*=>|\n\);)"
	)
	foreach ($m in $matches) {
		$code = $m.Groups[1].Value
		$block = $m.Groups[2].Value
		$map[$code] = @{
			correct_option = (Get-PhpStringValue $block 'correct_option').ToLower()
			domain         = (Get-PhpStringValue $block 'domain').ToUpper()
			explanation    = (Get-PhpStringValue $block 'explanation')
			item_status    = (Get-PhpStringValue $block 'item_status')
		}
	}
	return $map
}

function Build-Pool($stems, $keys, [string]$FormLabel) {
	$pool = @{}
	foreach ($def in $DomainDefs) { $pool[$def.key] = New-Object System.Collections.Generic.List[object] }

	foreach ($code in ($keys.Keys | Sort-Object)) {
		if (-not $stems.ContainsKey($code)) { continue }
		$key = $keys[$code]
		$stem = $stems[$code]
		$codeKey = [string]$key.domain
		$domain = $DomainDefs | Where-Object { $_.code -eq $codeKey } | Select-Object -First 1
		if (-not $domain) { continue }

		# Prefer scored items when available, but keep field-test as filler.
		$letter = [string]$key.correct_option
		$optText = ''
		if ($letter -match '^[a-d]$') {
			$optText = [string]$stem["option_$letter"]
		}

		$front = Compress-FlashFront ([string]$stem.question_text)
		if (-not $front) { continue }

		$backParts = New-Object System.Collections.Generic.List[string]
		if ($letter -and $optText) {
			$backParts.Add(('{0}. {1}' -f $letter.ToUpper(), $optText))
		} elseif ($letter) {
			$backParts.Add(('Correct option: {0}' -f $letter.ToUpper()))
		}
		$expl = Compress-Explanation ([string]$key.explanation)
		if ($expl) { $backParts.Add($expl) }
		$back = ($backParts -join "`n`n").Trim()
		if (-not $back) { continue }

		$priority = if ([string]$key.item_status -match '(?i)scored') { 0 } else { 1 }

		$pool[$domain.key].Add([ordered]@{
			source_code  = $code
			domain       = $domain.key
			domain_label = $domain.label
			front        = $front
			back         = $back
			memory_cue   = $FormLabel
			priority     = $priority
		})
	}
	return $pool
}

Write-Host 'Loading NCMHCE Form A/B v2 stems and answer keys...'
$stemsA = Read-ItemStems (Join-Path $Seeds 'lpcc-ncmhce-form-a-v2-items.php')
$stemsB = Read-ItemStems (Join-Path $Seeds 'lpcc-ncmhce-form-b-v2-items.php')
$keysA  = Read-AnswerKeys (Join-Path $Seeds 'admin-only\lpcc-ncmhce-form-a-v2-answer-key.php')
$keysB  = Read-AnswerKeys (Join-Path $Seeds 'admin-only\lpcc-ncmhce-form-b-v2-answer-key.php')
Write-Host ("Stems A={0} B={1} Keys A={2} B={3}" -f $stemsA.Count, $stemsB.Count, $keysA.Count, $keysB.Count)

$poolA = Build-Pool $stemsA $keysA 'Form A'
$poolB = Build-Pool $stemsB $keysB 'Form B'

function Get-UniqueDomainPool($poolA, $poolB, [string]$Key) {
	$combined = New-Object System.Collections.Generic.List[object]
	foreach ($c in $poolA[$Key]) { $combined.Add($c) }
	foreach ($c in $poolB[$Key]) { $combined.Add($c) }

	$ordered = $combined | Sort-Object @{ Expression = 'priority'; Ascending = $true }, @{ Expression = 'memory_cue'; Ascending = $true }, @{ Expression = 'source_code'; Ascending = $true }
	$seen = @{}
	$unique = New-Object System.Collections.Generic.List[object]
	foreach ($c in $ordered) {
		$dk = ($c.front.Substring(0, [Math]::Min(140, $c.front.Length))).ToLowerInvariant()
		if ($seen.ContainsKey($dk)) { continue }
		$seen[$dk] = $true
		$unique.Add($c)
	}
	return $unique
}

$byDomain = @{}
$allUnique = New-Object System.Collections.Generic.List[object]
foreach ($def in $DomainDefs) {
	$byDomain[$def.key] = Get-UniqueDomainPool $poolA $poolB $def.key
	foreach ($c in $byDomain[$def.key]) { $allUnique.Add($c) }
	Write-Host ("{0}: unique pool {1}" -f $def.label, $byDomain[$def.key].Count)
}

$cards = New-Object System.Collections.Generic.List[object]
$used = @{}
$counts = @{}
foreach ($def in $DomainDefs) { $counts[$def.key] = 0 }

# Pass 1: take up to approved target from each domain's natural pool.
foreach ($def in $DomainDefs) {
	$target = [int]$def.target
	$pool = $byDomain[$def.key]
	$take = [Math]::Min($target, $pool.Count)
	for ($i = 0; $i -lt $take; $i++) {
		$c = $pool[$i]
		$used[$c.source_code] = $true
		$counts[$def.key]++
		$cards.Add([PSCustomObject]@{
			id           = ''
			domain       = [string]$def.key
			domain_label = [string]$def.label
			front        = [string]$c.front
			back         = [string]$c.back
			memory_cue   = [string]$c.memory_cue
			sort_order   = 0
		})
	}
}

# Pass 2: reassign leftover unique cards into domains still below target.
$leftover = @($allUnique | Where-Object { -not $used.ContainsKey($_.source_code) })
$li = 0
while ($li -lt $leftover.Count -and $cards.Count -lt 180) {
	$short = $null
	foreach ($def in $DomainDefs) {
		if ($counts[$def.key] -lt [int]$def.target) {
			$short = $def
			break
		}
	}
	if ($null -eq $short) { break }

	$c = $leftover[$li]
	$li++
	$used[$c.source_code] = $true
	$counts[$short.key]++
	$cards.Add([PSCustomObject]@{
		id           = ''
		domain       = [string]$short.key
		domain_label = [string]$short.label
		front        = [string]$c.front
		back         = [string]$c.back
		memory_cue   = [string]$c.memory_cue
		sort_order   = 0
	})
}

if ($cards.Count -ne 180) {
	throw ("Expected 180 cards, got {0}" -f $cards.Count)
}
foreach ($def in $DomainDefs) {
	$got = [int]$counts[$def.key]
	if ($got -ne [int]$def.target) {
		throw ("Domain {0}: expected {1}, got {2}" -f $def.label, $def.target, $got)
	}
}

# Assign stable IDs / sort orders by domain.
$final = New-Object System.Collections.Generic.List[object]
$domainRows = @()
foreach ($def in $DomainDefs) {
	$domainCards = @($cards | Where-Object { $_.domain -eq $def.key })
	$i = 0
	foreach ($c in $domainCards) {
		$i++
		$final.Add([PSCustomObject]@{
			id           = ('NCMHCE-{0}-{1:D3}' -f $def.order, $i)
			domain       = [string]$def.key
			domain_label = [string]$def.label
			front        = [string]$c.front
			back         = [string]$c.back
			memory_cue   = [string]$c.memory_cue
			sort_order   = ($def.order * 100) + $i
		})
	}
	$domainRows += [PSCustomObject]@{
		key            = [string]$def.key
		label          = [string]$def.label
		order          = [int]$def.order
		expected_count = [int]$def.target
	}
	Write-Host ("{0}: picked {1}" -f $def.label, $def.target)
}

$deck = [PSCustomObject]@{
	program         = 'lpcc-ncmhce'
	title           = 'LPCC NCMHCE - Flashcard Study Center'
	version         = '1.1'
	expected_total  = 180
	source          = 'Five-domain NCMHCE Study Center deck; approved allocation PPE 27 / IAD 45 / TP 27 / CSI 54 / CCA 27 (NBCC content-area weights x 180; Forms A/B untouched)'
	domains         = @($domainRows)
	cards           = @($final.ToArray())
}

if (-not (Test-Path $OutDir)) {
	New-Item -ItemType Directory -Path $OutDir -Force | Out-Null
}

$json = $deck | ConvertTo-Json -Depth 8
[IO.File]::WriteAllText($OutFile, $json, [Text.UTF8Encoding]::new($false))
Write-Host ("Wrote {0} ({1:N1} KB, cards={2}, domains={3})" -f $OutFile, ((Get-Item $OutFile).Length / 1KB), $cards.Count, $domainRows.Count)
