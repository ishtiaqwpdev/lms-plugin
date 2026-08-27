# Build LMFT AMFTRB National Flashcard Study Center: 180 cards / 6 official domains.
# Stops quiz-bank fallback ("WbX Bank" × 400). Does not touch Form A/B DB seeds.

$ErrorActionPreference = 'Stop'
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Seeds = Join-Path $Root 'includes\quiz-seeds'
$OutDir = Join-Path $Root 'assets\course-materials\lmft-amftrb\study-tools'
$OutFile = Join-Path $OutDir 'flashcard-study-center.json'

$DomainDefs = @(
	@{ key = 'practice-of-systemic-therapy'; label = 'The Practice of Systemic Therapy'; order = 1 },
	@{ key = 'assessing-hypothesizing-and-diagnosing'; label = 'Assessing, Hypothesizing, and Diagnosing'; order = 2 },
	@{ key = 'designing-and-conducting-treatment'; label = 'Designing and Conducting Treatment'; order = 3 },
	@{ key = 'evaluating-process-and-terminating-treatment'; label = 'Evaluating Ongoing Process and Terminating Treatment'; order = 4 },
	@{ key = 'managing-crisis-situations'; label = 'Managing Crisis Situations'; order = 5 },
	@{ key = 'ethical-legal-and-professional-standards'; label = 'Maintaining Ethical, Legal, and Professional Standards'; order = 6 }
)

# Workbook bank → primary AMFTRB domain (matches CTA_Exam_Prep_Flashcard_Center::amftrb_workbook_domain_map).
$WbDomain = @{
	1 = 'practice-of-systemic-therapy'
	2 = 'practice-of-systemic-therapy'
	3 = 'assessing-hypothesizing-and-diagnosing'
	4 = 'assessing-hypothesizing-and-diagnosing'
	5 = 'designing-and-conducting-treatment'
	6 = 'managing-crisis-situations'
	7 = 'managing-crisis-situations'
	8 = 'designing-and-conducting-treatment'
	9 = 'designing-and-conducting-treatment'
	10 = 'evaluating-process-and-terminating-treatment'
	11 = 'ethical-legal-and-professional-standards'
	12 = 'evaluating-process-and-terminating-treatment'
}

function Get-PhpStringValue([string]$Block, [string]$Key) {
	$pat = "'$Key'\s*=>\s*'((?:\\'|[^'])*)'"
	$m = [regex]::Match($Block, $pat, 'Singleline')
	if (-not $m.Success) { return '' }
	$v = $m.Groups[1].Value -replace "\\'", "'"
	return $v.Trim()
}

function Classify-AmftrbDomain([string]$Text) {
	$t = $Text.ToLowerInvariant()

	if ($t -match 'suicide|homicid|crisis|emergency|danger|abuse|violence|means|self-harm|lethality|imminent|safe handoff|level of care|acuity|tarasoff|duty to protect|medical emergency') {
		return 'managing-crisis-situations'
	}
	if ($t -match 'ethic|legal|law,|confidential|privilege|consent|disclosure|authorization|jurisdiction|documentation|teletherapy|supervisor|supervisee|policy|malpractice|record|hipaa|business practice|ai\b|technology') {
		return 'ethical-legal-and-professional-standards'
	}
	if ($t -match 'progress-measure|outcome|terminat|conclude care|revise|continuity|discrepanc|alliance rupture during review|evaluat(?:e|ion|ing) ongoing|research literacy|plan revision') {
		return 'evaluating-process-and-terminating-treatment'
	}
	if ($t -match 'diagnos|differential|mse|mental status|intake|assess(?:ment|ing)|hypothes|instrument|formulation|baseline') {
		return 'assessing-hypothesizing-and-diagnosing'
	}
	if ($t -match 'treatment[- ]plan|intervention|technique|modality|genogram|goal|contract|measurement|systemic planning|narrative|solution-focused|experiential|behavioral|cognitive|mindfulness|attachment|structural|strategic') {
		return 'designing-and-conducting-treatment'
	}
	if ($t -match 'alliance|systemic|self of the therapist|therapeutic relationship|joining|engagement|practice of systemic') {
		return 'practice-of-systemic-therapy'
	}

	# Explanation defect tag hints (Form A)
	if ($t -match 'exhibit safety') { return 'managing-crisis-situations' }
	if ($t -match 'exhibit ethics') { return 'ethical-legal-and-professional-standards' }
	if ($t -match 'exhibit eval') { return 'evaluating-process-and-terminating-treatment' }
	if ($t -match 'exhibit assess') { return 'assessing-hypothesizing-and-diagnosing' }
	if ($t -match 'exhibit plan') { return 'designing-and-conducting-treatment' }
	if ($t -match 'exhibit system') { return 'practice-of-systemic-therapy' }

	return 'practice-of-systemic-therapy'
}

function Read-FormItems([string]$Path) {
	$list = New-Object System.Collections.Generic.List[object]
	$raw = [IO.File]::ReadAllText($Path)
	$matches = [regex]::Matches($raw, "array\s*\(\s*'question_text'\s*=>\s*'((?:\\'|[^'])*)'([\s\S]*?)(?=\n\tarray\s*\(|\n\);)")
	$i = 0
	foreach ($m in $matches) {
		$i++
		$qtext = ($m.Groups[1].Value -replace "\\'", "'").Trim()
		$block = "array(\n\t\t'question_text' => '" + $m.Groups[1].Value + "'" + $m.Groups[2].Value
		$letter = (Get-PhpStringValue $block 'correct_option').ToLower()
		$opt = ''
		if ($letter -match '^[a-d]$') {
			$opt = Get-PhpStringValue $block ("option_$letter")
		}
		$expl = Get-PhpStringValue $block 'explanation'
		$domain = Classify-AmftrbDomain ("$qtext `n $expl")
		$backParts = New-Object System.Collections.Generic.List[string]
		if ($letter -and $opt) { $backParts.Add(('{0}. {1}' -f $letter.ToUpper(), $opt)) }
		elseif ($letter) { $backParts.Add(('Correct option: {0}' -f $letter.ToUpper())) }
		if ($expl) {
			# Keep first explanation paragraph only for card back brevity.
			$first = ($expl -split "`n")[0].Trim()
			$backParts.Add($first)
		}
		$back = ($backParts -join "`n`n").Trim()
		if (-not $qtext -or -not $back) { continue }
		$list.Add([ordered]@{
			front  = $qtext
			back   = $back
			domain = $domain
			source = [IO.Path]::GetFileNameWithoutExtension($Path)
			idx    = $i
		})
	}
	return $list
}

function Read-WorkbookBank([int]$WbNum) {
	$path = Join-Path $Seeds ("lmft-amftrb-wb{0}-bank.php" -f $WbNum)
	if (-not (Test-Path $path)) { return @() }
	$domain = $WbDomain[$WbNum]
	$list = New-Object System.Collections.Generic.List[object]
	$raw = [IO.File]::ReadAllText($path)
	$matches = [regex]::Matches($raw, "array\s*\(\s*'question_text'\s*=>\s*'((?:\\'|[^'])*)'([\s\S]*?)(?=\n\tarray\s*\(|\n\);)")
	$i = 0
	foreach ($m in $matches) {
		$i++
		$qtext = ($m.Groups[1].Value -replace "\\'", "'").Trim()
		$block = "array(\n\t\t'question_text' => '" + $m.Groups[1].Value + "'" + $m.Groups[2].Value
		$letter = (Get-PhpStringValue $block 'correct_option').ToLower()
		$opt = ''
		if ($letter -match '^[a-d]$') { $opt = Get-PhpStringValue $block ("option_$letter") }
		$expl = Get-PhpStringValue $block 'explanation'
		$backParts = New-Object System.Collections.Generic.List[string]
		if ($letter -and $opt) { $backParts.Add(('{0}. {1}' -f $letter.ToUpper(), $opt)) }
		if ($expl) {
			$first = (($expl -replace "\\'", "'") -split "`n")[0].Trim()
			# Strip long distractor commentary if present
			if ($first.Length -gt 400) { $first = $first.Substring(0, 400).Trim() + '…' }
			$backParts.Add($first)
		}
		$back = ($backParts -join "`n`n").Trim()
		if (-not $qtext -or -not $back) { continue }
		$list.Add([ordered]@{
			front  = $qtext
			back   = $back
			domain = $domain
			source = "wb$WbNum-bank"
			idx    = $i
		})
	}
	return $list
}

Write-Host 'Loading Form A/B and workbook banks...'
$formA = Read-FormItems (Join-Path $Seeds 'lmft-amftrb-form-a.php')
$formB = Read-FormItems (Join-Path $Seeds 'lmft-amftrb-form-b.php')
$wbPool = New-Object System.Collections.Generic.List[object]
1..12 | ForEach-Object {
	foreach ($c in (Read-WorkbookBank $_)) { $wbPool.Add($c) }
}
Write-Host ("FormA={0} FormB={1} WBBanks={2}" -f $formA.Count, $formB.Count, $wbPool.Count)

$pools = @{}
foreach ($d in $DomainDefs) { $pools[$d.key] = New-Object System.Collections.Generic.List[object] }

# Prefer Form A, then Form B, then workbook banks (domain already mapped for WB).
foreach ($c in $formA) { $pools[$c.domain].Add($c) }
foreach ($c in $formB) { $pools[$c.domain].Add($c) }
foreach ($c in $wbPool) { $pools[$c.domain].Add($c) }

$perDomain = 30
$cards = New-Object System.Collections.Generic.List[object]
$domainRows = @()

foreach ($d in $DomainDefs) {
	$pool = $pools[$d.key]
	# Deduplicate by front text
	$seen = @{}
	$unique = New-Object System.Collections.Generic.List[object]
	foreach ($c in $pool) {
		$key = ($c.front.Substring(0, [Math]::Min(120, $c.front.Length))).ToLowerInvariant()
		if ($seen.ContainsKey($key)) { continue }
		$seen[$key] = $true
		$unique.Add($c)
	}
	if ($unique.Count -lt $perDomain) {
		throw ("Domain {0} has only {1} unique cards; need {2}" -f $d.key, $unique.Count, $perDomain)
	}
	$picked = $unique | Select-Object -First $perDomain
	$n = 0
	foreach ($c in $picked) {
		$n++
		$cards.Add([PSCustomObject]@{
			id           = ('AMFTRB-{0}-{1:D3}' -f $d.order, $n)
			domain       = $d.key
			domain_label = $d.label
			front        = [string]$c.front
			back         = [string]$c.back
			memory_cue   = [string]$c.source
			sort_order   = ($d.order * 100) + $n
		})
	}
	$domainRows += [PSCustomObject]@{ key = $d.key; label = $d.label; order = $d.order }
	Write-Host ("{0}: picked {1} (unique pool {2})" -f $d.label, $perDomain, $unique.Count)
}

if ($cards.Count -ne 180) { throw ("Expected 180, got {0}" -f $cards.Count) }

$cardRows = @($cards.ToArray())
$domainObj = @($domainRows)

$deckHash = @{
	program        = 'lmft-amftrb'
	title          = 'LMFT AMFTRB National — Flashcard Study Center'
	version        = '1.0'
	expected_total = 180
	source         = 'Six-domain AMFTRB blueprint rebuild from Form A/B + workbook banks (Study Center only; exam banks unchanged)'
	domains        = $domainObj
	cards          = $cardRows
}

if (-not (Test-Path $OutDir)) { New-Item -ItemType Directory -Path $OutDir -Force | Out-Null }
$json = ($deckHash | ConvertTo-Json -Depth 8)
[IO.File]::WriteAllText($OutFile, $json, [Text.UTF8Encoding]::new($false))
Write-Host ("Wrote {0} ({1:N1} KB, cards={2})" -f $OutFile, ((Get-Item $OutFile).Length/1KB), $cardRows.Count)
