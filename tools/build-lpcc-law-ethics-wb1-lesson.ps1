# Convert LPCC Law & Ethics Workbook 1 Candidate Edition DOCX -> lessons/wb01.html only.
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem

$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Docx = Get-ChildItem (Join-Path $Root 'assets\course-materials\lpcc-law-ethics\workbooks\*_WB01_*.docx') | Select-Object -First 1
if (-not $Docx) { throw 'LPCC Law & Ethics WB1 Candidate Edition DOCX not found' }
$OutDir = Join-Path $Root 'assets\course-materials\lpcc-law-ethics\lessons'
$Out = Join-Path $OutDir 'wb01.html'
if (-not (Test-Path $OutDir)) { New-Item -ItemType Directory -Path $OutDir -Force | Out-Null }

function Get-DocxParagraphs([string]$DocxPath) {
	$tmp = Join-Path $env:TEMP ('cta-docx-' + [guid]::NewGuid().ToString('N'))
	[System.IO.Compression.ZipFile]::ExtractToDirectory($DocxPath, $tmp)
	try {
		$xmlPath = Join-Path $tmp 'word\document.xml'
		[xml]$doc = Get-Content $xmlPath -Raw -Encoding UTF8
		$ns = New-Object System.Xml.XmlNamespaceManager($doc.NameTable)
		$ns.AddNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main')
		$paras = $doc.SelectNodes('//w:body/w:p|//w:body/w:tbl', $ns)
		$rows = @()
		foreach ($node in $paras) {
			if ($node.LocalName -eq 'tbl') {
				$tableRows = @()
				foreach ($tr in $node.SelectNodes('.//w:tr', $ns)) {
					$cells = @()
					foreach ($tc in $tr.SelectNodes('./w:tc', $ns)) {
						$paraTexts = @()
						foreach ($p in $tc.SelectNodes('./w:p', $ns)) {
							$tRuns = $p.SelectNodes('.//w:t', $ns) | ForEach-Object { $_.'#text' }
							$line = (($tRuns -join '') -replace '\s+', ' ').Trim()
							if ($line) { $paraTexts += $line }
						}
						$cells += (($paraTexts -join ' ') -replace '\s+', ' ').Trim()
					}
					if (($cells | Where-Object { $_ }).Count -gt 0) {
						$tableRows += ,$cells
					}
				}
				if ($tableRows.Count -gt 0) {
					$rows += [pscustomobject]@{ Kind = 'table'; Rows = $tableRows; Style = ''; Text = ''; IsList = $false; IsBold = $false }
				}
				continue
			}
			$styleNode = $node.SelectSingleNode('w:pPr/w:pStyle', $ns)
			$style = ''
			if ($styleNode) {
				$style = [string]$styleNode.GetAttribute('val', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main')
			}
			$numPr = $node.SelectSingleNode('w:pPr/w:numPr', $ns)
			$isList = $null -ne $numPr -or $style -match 'List|Bullet|Number'
			$boldNode = $node.SelectSingleNode('.//w:rPr/w:b', $ns)
			$isBold = $null -ne $boldNode
			$texts = $node.SelectNodes('.//w:t', $ns) | ForEach-Object { $_.'#text' }
			$text = ($texts -join '').Trim()
			if (-not $text) { continue }
			$rows += [pscustomobject]@{
				Kind   = 'p'
				Text   = $text
				Style  = $style
				IsList = [bool]$isList
				IsBold = [bool]$isBold
			}
		}
		return $rows
	}
	finally {
		Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
	}
}

function Escape-Html([string]$Text) {
	if ($null -eq $Text) { return '' }
	return ($Text -replace '&', '&amp;' -replace '<', '&lt;' -replace '>', '&gt;' -replace '"', '&quot;')
}

function Convert-WorkbookDocxToHtml([string]$DocxPath, [string]$ProgramKey, [int]$WorkbookNum) {
	$paras = Get-DocxParagraphs $DocxPath
	$sb = New-Object System.Text.StringBuilder
	[void]$sb.Append('<article class="cta-lesson-article" data-program="')
	[void]$sb.Append((Escape-Html $ProgramKey))
	[void]$sb.Append('" data-workbook="')
	[void]$sb.Append($WorkbookNum)
	[void]$sb.Append('">')

	$skipAnswerKey = $false
	$inList = $false
	$listTag = 'ul'

	foreach ($row in $paras) {
		if ($row.Kind -eq 'table') {
			if ($inList) { [void]$sb.Append("</$listTag>"); $inList = $false }
			[void]$sb.Append('<div class="cta-lesson-table-wrap"><table class="cta-lesson-table"><tbody>')
			foreach ($tr in $row.Rows) {
				[void]$sb.Append('<tr>')
				foreach ($cell in $tr) {
					[void]$sb.Append('<td>')
					[void]$sb.Append((Escape-Html $cell))
					[void]$sb.Append('</td>')
				}
				[void]$sb.Append('</tr>')
			}
			[void]$sb.Append('</tbody></table></div>')
			continue
		}

		$text = [string]$row.Text
		$style = [string]$row.Style

		if ($text -match '(?i)^Answer Key\b') { $skipAnswerKey = $true; continue }
		if ($skipAnswerKey) {
			if ($style -match '(?i)^Heading1$' -or $text -match '(?i)^(How to Use This Workbook|Workbook Learning Objectives|Chapter Summary|Knowledge Check|Workbook Close|Chapter\s+\d+)\b') {
				$skipAnswerKey = $false
			} else {
				continue
			}
		}

		$isH1 = $style -match '(?i)^(Heading1|Title)$' -or $text -match '(?i)^(How to Use This Workbook|Workbook Learning Objectives|Learning Objectives|Why This Topic Matters|Workbook Roadmap|Chapter Map|Chapter Summary|Knowledge Check|Common Exam Traps|Workbook Close|Study Planning|Chapter\s+\d+)\b'
		$isH2 = $style -match '(?i)^Heading2$' -or ($row.IsBold -and $text.Length -le 110 -and $text -match '^(BIG IDEA|What to Learn|Memory Cue|Exam Distinctions)\b')
		$isH3 = $style -match '(?i)^Heading3$'

		if ($isH1 -or $isH2 -or $isH3) {
			if ($inList) { [void]$sb.Append("</$listTag>"); $inList = $false }
			$tag = if ($isH1) { 'h2' } elseif ($isH2) { 'h3' } else { 'h4' }
			[void]$sb.Append("<$tag class=`"cta-lesson-$tag`">")
			[void]$sb.Append((Escape-Html $text))
			[void]$sb.Append("</$tag>")
			continue
		}

		if ($row.IsList) {
			if (-not $inList) {
				$listTag = if ($style -match '(?i)Number') { 'ol' } else { 'ul' }
				[void]$sb.Append("<$listTag class=`"cta-lesson-$listTag`">")
				$inList = $true
			}
			[void]$sb.Append('<li class="cta-lesson-li">')
			[void]$sb.Append((Escape-Html $text))
			[void]$sb.Append('</li>')
			continue
		}

		if ($inList) { [void]$sb.Append("</$listTag>"); $inList = $false }

		if ($text -match '^(BIG IDEA|CHAPTER BIG IDEA|CTA STUDY CYCLE|EDUCATIONAL|NO CONTINUING|STUDENT-FACING|MATERIAL NOTICE|IMPORTANT|Publication Notice)\b') {
			[void]$sb.Append('<p class="cta-lesson-p cta-lesson-p--banner"><strong>')
			[void]$sb.Append((Escape-Html $text))
			[void]$sb.Append('</strong></p>')
			continue
		}

		[void]$sb.Append('<p class="cta-lesson-p">')
		[void]$sb.Append((Escape-Html $text))
		[void]$sb.Append('</p>')
	}

	if ($inList) { [void]$sb.Append("</$listTag>") }
	[void]$sb.Append('</article>')
	return $sb.ToString()
}

Write-Host ("Converting: " + $Docx.Name)
$html = Convert-WorkbookDocxToHtml $Docx.FullName 'lpcc-law-ethics' 1
[IO.File]::WriteAllText($Out, $html, [Text.UTF8Encoding]::new($false))
$plain = ([regex]::Replace($html, '<[^>]+>', ' ')).Trim()
$chapters = ([regex]::Matches($html, '(?i)>Chapter\s+[1-7]<')).Count
$hasClose = $html -match 'Workbook Close'
$hasPending = $html -match 'pending full client workbook upload'
Write-Host ("Wrote: $Out")
Write-Host ("Bytes=" + (Get-Item $Out).Length + " plainChars=" + $plain.Length + " chapterHeads=" + $chapters + " workbookClose=" + $hasClose + " placeholder=" + $hasPending)
# Require substantial content covering all 7 chapters
if ($plain.Length -lt 40000) { throw "Converted HTML too short ($($plain.Length) chars) - incomplete conversion" }
if ($chapters -lt 7) { throw "Expected Chapter 1-7 headings, found $chapters" }
if (-not $hasClose) { throw 'Missing Workbook Close section' }
Write-Host 'OK: full Workbook 1 content present.'
