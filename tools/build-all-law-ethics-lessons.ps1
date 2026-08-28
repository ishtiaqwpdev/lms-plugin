# Build online workbook lessons (wbNN.html) from Candidate Edition DOCX for Law & Ethics programs.
# Does not invent flashcards. Skips Answer Key sections.
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem

$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Materials = Join-Path $Root 'assets\course-materials'

function Get-DocxParagraphs([string]$DocxPath) {
	$tmp = Join-Path $env:TEMP ('cta-docx-' + [guid]::NewGuid().ToString('N'))
	[System.IO.Compression.ZipFile]::ExtractToDirectory($DocxPath, $tmp)
	try {
		[xml]$doc = Get-Content (Join-Path $tmp 'word\document.xml') -Raw -Encoding UTF8
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
					if (($cells | Where-Object { $_ }).Count -gt 0) { $tableRows += ,$cells }
				}
				if ($tableRows.Count -gt 0) {
					$rows += [pscustomobject]@{ Kind = 'table'; Rows = $tableRows; Style = ''; Text = ''; IsList = $false; IsBold = $false }
				}
				continue
			}
			$styleNode = $node.SelectSingleNode('w:pPr/w:pStyle', $ns)
			$style = if ($styleNode) { [string]$styleNode.GetAttribute('val', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main') } else { '' }
			$numPr = $node.SelectSingleNode('w:pPr/w:numPr', $ns)
			$isList = $null -ne $numPr -or $style -match 'List|Bullet|Number'
			$isBold = $null -ne $node.SelectSingleNode('.//w:rPr/w:b', $ns)
			$texts = $node.SelectNodes('.//w:t', $ns) | ForEach-Object { $_.'#text' }
			$text = ($texts -join '').Trim()
			if (-not $text) { continue }
			$rows += [pscustomobject]@{ Kind = 'p'; Text = $text; Style = $style; IsList = [bool]$isList; IsBold = [bool]$isBold }
		}
		return $rows
	} finally {
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
	$skip = $false
	$inList = $false
	foreach ($row in $paras) {
		if ($row.Kind -eq 'table') {
			if ($inList) { [void]$sb.Append('</ul>'); $inList = $false }
			if ($skip) { continue }
			[void]$sb.Append('<table class="cta-lesson-table"><tbody>')
			foreach ($tr in $row.Rows) {
				[void]$sb.Append('<tr>')
				foreach ($cell in $tr) { [void]$sb.Append('<td>'); [void]$sb.Append((Escape-Html $cell)); [void]$sb.Append('</td>') }
				[void]$sb.Append('</tr>')
			}
			[void]$sb.Append('</tbody></table>')
			continue
		}
		$text = [string]$row.Text
		if ($text -match '^(Answer Key|Controlled Answer Key)\b') { $skip = $true; if ($inList) { [void]$sb.Append('</ul>'); $inList = $false }; continue }
		if ($skip) {
			if ($text -match '^(Chapter\s+\d+|Workbook Close|How to Use This Workbook|Chapter Map)\b') { $skip = $false } else { continue }
		}
		$isH1 = $row.Style -match '^(Heading1|Title)$' -or $text -match '^(How to Use This Workbook|Workbook Learning Objectives|Learning Objectives|Why This Topic Matters|Workbook Roadmap|Chapter Map|Chapter Summary|Knowledge Check|Common Exam Traps|Workbook Close|Study Planning|Publication Notice)\b' -or ($row.IsBold -and $text.Length -le 110 -and $text -match '^(Chapter\s+\d+)\b')
		$isH2 = $row.Style -match '^Heading2$' -or ($row.IsBold -and $text.Length -le 120 -and -not $isH1 -and $text -match '^(What to Learn|Core Study Rules|Exam Reasoning Checklist|Chapter Rapid Review|BIG IDEA|CHAPTER BIG IDEA)\b')
		$isH3 = $row.Style -match '^Heading3$'
		if ($isH1 -or $isH2 -or $isH3) {
			if ($inList) { [void]$sb.Append('</ul>'); $inList = $false }
			$tag = if ($isH1) { 'h2' } elseif ($isH2) { 'h3' } else { 'h4' }
			$class = if ($isH1) { 'cta-lesson-h2' } elseif ($isH2) { 'cta-lesson-h3' } else { 'cta-lesson-h4' }
			[void]$sb.Append("<$tag class=`"$class`">"); [void]$sb.Append((Escape-Html $text)); [void]$sb.Append("</$tag>")
			continue
		}
		if ($row.IsList) {
			if (-not $inList) { [void]$sb.Append('<ul class="cta-lesson-list">'); $inList = $true }
			[void]$sb.Append('<li>'); [void]$sb.Append((Escape-Html $text)); [void]$sb.Append('</li>')
			continue
		}
		if ($inList) { [void]$sb.Append('</ul>'); $inList = $false }
		[void]$sb.Append('<p>'); [void]$sb.Append((Escape-Html $text)); [void]$sb.Append('</p>')
	}
	if ($inList) { [void]$sb.Append('</ul>') }
	[void]$sb.Append('</article>')
	return $sb.ToString()
}

function Build-ProgramLessons([string]$ProgramKey, [string]$WbGlob) {
	$wbDir = Join-Path $Materials "$ProgramKey\workbooks"
	$outDir = Join-Path $Materials "$ProgramKey\lessons"
	if (-not (Test-Path $wbDir)) { Write-Output "SKIP missing workbooks: $ProgramKey"; return }
	if (-not (Test-Path $outDir)) { New-Item -ItemType Directory -Path $outDir -Force | Out-Null }
	$files = Get-ChildItem $wbDir -Filter *.docx | Where-Object { $_.Name -match $WbGlob } | Sort-Object Name
	foreach ($docx in $files) {
		if ($docx.Name -notmatch '_WB0?(\d+)_') { continue }
		$n = [int]$Matches[1]
		$html = Convert-WorkbookDocxToHtml $docx.FullName $ProgramKey $n
		$plain = [regex]::Replace($html, '<[^>]+>', ' ')
		$plain = [regex]::Replace($plain, '\s+', ' ').Trim()
		if ($plain.Length -lt 5000) { throw "Too short lesson for $($docx.Name) plain=$($plain.Length)" }
		$out = Join-Path $outDir ('wb' + ('{0:d2}' -f $n) + '.html')
		Set-Content -LiteralPath $out -Value $html -Encoding UTF8
		Write-Output ("OK {0} wb{1:d2} bytes={2} plain={3}" -f $ProgramKey, $n, (Get-Item $out).Length, $plain.Length)
	}
}

Build-ProgramLessons 'lpcc-law-ethics' '_WB\d+_'
Build-ProgramLessons 'lcsw-law-ethics' '_WB\d+_'
Write-Output 'DONE'
