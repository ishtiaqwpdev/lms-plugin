# Build LMFT Law & Ethics online Workbook Practice Bank seeds (wb1-wb9)
# from Controlled Answer Key DOCX files. Does not modify Practice Exams / flashcards / lesson HTML.

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem

$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$RationaleDir = Join-Path $Root 'assets\course-materials\lmft-law-ethics\rationales'
$OutDir = Join-Path $Root 'includes\quiz-seeds'

$Expect = @{
	1 = 119; 2 = 102; 3 = 102; 4 = 85; 5 = 85; 6 = 85; 7 = 51; 8 = 68; 9 = 68
}

function Get-DocxPlainText([string]$DocxPath) {
	$tmp = Join-Path $env:TEMP ("cta-lmft-le-{0}" -f [Guid]::NewGuid().ToString('N'))
	if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
	[System.IO.Compression.ZipFile]::ExtractToDirectory((Resolve-Path $DocxPath), $tmp)
	$xml = [IO.File]::ReadAllText((Join-Path $tmp 'word\document.xml'))
	Remove-Item $tmp -Recurse -Force
	$t = [regex]::Replace($xml, '</w:p>', "`n")
	$t = [regex]::Replace($t, '<[^>]+>', '')
	$t = [System.Net.WebUtility]::HtmlDecode($t)
	$t = $t -replace "`r`n", "`n"
	return $t.Trim()
}

function Escape-PhpSingle([string]$s) {
	if ($null -eq $s) { return '' }
	($s -replace '\\', '\\\\') -replace "'", "\\'"
}

function Parse-AnswerKey([string]$Text) {
	$questions = New-Object System.Collections.Generic.List[hashtable]
	# Split on "Question N | ..." headers used in controlled keys.
	$parts = [regex]::Split($Text, '(?m)(?=^Question\s+\d+\s*\|)')
	foreach ($part in $parts) {
		if ($part -notmatch '(?m)^Question\s+\d+\s*\|') { continue }

		$m = [regex]::Match(
			$part,
			'(?s)^Question\s+\d+\s*\|[^\n]*\n(?:Difficulty:[^\n]*\n)?(.+?)\r?\nA\.\s*(.+?)\r?\nB\.\s*(.+?)\r?\nC\.\s*(.+?)\r?\nD\.\s*(.+?)\r?\nCorrect Answer:\s*([A-Da-d])\s*\r?\n(?:Detailed Option-by-Option Rationales\s*\r?\n)?(.+?)(?=\r?\nRemediation:|\r?\nQuestion\s+\d+\s*\||\z)'
		)
		if (-not $m.Success) { continue }

		$stem = ($m.Groups[1].Value -replace '\s+', ' ').Trim()
		# Drop leftover metadata lines if Difficulty line was missing and meta stuck to stem.
		if ($stem -match '^(Difficulty|Type|Primary concept):') {
			$stem = ($stem -replace '(?s)^.*?\n', '').Trim()
		}

		$expl = $m.Groups[7].Value.Trim()
		# Keep option rationales + CTA Exam Strategy; drop remediation footer if captured.
		$expl = ($expl -split '(?m)^Remediation:\s*')[0].Trim()

		if (-not $stem) { continue }

		$questions.Add(@{
			question_text  = $stem
			option_a       = ($m.Groups[2].Value -replace '\s+', ' ').Trim()
			option_b       = ($m.Groups[3].Value -replace '\s+', ' ').Trim()
			option_c       = ($m.Groups[4].Value -replace '\s+', ' ').Trim()
			option_d       = ($m.Groups[5].Value -replace '\s+', ' ').Trim()
			correct_option = $m.Groups[6].Value.ToLowerInvariant()
			explanation    = $expl
		})
	}
	return $questions
}

function Write-SeedFile([int]$WbNum, [System.Collections.Generic.List[hashtable]]$Questions) {
	$out = Join-Path $OutDir ("lmft-law-ethics-wb{0}.php" -f $WbNum)
	$sb = New-Object System.Text.StringBuilder
	[void]$sb.AppendLine('<?php')
	[void]$sb.AppendLine('/**')
	[void]$sb.AppendLine((" * CTA LMFT California Law & Ethics - Workbook {0} Assessment ({1} questions)." -f $WbNum, $Questions.Count))
	[void]$sb.AppendLine(' * Built from approved Controlled Answer Key DOCX (online Practice Bank).')
	[void]$sb.AppendLine(' */')
	[void]$sb.AppendLine("if ( ! defined( 'ABSPATH' ) ) { exit; }")
	[void]$sb.AppendLine('return array(')

	foreach ($q in $Questions) {
		[void]$sb.AppendLine('	array(')
		[void]$sb.AppendLine("		'question_text'  => '" + (Escape-PhpSingle $q.question_text) + "',")
		[void]$sb.AppendLine("		'option_a'       => '" + (Escape-PhpSingle $q.option_a) + "',")
		[void]$sb.AppendLine("		'option_b'       => '" + (Escape-PhpSingle $q.option_b) + "',")
		[void]$sb.AppendLine("		'option_c'       => '" + (Escape-PhpSingle $q.option_c) + "',")
		[void]$sb.AppendLine("		'option_d'       => '" + (Escape-PhpSingle $q.option_d) + "',")
		[void]$sb.AppendLine("		'correct_option' => '" + $q.correct_option + "',")
		[void]$sb.AppendLine("		'explanation'    => '" + (Escape-PhpSingle $q.explanation) + "',")
		[void]$sb.AppendLine('	),')
	}

	[void]$sb.AppendLine(');')
	[IO.File]::WriteAllText($out, $sb.ToString(), [Text.UTF8Encoding]::new($false))
	return $out
}

Write-Host 'Building LMFT Law & Ethics workbook bank seeds...'
for ($wb = 1; $wb -le 9; $wb++) {
	$docx = Get-ChildItem -Path $RationaleDir -Filter ("*_WB{0}_*Rationales*.docx" -f $wb) -File | Select-Object -First 1
	if (-not $docx) { throw ("Missing rationale DOCX for WB{0}" -f $wb) }

	$text = Get-DocxPlainText $docx.FullName
	$qs = Parse-AnswerKey $text
	$need = [int]$Expect[$wb]
	if ($qs.Count -ne $need) {
		throw ("WB{0}: expected {1} questions, parsed {2} from {3}" -f $wb, $need, $qs.Count, $docx.Name)
	}

	$out = Write-SeedFile $wb $qs
	Write-Host ("OK wb{0}: {1} q -> {2}" -f $wb, $qs.Count, (Split-Path $out -Leaf))
}
Write-Host 'Done.'
