# Convert LMFT California Law & Ethics Candidate Forms + Controlled Keys into PHP quiz seeds.
# Does not touch Practice A/B, comprehensive final, flashcards, or workbook lesson HTML.

$ErrorActionPreference = 'Stop'
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$AssessDir = Join-Path $Root 'assets\course-materials\lmft-law-ethics\assessments'
$KeyDir = Join-Path $Root 'assets\course-materials\lmft-law-ethics\rationales'
$OutDir = Join-Path $Root 'includes\quiz-seeds'

$Expected = @{
	1 = 119; 2 = 102; 3 = 102; 4 = 85; 5 = 85; 6 = 85; 7 = 51; 8 = 68; 9 = 68
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

function Get-JoinedText([string[]]$Lines, [int]$Start, [int]$End) {
	$buf = New-Object System.Collections.Generic.List[string]
	for ($i = $Start; $i -lt $End -and $i -lt $Lines.Count; $i++) {
		$line = $Lines[$i].Trim()
		if ($line) { [void]$buf.Add($line) }
	}
	return (($buf -join ' ') -replace '\s+', ' ').Trim()
}

function Find-OptionIndex([string[]]$Lines, [string]$Letter, [int]$From, [int]$To) {
	$pat = '^' + $Letter + '\.\s+(.*)$'
	for ($i = $From; $i -lt $To -and $i -lt $Lines.Count; $i++) {
		if ($Lines[$i].Trim() -match $pat) { return $i }
	}
	return -1
}

function Parse-CandidateForm([string]$Text, [int]$Expect) {
	$cut = [regex]::Match($Text, '(?m)^Chapter\s+\d+\s+Assessment\b')
	if (-not $cut.Success) { throw 'Candidate form is missing Chapter N Assessment heading' }
	$body = $Text.Substring($cut.Index)
	$parts = [regex]::Split($body, '(?m)^(?=Question\s+\d+\s*$)')
	$items = New-Object System.Collections.Generic.List[object]

	foreach ($part in $parts) {
		if ($part -notmatch '(?s)^Question\s+(\d+)\s*\n') { continue }
		$num = [int]$Matches[1]
		$lines = @($part -split "`n" | ForEach-Object { $_.TrimEnd() })
		$a = Find-OptionIndex $lines 'A' 1 $lines.Count
		$b = Find-OptionIndex $lines 'B' 1 $lines.Count
		$c = Find-OptionIndex $lines 'C' 1 $lines.Count
		$d = Find-OptionIndex $lines 'D' 1 $lines.Count
		if ($a -lt 0 -or $b -lt 0 -or $c -lt 0 -or $d -lt 0) {
			throw ("Form item {0} (label Q{1}): missing A-D options" -f ($items.Count + 1), $num)
		}

		$stem = Get-JoinedText $lines 1 $a
		$optA = (Get-JoinedText $lines $a $b) -replace '^A\.\s*', ''
		$optB = (Get-JoinedText $lines $b $c) -replace '^B\.\s*', ''
		$optC = (Get-JoinedText $lines $c $d) -replace '^C\.\s*', ''
		$endD = $lines.Count
		for ($i = $d + 1; $i -lt $lines.Count; $i++) {
			$t = $lines[$i].Trim()
			if ($t -match '^Chapter\s+\d+\s+Assessment\b') { $endD = $i; break }
			if ($t -match '^Candidate Reflection') { $endD = $i; break }
			if ($t -match '^End of\b') { $endD = $i; break }
		}
		$optD = (Get-JoinedText $lines $d $endD) -replace '^D\.\s*', ''
		$optD = $optD -replace '\s*Candidate Reflection and Remediation[\s\S]*$', ''

		if (-not $stem -or -not $optA -or -not $optB -or -not $optC -or -not $optD) {
			throw ("Form item {0} (label Q{1}): empty stem or option" -f ($items.Count + 1), $num)
		}

		$items.Add([ordered]@{
			num            = $num
			question_text  = $stem
			option_a       = $optA
			option_b       = $optB
			option_c       = $optC
			option_d       = $optD
			correct_option = ''
			explanation    = ''
		})
	}

	if ($items.Count -ne $Expect) {
		throw ("Form expected {0} items, got {1}" -f $Expect, $items.Count)
	}
	return $items
}

function Parse-AnswerKey([string]$Text, [int]$Expect) {
	$parts = [regex]::Split($Text, '(?m)^(?=Question\s+\d+\s+\|)')
	$items = New-Object System.Collections.Generic.List[object]

	foreach ($part in $parts) {
		if ($part -notmatch '(?s)^Question\s+(\d+)\s+\|') { continue }
		$num = [int]$Matches[1]
		$correct = ''
		if ($part -match '(?m)^Correct Answer:\s*([A-D])\b') {
			$correct = $Matches[1].ToLowerInvariant()
		}
		$expl = ''
		$rIdx = $part.IndexOf('Detailed Option-by-Option Rationales')
		if ($rIdx -ge 0) {
			$rest = $part.Substring($rIdx + 'Detailed Option-by-Option Rationales'.Length)
			$stop = [regex]::Match($rest, '(?m)^(?:Question\s+\d+\s+\||Chapter\s+\d+:\s+Controlled|Consolidated Answer Key|End of\b)')
			if ($stop.Success) { $rest = $rest.Substring(0, $stop.Index) }
			$lines = @($rest -split "`n" | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })
			$expl = ($lines -join "`n").Trim()
		}

		if ($correct -notmatch '^[a-d]$') {
			throw ("Key item {0} (label Q{1}): missing Correct Answer letter" -f ($items.Count + 1), $num)
		}
		if (-not $expl) {
			throw ("Key item {0} (label Q{1}): missing rationales" -f ($items.Count + 1), $num)
		}

		$items.Add([ordered]@{
			num            = $num
			correct_option = $correct
			explanation    = $expl
		})
	}

	if ($items.Count -ne $Expect) {
		throw ("Key expected {0} items, got {1}" -f $Expect, $items.Count)
	}
	return $items
}

if (-not (Test-Path $OutDir)) {
	New-Item -ItemType Directory -Path $OutDir | Out-Null
}

$utf8 = New-Object System.Text.UTF8Encoding $false

for ($n = 1; $n -le 9; $n++) {
	$expect = [int]$Expected[$n]
	$formFiles = @(Get-ChildItem -Path $AssessDir -Filter ("CTA_LMFT_Law_and_Ethics_EP_WB{0}_*_Candidate_Form_*.docx" -f $n))
	$keyFiles = @(Get-ChildItem -Path $KeyDir -Filter ("CTA_LMFT_Law_and_Ethics_EP_WB{0}_*_Controlled_Answer_Key_*.docx" -f $n))
	if ($formFiles.Count -lt 1) { throw ("Missing candidate form for WB{0}" -f $n) }
	if ($keyFiles.Count -lt 1) { throw ("Missing answer key for WB{0}" -f $n) }

	Write-Host ("Parsing WB{0}..." -f $n)
	$form = Parse-CandidateForm (Get-DocxPlainText $formFiles[0].FullName) $expect
	$key = Parse-AnswerKey (Get-DocxPlainText $keyFiles[0].FullName) $expect

	$ordered = New-Object System.Collections.Generic.List[object]
	for ($q = 0; $q -lt $expect; $q++) {
		$row = $form[$q]
		$row.correct_option = $key[$q].correct_option
		$row.explanation = $key[$q].explanation
		$ordered.Add($row)
	}

	$sb = New-Object System.Text.StringBuilder
	[void]$sb.AppendLine('<?php')
	[void]$sb.AppendLine('/**')
	[void]$sb.AppendLine((' * CTA LMFT Law & Ethics EP - Workbook {0} Assessment ({1} questions).' -f $n, $expect))
	[void]$sb.AppendLine((' * Source: {0} + {1}.' -f $formFiles[0].Name, $keyFiles[0].Name))
	[void]$sb.AppendLine(' */')
	[void]$sb.AppendLine("if ( ! defined( 'ABSPATH' ) ) { exit; }")
	[void]$sb.AppendLine('return array(')

	foreach ($q in $ordered) {
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
	$out = Join-Path $OutDir ("lmft-law-ethics-wb{0}.php" -f $n)
	[IO.File]::WriteAllText($out, $sb.ToString(), $utf8)
	Write-Host ("Wrote {0} ({1} questions)" -f $out, $ordered.Count)
}

Write-Host 'DONE'
