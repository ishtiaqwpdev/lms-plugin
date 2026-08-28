# Build LMFT Clinical workbook Practice Bank quiz seeds (wb1-wb12, 17q each)
# from approved question-bank DOCX files. Does not modify Forms A/B.

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem

$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$BanksDir = Join-Path $Root 'assets\course-materials\lmft-clinical\question-banks'
$OutDir = Join-Path $Root 'includes\quiz-seeds'

function Get-DocxPlainText([string]$DocxPath) {
	$tmp = Join-Path $env:TEMP ("cta-clinical-qb-{0}" -f [Guid]::NewGuid().ToString('N'))
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
	($s -replace "\\", "\\\\") -replace "'", "\\'"
}

function Parse-QuestionBlock([string]$Block) {
	$m = [regex]::Match($Block, '(?s)Question\s*\r?\n(.+?)\r?\nA\.\s*(.+?)\r?\nB\.\s*(.+?)\r?\nC\.\s*(.+?)\r?\nD\.\s*(.+?)\r?\nCorrect Answer:\s*([A-Da-d])')
	if (-not $m.Success) { return $null }

	$stem = $m.Groups[1].Value.Trim()
	$opts = @($m.Groups[2].Value.Trim(), $m.Groups[3].Value.Trim(), $m.Groups[4].Value.Trim(), $m.Groups[5].Value.Trim())
	$correct = $m.Groups[6].Value.ToLower()

	$expl = ''
	$rm = [regex]::Match($Block, '(?s)Rationales\s*\r?\n(.+)$')
	if ($rm.Success) {
		$expl = $rm.Groups[1].Value.Trim()
		# Trim trailing workbook-level sections if parser ran into next difficulty header.
		$expl = ($expl -split '(?m)^(Easy Questions|Moderate Questions|Difficult Questions|Question ID)\s*$')[0].Trim()
	}

	if (-not $stem -or -not $correct) { return $null }
	return @{
		question_text = $stem
		option_a = $opts[0]
		option_b = $opts[1]
		option_c = $opts[2]
		option_d = $opts[3]
		correct_option = $correct
		explanation = $expl
	}
}

function Write-SeedFile([int]$WbNum, [array]$Questions) {
	$out = Join-Path $OutDir ("lmft-clinical-wb{0}-bank.php" -f $WbNum)
	$sb = New-Object System.Text.StringBuilder
	[void]$sb.AppendLine('<?php')
	[void]$sb.AppendLine('/**')
	[void]$sb.AppendLine((' * CTA LMFT California Clinical - Workbook {0} - 17-question practice bank.' -f $WbNum))
	[void]$sb.AppendLine(' * Built from approved CTA_LMFT_WB' + $WbNum + '_17_Question_Bank_v1.0.docx.')
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
	[void][IO.File]::WriteAllText($out, $sb.ToString(), [Text.UTF8Encoding]::new($false))
	return $out
}

if (-not (Test-Path $OutDir)) { New-Item -ItemType Directory -Path $OutDir | Out-Null }

$summary = @()
for ($n = 1; $n -le 12; $n++) {
	$docx = Get-ChildItem -Path $BanksDir -Filter ("CTA_LMFT_WB{0}_17_Question_Bank*.docx" -f $n) | Select-Object -First 1
	if (-not $docx) { throw "Missing question bank DOCX for workbook $n" }

	$text = Get-DocxPlainText $docx.FullName
	$parts = [regex]::Split($text, '(?=LMFT-WB\d+-QB-Q\d+)')
	$questions = New-Object System.Collections.Generic.List[object]
	foreach ($part in $parts) {
		if ($part -notmatch 'LMFT-WB\d+-QB-Q\d+') { continue }
		$parsed = Parse-QuestionBlock $part
		if ($parsed) { $questions.Add($parsed) }
	}

	if ($questions.Count -ne 17) {
		Write-Warning ("WB{0}: expected 17 questions, parsed {1} from {2}" -f $n, $questions.Count, $docx.Name)
	}

	$path = Write-SeedFile $n ([object[]]@($questions.ToArray()))
	$summary += [pscustomobject]@{ Workbook = $n; Questions = $questions.Count; File = (Split-Path $path -Leaf) }
}

$summary | Format-Table -AutoSize
$bad = @($summary | Where-Object { $_.Questions -ne 17 })
if ($bad.Count -gt 0) {
	throw ("Seed build incomplete: {0} workbook(s) do not have 17 questions." -f $bad.Count)
}
Write-Host 'All 12 LMFT Clinical workbook bank seeds built successfully.'
