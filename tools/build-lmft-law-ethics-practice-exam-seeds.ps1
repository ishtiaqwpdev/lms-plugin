# Build LMFT Law & Ethics Practice Exam seeds (Practice A/B + Comprehensive Final)
# from Controlled Answer Key DOCX. Does not touch workbook banks, lessons, or flashcards.

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem

$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Mat = Join-Path $Root 'assets\course-materials\lmft-law-ethics'
$OutDir = Join-Path $Root 'includes\quiz-seeds'

$Jobs = @(
	@{
		Key = 'practice-a'
		File = 'lmft-law-ethics-practice-a.php'
		Title = 'Practice Examination A'
		Expect = 50
		Glob = 'practice-a\*Controlled_Answer_Key*.docx'
	},
	@{
		Key = 'practice-b'
		File = 'lmft-law-ethics-practice-b.php'
		Title = 'Practice Examination B'
		Expect = 50
		Glob = 'practice-b\*Controlled_Answer_Key*.docx'
	},
	@{
		Key = 'comprehensive-final'
		File = 'lmft-law-ethics-comprehensive-final.php'
		Title = 'Comprehensive Final Examination'
		Expect = 100
		Glob = 'comprehensive-final\*Controlled_Answer_Key*.docx'
	}
)

function Get-DocxPlainText([string]$DocxPath) {
	$tmp = Join-Path $env:TEMP ("cta-lmft-le-exam-" + [Guid]::NewGuid().ToString('N'))
	if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
	[System.IO.Compression.ZipFile]::ExtractToDirectory((Resolve-Path $DocxPath), $tmp)
	$xml = [IO.File]::ReadAllText((Join-Path $tmp 'word\document.xml'))
	Remove-Item $tmp -Recurse -Force
	$t = [regex]::Replace($xml, '</w:p>', "`n")
	$t = [regex]::Replace($t, '<[^>]+>', '')
	$t = [System.Net.WebUtility]::HtmlDecode($t)
	return (($t -replace "`r`n", "`n").Trim())
}

function Escape-PhpSingle([string]$s) {
	if ($null -eq $s) { return '' }
	($s -replace '\\', '\\\\') -replace "'", "\\'"
}

function Parse-ExamKey([string]$Text) {
	# Prefer detailed rationales section when present.
	$start = $Text.IndexOf('Detailed Rationales and Remediation')
	if ($start -lt 0) { $start = $Text.IndexOf('Detailed Rationales') }
	$body = if ($start -ge 0) { $Text.Substring($start) } else { $Text }

	$questions = New-Object System.Collections.Generic.List[hashtable]
	# Split on numbered stems: "1. " ... before next "N. " or end.
	$parts = [regex]::Split($body, '(?m)(?=^\d+\.\s)')
	foreach ($part in $parts) {
		$m = [regex]::Match(
			$part,
			'(?ms)^\d+\.\s*(.+?)\r?\nA\.\s*(.+?)\r?\nB\.\s*(.+?)\r?\nC\.\s*(.+?)\r?\nD\.\s*(.+?)\r?\nCorrect Answer:\s*([A-Da-d])\s*(?:\r?\n(?:Option-by-Option Rationales\s*\r?\n)?(.+?))?(?=\r?\n\d+\.\s|\z)'
		)
		if (-not $m.Success) { continue }

		$stem = ($m.Groups[1].Value -replace '\s+', ' ').Trim()
		$expl = ''
		if ($m.Groups[7].Success) {
			$expl = $m.Groups[7].Value.Trim()
			# Stop before remediation footer / next section headers if captured.
			$expl = ($expl -split '(?m)^(Remediation:|Master ID|Internal ID|Form Question)')[0].Trim()
		}

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

function Write-SeedFile([string]$OutName, [string]$Title, [System.Collections.Generic.List[hashtable]]$Questions) {
	$out = Join-Path $OutDir $OutName
	$sb = New-Object System.Text.StringBuilder
	[void]$sb.AppendLine('<?php')
	[void]$sb.AppendLine('/**')
	[void]$sb.AppendLine((" * CTA LMFT California Law & Ethics - {0} ({1} questions)." -f $Title, $Questions.Count))
	[void]$sb.AppendLine(' * Built from approved Controlled Answer Key DOCX (online Practice Exam).')
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

Write-Host 'Building LMFT Law & Ethics Practice Exam seeds...'
foreach ($job in $Jobs) {
	$docx = Get-ChildItem -Path $Mat -Recurse -Filter '*.docx' | Where-Object {
		$_.FullName -like ("*" + ($job.Glob -replace '\*', '*')) -or
		($_.DirectoryName -match [regex]::Escape(($job.Key))) -and ($_.Name -match 'Controlled_Answer_Key')
	} | Select-Object -First 1

	# More reliable path resolve
	$docx = Get-ChildItem -Path (Join-Path $Mat ($job.Key)) -Filter '*Controlled_Answer_Key*.docx' -File | Select-Object -First 1
	if (-not $docx) { throw ("Missing answer key for {0}" -f $job.Key) }

	$text = Get-DocxPlainText $docx.FullName
	$qs = Parse-ExamKey $text
	if ($qs.Count -ne [int]$job.Expect) {
		throw ("{0}: expected {1}, parsed {2} from {3}" -f $job.Key, $job.Expect, $qs.Count, $docx.Name)
	}
	$out = Write-SeedFile $job.File $job.Title $qs
	Write-Host ("OK {0}: {1} q -> {2}" -f $job.Key, $qs.Count, (Split-Path $out -Leaf))
}
Write-Host 'Done.'
