# Convert LMFT California Clinical DOCX 17-question banks into PHP quiz seeds.
# Does not touch Form A/B or flashcard JSON.

$ErrorActionPreference = 'Stop'
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$BankDir = Join-Path $Root 'assets\course-materials\lmft-clinical\question-banks'
$OutDir = Join-Path $Root 'includes\quiz-seeds'

function Get-DocxPlainText([string]$Path) {
	$tmp = Join-Path $env:TEMP ('cta-docx-' + [guid]::NewGuid().ToString('N'))
	New-Item -ItemType Directory -Path $tmp | Out-Null
	try {
		Copy-Item -LiteralPath $Path -Destination (Join-Path $tmp 'bank.zip')
		Expand-Archive -LiteralPath (Join-Path $tmp 'bank.zip') -DestinationPath (Join-Path $tmp 'unz') -Force
		$xmlPath = Join-Path $tmp 'unz\word\document.xml'
		$xml = [IO.File]::ReadAllText($xmlPath)
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

function Get-NextNonEmpty([string[]]$Lines, [ref]$Index) {
	while ($Index.Value -lt $Lines.Count) {
		$line = $Lines[$Index.Value].Trim()
		$Index.Value++
		if ($line -ne '') { return $line }
	}
	return ''
}

function Parse-BankText([string]$Text, [int]$WorkbookNum) {
	$pad = '{0:D2}' -f $WorkbookNum
	$parts = [regex]::Split($Text, '(?=LMFT-WB' + $pad + '-QB-Q\d{3})')
	$questions = New-Object System.Collections.Generic.List[object]

	foreach ($part in $parts) {
		if ($part -notmatch ('LMFT-WB' + $pad + '-QB-Q(\d{3})')) { continue }
		$id = $Matches[0]
		$lines = @($part -split "`n" | ForEach-Object { $_.Trim() })

		$i = 0
		while ($i -lt $lines.Count -and $lines[$i] -ne $id) { $i++ }
		if ($i -ge $lines.Count) { continue }
		$i++ # skip ID
		# skip difficulty / type / concept
		$skipped = 0
		while ($i -lt $lines.Count -and $skipped -lt 6) {
			if ($lines[$i] -eq 'Question') { break }
			if ($lines[$i] -ne '') { $skipped++ }
			$i++
		}
		while ($i -lt $lines.Count -and $lines[$i] -ne 'Question') { $i++ }
		if ($i -ge $lines.Count) { continue }
		$i++ # skip "Question"

		$qLines = New-Object System.Collections.Generic.List[string]
		while ($i -lt $lines.Count) {
			$line = $lines[$i]
			if ($line -match '^[A-D]\.\s') { break }
			if ($line -match '^Correct Answer:') { break }
			if ($line -ne '' -and $line -ne 'Question ID') { [void]$qLines.Add($line) }
			$i++
		}
		$front = ($qLines -join ' ').Trim()
		if (-not $front) { continue }

		$opts = @{ A = ''; B = ''; C = ''; D = '' }
		foreach ($letter in @('A','B','C','D')) {
			if ($i -ge $lines.Count) { break }
			while ($i -lt $lines.Count -and $lines[$i] -eq '') { $i++ }
			if ($i -ge $lines.Count -or $lines[$i] -notmatch ('^' + $letter + '\.\s+(.*)$')) { break }
			$buf = New-Object System.Collections.Generic.List[string]
			[void]$buf.Add($Matches[1].Trim())
			$i++
			while ($i -lt $lines.Count) {
				$line = $lines[$i]
				if ($line -match '^[A-D]\.\s') { break }
				if ($line -match '^Correct Answer:') { break }
				if ($line -eq 'Rationales') { break }
				if ($line -ne '') { [void]$buf.Add($line) }
				$i++
			}
			$opts[$letter] = ($buf -join ' ').Trim()
		}

		while ($i -lt $lines.Count -and $lines[$i] -eq '') { $i++ }
		$correct = ''
		if ($i -lt $lines.Count -and $lines[$i] -match '^Correct Answer:\s*([A-D])') {
			$correct = $Matches[1].ToLowerInvariant()
			$i++
		}

		while ($i -lt $lines.Count -and $lines[$i] -ne 'Rationales') {
			if ($lines[$i] -match '^Correct Answer:\s*([A-D])') {
				$correct = $Matches[1].ToLowerInvariant()
			}
			$i++
		}
		if ($i -lt $lines.Count -and $lines[$i] -eq 'Rationales') { $i++ }

		$rationaleLines = New-Object System.Collections.Generic.List[string]
		$strategy = ''
		while ($i -lt $lines.Count) {
			$line = $lines[$i]
			if ($line -eq 'Question ID' -or $line -match ('^LMFT-WB' + $pad + '-QB-Q')) { break }
			if ($line -eq 'Final Quality-Control Summary') { break }
			if ($line -match '^CTA Exam Strategy\s*(.*)$') {
				$rest = $Matches[1].Trim()
				$stratBuf = New-Object System.Collections.Generic.List[string]
				if ($rest) { [void]$stratBuf.Add($rest) }
				$i++
				while ($i -lt $lines.Count) {
					$nline = $lines[$i]
					if ($nline -eq 'Question ID' -or $nline -match ('^LMFT-WB' + $pad + '-QB-Q')) { break }
					if ($nline -eq 'Final Quality-Control Summary') { break }
					if ($nline -ne '') { [void]$stratBuf.Add($nline) }
					$i++
				}
				$strategy = ($stratBuf -join ' ').Trim()
				break
			}
			if ($line -ne '') { [void]$rationaleLines.Add($line) }
			$i++
		}

		$explParts = New-Object System.Collections.Generic.List[string]
		if ($rationaleLines.Count -gt 0) {
			[void]$explParts.Add(($rationaleLines -join "`n"))
		}
		if ($strategy) {
			[void]$explParts.Add('CTA Exam Strategy: ' + $strategy)
		}
		$explanation = ($explParts -join "`n`n").Trim()

		if ($correct -notmatch '^[a-d]$') { throw ("Workbook {0} {1}: missing correct answer" -f $WorkbookNum, $id) }
		if (-not $opts.A -or -not $opts.B -or -not $opts.C -or -not $opts.D) {
			throw ("Workbook {0} {1}: missing option(s)" -f $WorkbookNum, $id)
		}

		$questions.Add([ordered]@{
			id             = $id
			question_text  = $front
			option_a       = $opts.A
			option_b       = $opts.B
			option_c       = $opts.C
			option_d       = $opts.D
			correct_option = $correct
			explanation    = $explanation
		})
	}

	return $questions
}

if (-not (Test-Path $OutDir)) {
	New-Item -ItemType Directory -Path $OutDir | Out-Null
}

$titles = @{
	1  = 'Exam Strategy and Clinical Reasoning'
	2  = 'Clinical Engagement, Intake, and Mental Status Assessment'
	3  = 'Developmental, Psychosocial, and Diversity Assessment'
	4  = 'Relational, Family-System, Trauma, and Strengths Assessment'
	5  = 'Diagnosis and Differential Diagnosis'
	6  = 'Crisis, Abuse, and Safety'
	7  = 'Planning, Progress, and Termination'
	8  = 'Family, Couple, Attachment, and Relational Interventions'
	9  = 'Trauma, Psychological, Behavioral, and Recovery Interventions'
	10 = 'Developmental, Cultural, and Contextual Interventions'
	11 = 'Theory, Groups, Referral, and Interdisciplinary Collaboration'
	12 = 'California Legal, Ethical, and Professional Practice'
}

$utf8 = New-Object System.Text.UTF8Encoding $false

for ($n = 1; $n -le 12; $n++) {
	$docx = Join-Path $BankDir ("CTA_LMFT_WB{0}_17_Question_Bank_v1.0.docx" -f $n)
	if (-not (Test-Path $docx)) { throw "Missing $docx" }

	Write-Host ("Parsing WB{0}..." -f $n)
	$text = Get-DocxPlainText $docx
	$qs = Parse-BankText $text $n
	if ($qs.Count -ne 17) {
		throw ("WB{0} expected 17 questions, got {1}" -f $n, $qs.Count)
	}

	$sb = New-Object System.Text.StringBuilder
	[void]$sb.AppendLine('<?php')
	[void]$sb.AppendLine('/**')
	[void]$sb.AppendLine((' * CTA LMFT California Clinical — Workbook {0} — 17-question practice bank.' -f $n))
	[void]$sb.AppendLine((' * Source: CTA_LMFT_WB{0}_17_Question_Bank_v1.0.docx ({1}).' -f $n, $titles[$n]))
	[void]$sb.AppendLine(' * Learner-facing fields omit Question ID / difficulty / type / concept metadata.')
	[void]$sb.AppendLine(' */')
	[void]$sb.AppendLine("if ( ! defined( 'ABSPATH' ) ) { exit; }")
	[void]$sb.AppendLine('return array(')

	foreach ($q in $qs) {
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

	$out = Join-Path $OutDir ("lmft-clinical-wb{0}-bank.php" -f $n)
	[IO.File]::WriteAllText($out, $sb.ToString(), $utf8)
	Write-Host ("Wrote {0} ({1} questions)" -f $out, $qs.Count)
}

Write-Host 'DONE'
