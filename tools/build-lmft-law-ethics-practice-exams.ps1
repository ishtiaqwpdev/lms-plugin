# Convert LMFT California Law & Ethics Practice A/B + Comprehensive Final
# Controlled Answer Keys into PHP quiz seeds.
# Does not touch workbook lesson HTML, workbook Practice Banks, or flashcards.

$ErrorActionPreference = 'Stop'
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Base = Join-Path $Root 'assets\course-materials\lmft-law-ethics'
$OutDir = Join-Path $Root 'includes\quiz-seeds'

$Exams = @(
	@{
		Key        = 'practice-a'
		Expect     = 50
		File       = 'lmft-law-ethics-practice-a.php'
		Label      = 'Practice Examination A'
		KeyGlob    = 'practice-a\*Controlled_Answer_Key*.docx'
		FormGlob   = 'practice-a\*Learner_Booklet*.docx'
	}
	@{
		Key        = 'practice-b'
		Expect     = 50
		File       = 'lmft-law-ethics-practice-b.php'
		Label      = 'Practice Examination B'
		KeyGlob    = 'practice-b\*Controlled_Answer_Key*.docx'
		FormGlob   = 'practice-b\*Learner_Booklet*.docx'
	}
	@{
		Key        = 'comprehensive-final'
		Expect     = 100
		File       = 'lmft-law-ethics-comprehensive-final.php'
		Label      = 'Comprehensive Final Examination'
		KeyGlob    = 'comprehensive-final\*Controlled_Answer_Key*.docx'
		FormGlob   = 'comprehensive-final\*Learner_Booklet*.docx'
	}
)

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
		$text = $text -replace "`r`n", "`n"
		return $text
	} finally {
		Remove-Item -LiteralPath $tmp -Recurse -Force -ErrorAction SilentlyContinue
	}
}

function ConvertTo-PhpSingleQuoted([string]$Value) {
	$Value = [string]$Value
	$Value = $Value.Trim()
	$Value = $Value -replace '\\', '\\'
	$Value = $Value -replace "'", "\'"
	return $Value
}

function Repair-SentenceSpacing([string]$Value) {
	$Value = [string]$Value
	# DOCX export often glues the next sentence or list item ("client.Which", "therapist:Accepts").
	$Value = [regex]::Replace($Value, '(?<=[\.!\?:;])(?=[A-Z])', ' ')
	# List items concatenated without punctuation ("garageElectronic records"), but keep tokens like ePHI.
	$Value = [regex]::Replace($Value, '(?<=[a-z])(?=[A-Z][a-z])', ' ')
	return $Value
}

function Get-JoinedText([string[]]$Lines, [int]$Start, [int]$End) {
	$buf = New-Object System.Collections.Generic.List[string]
	for ($i = $Start; $i -lt $End -and $i -lt $Lines.Count; $i++) {
		$line = $Lines[$i].Trim()
		if ($line) { [void]$buf.Add($line) }
	}
	return (Repair-SentenceSpacing (($buf -join ' ') -replace '\s+', ' ')).Trim()
}

function Find-OptionIndex([string[]]$Lines, [string]$Letter, [int]$From, [int]$To) {
	$pat = '^' + $Letter + '\.\s+(.*)$'
	for ($i = $From; $i -lt $To -and $i -lt $Lines.Count; $i++) {
		if ($Lines[$i].Trim() -match $pat) { return $i }
	}
	return -1
}

function Parse-PracticeExamKey([string]$Text, [int]$Expect, [string]$Label) {
	$cut = $Text.IndexOf('Detailed Rationales and Remediation')
	if ($cut -lt 0) {
		throw ("{0}: missing Detailed Rationales and Remediation heading" -f $Label)
	}

	$body = $Text.Substring($cut)
	$parts = [regex]::Split($body, '(?m)^(?=\d+\.\s)')
	$items = New-Object System.Collections.Generic.List[object]

	foreach ($part in $parts) {
		if ($part -notmatch '(?s)^(\d+)\.\s') { continue }
		$num = [int]$Matches[1]
		# Skip compact-key leftovers or heading fragments that are not items.
		if ($part -notmatch '(?m)^Correct Answer:\s*[A-D]\b') { continue }
		if ($part -notmatch '(?m)^A\.\s+') { continue }

		$lines = @($part -split "`n" | ForEach-Object { $_.TrimEnd() })
		# First line is "N. stem..."
		if ($lines.Count -gt 0) {
			$lines[0] = [regex]::Replace($lines[0], '^\d+\.\s+', '')
		}

		$a = Find-OptionIndex $lines 'A' 0 $lines.Count
		$b = Find-OptionIndex $lines 'B' 0 $lines.Count
		$c = Find-OptionIndex $lines 'C' 0 $lines.Count
		$d = Find-OptionIndex $lines 'D' 0 $lines.Count
		if ($a -lt 0 -or $b -lt 0 -or $c -lt 0 -or $d -lt 0) {
			throw ("{0} item {1}: missing A-D options" -f $Label, $num)
		}

		$correctLine = -1
		for ($i = $d; $i -lt $lines.Count; $i++) {
			if ($lines[$i].Trim() -match '^Correct Answer:\s*([A-D])\b') {
				$correctLine = $i
				break
			}
		}
		if ($correctLine -lt 0) {
			throw ("{0} item {1}: missing Correct Answer letter" -f $Label, $num)
		}
		$null = $lines[$correctLine].Trim() -match '^Correct Answer:\s*([A-D])\b'
		$correct = $Matches[1].ToLowerInvariant()

		$stem = Get-JoinedText $lines 0 $a
		$optA = (Get-JoinedText $lines $a $b) -replace '^A\.\s*', ''
		$optB = (Get-JoinedText $lines $b $c) -replace '^B\.\s*', ''
		$optC = (Get-JoinedText $lines $c $d) -replace '^C\.\s*', ''
		$optD = (Get-JoinedText $lines $d $correctLine) -replace '^D\.\s*', ''

		$explLines = New-Object System.Collections.Generic.List[string]
		$inRationale = $false
		for ($i = $correctLine + 1; $i -lt $lines.Count; $i++) {
			$t = $lines[$i].Trim()
			if ($t -match '^Option-by-Option Rationales\b') { $inRationale = $true; continue }
			if ($t -match '^Remediation:') { break }
			if ($t -match '^Internal control:') { break }
			if ($t -match '^Detailed Rationales') { break }
			if (-not $t) { continue }
			if ($t -match '^CTA EXAM STRATEGY:\s*(.*)$') {
				$strategy = $Matches[1].Trim()
				if ($explLines.Count -gt 0) { [void]$explLines.Add('') }
				[void]$explLines.Add(('CTA Exam Strategy: {0}' -f $strategy))
				continue
			}
			if ($inRationale) {
				[void]$explLines.Add($t)
			}
		}
		$expl = ($explLines -join "`n").Trim()
		$expl = $expl -replace "`n{3,}", "`n`n"

		if (-not $stem -or -not $optA -or -not $optB -or -not $optC -or -not $optD) {
			throw ("{0} item {1}: empty stem or option" -f $Label, $num)
		}
		if ($correct -notmatch '^[a-d]$') {
			throw ("{0} item {1}: invalid correct letter" -f $Label, $num)
		}
		if (-not $expl) {
			throw ("{0} item {1}: missing option rationales" -f $Label, $num)
		}

		$items.Add([ordered]@{
			num            = $num
			question_text  = $stem
			option_a       = $optA
			option_b       = $optB
			option_c       = $optC
			option_d       = $optD
			correct_option = $correct
			explanation    = $expl
		})
	}

	if ($items.Count -ne $Expect) {
		throw ("{0}: expected {1} items, got {2}" -f $Label, $Expect, $items.Count)
	}

	for ($i = 0; $i -lt $items.Count; $i++) {
		if ([int]$items[$i].num -ne ($i + 1)) {
			throw ("{0}: expected sequential item {1}, got {2}" -f $Label, ($i + 1), $items[$i].num)
		}
	}

	$stems = @{}
	foreach ($row in $items) {
		$key = [string]$row.question_text
		if ($stems.ContainsKey($key)) {
			throw ("{0}: duplicate stem at item {1}" -f $Label, $row.num)
		}
		$stems[$key] = $true
	}

	return $items
}

if (-not (Test-Path $OutDir)) {
	New-Item -ItemType Directory -Path $OutDir | Out-Null
}

$utf8 = New-Object System.Text.UTF8Encoding $false

foreach ($exam in $Exams) {
	$keyFiles = @(Get-ChildItem -Path (Join-Path $Base ($exam.KeyGlob.Split('\')[0])) -Filter ($exam.KeyGlob.Split('\')[-1]))
	$formFiles = @(Get-ChildItem -Path (Join-Path $Base ($exam.FormGlob.Split('\')[0])) -Filter ($exam.FormGlob.Split('\')[-1]))
	if ($keyFiles.Count -lt 1) { throw ("Missing controlled key for {0}" -f $exam.Label) }
	if ($formFiles.Count -lt 1) { throw ("Missing learner booklet for {0}" -f $exam.Label) }

	Write-Host ("Parsing {0}..." -f $exam.Label)
	$items = Parse-PracticeExamKey (Get-DocxPlainText $keyFiles[0].FullName) $exam.Expect $exam.Label

	$sb = New-Object System.Text.StringBuilder
	[void]$sb.AppendLine('<?php')
	[void]$sb.AppendLine('/**')
	[void]$sb.AppendLine((' * CTA LMFT Law & Ethics EP - {0} ({1} questions).' -f $exam.Label, $exam.Expect))
	[void]$sb.AppendLine((' * Source: {0} (stems/options/keys/rationales). Learner booklet: {1}.' -f $keyFiles[0].Name, $formFiles[0].Name))
	[void]$sb.AppendLine(' */')
	[void]$sb.AppendLine("if ( ! defined( 'ABSPATH' ) ) { exit; }")
	[void]$sb.AppendLine('return array(')

	foreach ($q in $items) {
		[void]$sb.AppendLine("`tarray(")
		[void]$sb.AppendLine(("`t`t'question_text'  => '{0}'," -f (ConvertTo-PhpSingleQuoted $q.question_text)))
		[void]$sb.AppendLine(("`t`t'option_a'       => '{0}'," -f (ConvertTo-PhpSingleQuoted $q.option_a)))
		[void]$sb.AppendLine(("`t`t'option_b'       => '{0}'," -f (ConvertTo-PhpSingleQuoted $q.option_b)))
		[void]$sb.AppendLine(("`t`t'option_c'       => '{0}'," -f (ConvertTo-PhpSingleQuoted $q.option_c)))
		[void]$sb.AppendLine(("`t`t'option_d'       => '{0}'," -f (ConvertTo-PhpSingleQuoted $q.option_d)))
		[void]$sb.AppendLine(("`t`t'correct_option' => '{0}'," -f $q.correct_option))
		$expl = ConvertTo-PhpSingleQuoted $q.explanation
		$expl = $expl -replace "`n", "`n`t`t"
		[void]$sb.AppendLine(("`t`t'explanation'    => '{0}'," -f $expl))
		[void]$sb.AppendLine("`t),")
	}

	[void]$sb.AppendLine(');')
	$out = Join-Path $OutDir $exam.File
	[IO.File]::WriteAllText($out, $sb.ToString(), $utf8)
	Write-Host ("Wrote {0} ({1} questions)" -f $out, $items.Count)
}

Write-Host 'DONE'
