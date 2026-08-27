# Build LPCC NCMHCE Flashcard Study Center: 180 cards / 5 current-exam domains.
# Source: Form A/B v2 learner stems + admin-only answer keys (domain-tagged).
# Does not modify Form A/B, checkpoints, or workbook content.

$ErrorActionPreference = 'Stop'
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Seeds = Join-Path $Root 'includes\quiz-seeds'
$OutDir = Join-Path $Root 'assets\course-materials\lpcc-ncmhce\study-tools'
$OutFile = Join-Path $OutDir 'flashcard-study-center.json'

# Official current NCMHCE content areas (Areas of Clinical Focus is embedded, not a 6th tab).
$DomainDefs = @(
	@{ code = 'PPE'; key = 'professional-practice-and-ethics'; label = 'Professional Practice and Ethics'; order = 1 },
	@{ code = 'IAD'; key = 'intake-assessment-and-diagnosis'; label = 'Intake, Assessment, and Diagnosis'; order = 2 },
	@{ code = 'TP';  key = 'treatment-planning'; label = 'Treatment Planning'; order = 3 },
	@{ code = 'CSI'; key = 'counseling-skills-and-interventions'; label = 'Counseling Skills and Interventions'; order = 4 },
	@{ code = 'CCA'; key = 'core-counseling-attributes'; label = 'Core Counseling Attributes'; order = 5 }
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

# Approved allocation (NBCC NCMHCE weights on 180 cards): 15/25/15/30/15.
$TargetCounts = @{
	'professional-practice-and-ethics'     = 27
	'intake-assessment-and-diagnosis'      = 45
	'treatment-planning'                   = 27
	'counseling-skills-and-interventions'  = 54
	'core-counseling-attributes'           = 27
}
$cards = New-Object System.Collections.Generic.List[object]
$domainRows = @()

foreach ($def in $DomainDefs) {
	$need = [int]$TargetCounts[$def.key]
	$combined = New-Object System.Collections.Generic.List[object]
	foreach ($c in $poolA[$def.key]) { $combined.Add($c) }
	foreach ($c in $poolB[$def.key]) { $combined.Add($c) }

	# Prefer scored, then Form A order, then Form B; dedupe by compressed front.
	$ordered = $combined | Sort-Object @{ Expression = 'priority'; Ascending = $true }, @{ Expression = 'memory_cue'; Ascending = $true }, @{ Expression = 'source_code'; Ascending = $true }
	$seen = @{}
	$unique = New-Object System.Collections.Generic.List[object]
	foreach ($c in $ordered) {
		$dk = ($c.front.Substring(0, [Math]::Min(140, $c.front.Length))).ToLowerInvariant()
		if ($seen.ContainsKey($dk)) { continue }
		$seen[$dk] = $true
		$unique.Add($c)
	}

	if ($unique.Count -lt $need) {
		throw ("Domain {0} has only {1} unique cards; need {2}" -f $def.key, $unique.Count, $need)
	}

	$picked = $unique | Select-Object -First $need
	$i = 0
	foreach ($c in $picked) {
		$i++
		$cards.Add([PSCustomObject]@{
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
		key   = [string]$def.key
		label = [string]$def.label
		order = [int]$def.order
	}
	Write-Host ("{0}: picked {1} (unique pool {2})" -f $def.label, $need, $unique.Count)
}

if ($cards.Count -ne 180) {
	throw ("Expected 180 cards, got {0}" -f $cards.Count)
}

$deck = [PSCustomObject]@{
	program         = 'lpcc-ncmhce'
	title           = 'LPCC NCMHCE — Flashcard Study Center'
	version         = '1.0'
	expected_total  = 180
	source          = 'Five-domain current NCMHCE blueprint rebuild from Form A/B v2 keyed items; approved allocation 27/45/27/54/27 (Study Center only; exam banks unchanged)'
	domains         = @($domainRows)
	cards           = @($cards.ToArray())
}

if (-not (Test-Path $OutDir)) {
	New-Item -ItemType Directory -Path $OutDir -Force | Out-Null
}

$json = $deck | ConvertTo-Json -Depth 8
[IO.File]::WriteAllText($OutFile, $json, [Text.UTF8Encoding]::new($false))
Write-Host ("Wrote {0} ({1:N1} KB, cards={2}, domains={3})" -f $OutFile, ((Get-Item $OutFile).Length / 1KB), $cards.Count, $domainRows.Count)
