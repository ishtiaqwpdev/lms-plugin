# Build LMFT California Clinical Flashcard Study Center deck:
# 180 cards across 6 official BBS Clinical Exam domains.
# Source: Form A + Form B learner stems + admin answer keys (structure only; Forms A/B DB untouched).

$ErrorActionPreference = 'Stop'
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Seeds = Join-Path $Root 'includes\quiz-seeds'
$OutDir = Join-Path $Root 'assets\course-materials\lmft-clinical\study-tools'
$OutFile = Join-Path $OutDir 'flashcard-study-center.json'

$DomainDefs = @(
	@{ key = 'clinical-evaluation'; label = 'Clinical Evaluation'; order = 1; areaMatch = 'Area 1:' },
	@{ key = 'diagnostic-impression'; label = 'Developing a Diagnostic Impression'; order = 2; areaMatch = 'Area 2:' },
	@{ key = 'managing-crisis'; label = 'Managing Crisis Situations'; order = 3; areaMatch = 'Area 3:' },
	@{ key = 'case-conceptualization-planning'; label = 'Case Conceptualization and Planning'; order = 4; areaMatch = 'Area 4:' },
	@{ key = 'treatment'; label = 'Treatment'; order = 5; areaMatch = 'Area 5:' },
	@{ key = 'legal-ethical-obligations'; label = 'Managing Legal and Ethical Obligations'; order = 6; areaMatch = 'Area 6:' }
)

function Get-PhpStringValue([string]$Block, [string]$Key) {
	# Match 'key' => 'value' with escaped quotes inside.
	$pat = "'$Key'\s*=>\s*'((?:\\'|[^'])*)'"
	$m = [regex]::Match($Block, $pat, 'Singleline')
	if (-not $m.Success) { return '' }
	$v = $m.Groups[1].Value
	$v = $v -replace "\\'", "'"
	$v = $v -replace '\\"', '"'
	return $v.Trim()
}

function Read-ItemStems([string]$Glob) {
	$map = @{}
	Get-ChildItem -Path $Seeds -Filter $Glob -File | ForEach-Object {
		$raw = [IO.File]::ReadAllText($_.FullName)
		$matches = [regex]::Matches($raw, "array\s*\(\s*'question_code'\s*=>\s*'([^']+)'([\s\S]*?)(?=\n\tarray\s*\(|\n\);)")
		foreach ($m in $matches) {
			$code = $m.Groups[1].Value
			$block = "array(\n\t\t'question_code' => '$code'" + $m.Groups[2].Value
			$map[$code] = @{
				question_text = (Get-PhpStringValue $block 'question_text')
				option_a      = (Get-PhpStringValue $block 'option_a')
				option_b      = (Get-PhpStringValue $block 'option_b')
				option_c      = (Get-PhpStringValue $block 'option_c')
				option_d      = (Get-PhpStringValue $block 'option_d')
			}
		}
	}
	return $map
}

function Read-AnswerKeys([string]$Glob) {
	$map = @{}
	$admin = Join-Path $Seeds 'admin-only'
	Get-ChildItem -Path $admin -Filter $Glob -File | ForEach-Object {
		$raw = [IO.File]::ReadAllText($_.FullName)
		# Split on top-level question code keys
		$matches = [regex]::Matches($raw, "'(CTA-LMFT-CA-F[AB]-\d{3})'\s*=>\s*array\s*\(([\s\S]*?)(?=\n\t'(?:CTA-LMFT-CA-F[AB]-\d{3})'\s*=>|\n\);)")
		foreach ($m in $matches) {
			$code = $m.Groups[1].Value
			$block = $m.Groups[2].Value
			$correct = (Get-PhpStringValue $block 'correct_option').ToLower()
			$area = Get-PhpStringValue $block 'area'
			$rationale = ''
			if ($correct -match '^[a-d]$') {
				$letter = $correct.ToUpper()
				$rm = [regex]::Match($block, "'$letter'\s*=>\s*'((?:\\'|[^'])*)'", 'Singleline')
				if ($rm.Success) {
					$rationale = ($rm.Groups[1].Value -replace "\\'", "'" ).Trim()
				}
			}
			$map[$code] = @{
				correct_option = $correct
				area           = $area
				rationale      = $rationale
			}
		}
	}
	return $map
}

Write-Host 'Loading Form A/B stems and answer keys...'
$stemsA = Read-ItemStems 'lmft-clinical-form-a-items-*.php'
$stemsB = Read-ItemStems 'lmft-clinical-form-b-items-*.php'
$keysA  = Read-AnswerKeys 'lmft-clinical-form-a-answer-key-*.php'
$keysB  = Read-AnswerKeys 'lmft-clinical-form-b-answer-key-*.php'

Write-Host ("Stems A={0} B={1} Keys A={2} B={3}" -f $stemsA.Count, $stemsB.Count, $keysA.Count, $keysB.Count)

function Build-Pool($stems, $keys, [string]$FormLabel) {
	$pool = @{}
	foreach ($def in $DomainDefs) { $pool[$def.key] = New-Object System.Collections.Generic.List[object] }

	foreach ($code in ($keys.Keys | Sort-Object)) {
		if (-not $stems.ContainsKey($code)) { continue }
		$key = $keys[$code]
		$stem = $stems[$code]
		$area = [string]$key.area
		$domain = $null
		foreach ($def in $DomainDefs) {
			if ($area.StartsWith($def.areaMatch)) { $domain = $def; break }
		}
		if ($null -eq $domain) { continue }

		$letter = [string]$key.correct_option
		$optKey = 'option_' + $letter
		$optText = ''
		if ($stem.ContainsKey($optKey)) { $optText = [string]$stem[$optKey] }
		elseif ($stem.ContainsKey("option_$letter")) { $optText = [string]$stem["option_$letter"] }

		$front = [string]$stem.question_text
		if (-not $front) { continue }

		$backParts = New-Object System.Collections.Generic.List[string]
		if ($letter -and $optText) {
			$backParts.Add(('{0}. {1}' -f $letter.ToUpper(), $optText))
		} elseif ($letter) {
			$backParts.Add(('Correct option: {0}' -f $letter.ToUpper()))
		}
		if ($key.rationale) {
			$backParts.Add([string]$key.rationale)
		}
		$back = ($backParts -join "`n`n").Trim()
		if (-not $back) { continue }

		$pool[$domain.key].Add([ordered]@{
			id           = $code
			domain       = $domain.key
			domain_label = $domain.label
			front        = $front
			back         = $back
			memory_cue   = $FormLabel
			sort_order   = 0
			meta         = [ordered]@{ source = $FormLabel; area = $area }
		})
	}
	return $pool
}

$poolA = Build-Pool $stemsA $keysA 'Form A'
$poolB = Build-Pool $stemsB $keysB 'Form B'

$cards = New-Object System.Collections.Generic.List[object]
$perDomain = 30
$domainRows = @()

foreach ($def in $DomainDefs) {
	$combined = New-Object System.Collections.Generic.List[object]
	foreach ($c in $poolA[$def.key]) { $combined.Add($c) }
	foreach ($c in $poolB[$def.key]) { $combined.Add($c) }

	if ($combined.Count -lt $perDomain) {
		throw ("Domain {0} has only {1} cards; need {2}" -f $def.key, $combined.Count, $perDomain)
	}

	$picked = $combined | Select-Object -First $perDomain
	$i = 0
	foreach ($c in $picked) {
		$i++
		$c.sort_order = ($def.order * 100) + $i
		$c.id = ('LMFT-CLIN-{0}-{1:D3}' -f $def.order, $i)
		$cards.Add($c)
	}
	$domainRows += [ordered]@{
		key   = $def.key
		label = $def.label
		order = $def.order
	}
	Write-Host ("{0}: picked {1} (pool {2})" -f $def.label, $perDomain, $combined.Count)
}

if ($cards.Count -ne 180) {
	throw ("Expected 180 cards, got {0}" -f $cards.Count)
}

$cardRows = @()
foreach ($c in $cards) {
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

$domainObj = @()
foreach ($d in $domainRows) {
	$domainObj += [PSCustomObject]@{
		key   = [string]$d.key
		label = [string]$d.label
		order = [int]$d.order
	}
}

$deck = [PSCustomObject]@{
	program         = 'lmft-clinical'
	title           = 'LMFT California Clinical — Flashcard Study Center'
	version         = '1.0'
	expected_total  = 180
	source          = 'BBS six-domain blueprint rebuild from Form A/B keyed items (Study Center only; exam banks unchanged)'
	domains         = $domainObj
	cards           = $cardRows
}

if (-not (Test-Path $OutDir)) {
	New-Item -ItemType Directory -Path $OutDir -Force | Out-Null
}

$json = $deck | ConvertTo-Json -Depth 8
[IO.File]::WriteAllText($OutFile, $json, [Text.UTF8Encoding]::new($false))

Write-Host ("Wrote {0} ({1:N1} KB, cards={2}, domains={3})" -f $OutFile, ((Get-Item $OutFile).Length/1KB), $cardRows.Count, $domainObj.Count)
