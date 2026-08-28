# Restore Exam Prep online workbook HTML + Study Center flashcard JSON from printable DOCX.
# Does not touch Form A/B quiz seeds or DB.
#
# Examples:
#   .\restore-exam-prep-content.ps1
#   .\restore-exam-prep-content.ps1 -WorkbookNums 2,3 -SkipFlashcards -ProgramKeys lmft-clinical,lcsw-aswb,lmft-amftrb,lpcc-ncmhce

param(
	[int[]] $WorkbookNums = @(1..12),
	[switch] $SkipFlashcards,
	[string[]] $ProgramKeys = @()
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Materials = Join-Path $Root 'assets\course-materials'

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
						# Join each paragraph separately so callout cells do not glue lines.
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
					$rows += [pscustomobject]@{ Kind = 'table'; Rows = $tableRows; Style = ''; Text = '' }
				}
				continue
			}

			$styleNode = $node.SelectSingleNode('w:pPr/w:pStyle', $ns)
			$style = ''
			if ($styleNode) {
				$style = [string]$styleNode.GetAttribute('val', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main')
			}
			$outline = $node.SelectSingleNode('w:pPr/w:outlineLvl', $ns)
			$outlineLvl = ''
			if ($outline) {
				$outlineLvl = [string]$outline.GetAttribute('val', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main')
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
				Outline = $outlineLvl
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
		$outline = [string]$row.Outline

		$isH1 = ($style -match '^(Heading1|Title|heading\s*1)$') -or ($outline -eq '0')
		$isH2 = ($style -match '^(Heading2|heading\s*2)$') -or ($outline -eq '1')
		$isH3 = ($style -match '^(Heading3|heading\s*3)$') -or ($outline -eq '2')
		$isBold = [bool]$row.IsBold

		# Promote common chapter-title patterns when Word left them unstyled.
<<<<<<< HEAD
		if (-not $isH1 -and -not $isH2 -and $text -match '^(How to Use This Workbook|Workbook Learning Objectives|Learning Objectives|Why This Topic Matters|Workbook Roadmap|Chapter Summary|Chapter\s+\d+\s+Summary|Knowledge Check|Workbook\s+\d+\s+Knowledge Check|Common Exam Traps|Workbook Close|Study Planning(?:\s+and\s+Next Step)?|Current AMFTRB Alignment|Workbook\s+\d+\s+Emphasis)\b') {
=======
		if (-not $isH1 -and -not $isH2 -and $text -match '^(How to Use This Workbook|Workbook Learning Objectives|Learning Objectives|Why This Topic Matters|Workbook Roadmap|Chapter Map|Chapter Summary|Chapter\s+\d+\s+Summary|Knowledge Check|Workbook\s+\d+\s+Knowledge Check|Common Exam Traps|Workbook Close|Study Planning(?:\s+and\s+Next Step)?|Current AMFTRB Alignment|Workbook\s+\d+\s+Emphasis)\b') {
>>>>>>> 1dcdd55b430ec7b912f0b502b3878173ec976d47
			$isH1 = $true
		}
		if (-not $isH1 -and -not $isH2 -and $text -match '^(Answer Key|Answer Key and Detailed Rationales)\b') {
			$isH1 = $true
		}
		if (-not $isH1 -and -not $isH2 -and -not $isH3 -and $isBold -and $text.Length -le 110 -and $text -match '^(Domain\s+\d+|EXAM CONTENT CONTROL|EDUCATIONAL USE NOTICE|MATERIAL NOTICE|BIG IDEA|CHAPTER BIG IDEA)\b') {
			$isH2 = $true
		}
		# AMFTRB: bold short title lines without Heading styles.
		if (-not $isH1 -and -not $isH2 -and -not $isH3 -and $isBold -and $text.Length -ge 12 -and $text.Length -le 90 -and $text -notmatch '\?$' -and $text -notmatch '^(Version|Editable|Clinical Training|CTA LMFT|NATIONAL|Student Workbook|How to Use)') {
			if ($text -match '^(Case Lab|High-Yield|The [A-Z]|Linear|Theory|Alliance|Participation|Access|Rupture|Documentation|Common Therapist|Competence|Care Versus|Direct Alliance|Symptom Progress|Write the Note)') {
				$isH1 = $true
			} elseif ($text -notmatch '\.$') {
				$isH2 = $true
			}
		}

		if ($isH1 -and $text -match '^(Answer Key|Answer Key and Detailed Rationales)\b') {
			$skipAnswerKey = $true
			if ($inList) { [void]$sb.Append("</$listTag>"); $inList = $false }
			continue
		}
		if ($skipAnswerKey) {
			if ($isH1) {
				$skipAnswerKey = $false
			} else {
				continue
			}
		}

		if ($isH1) {
			if ($inList) { [void]$sb.Append("</$listTag>"); $inList = $false }
			[void]$sb.Append('<h2 class="cta-lesson-h2">')
			[void]$sb.Append((Escape-Html $text))
			[void]$sb.Append('</h2>')
			continue
		}
		if ($isH2) {
			if ($inList) { [void]$sb.Append("</$listTag>"); $inList = $false }
			[void]$sb.Append('<h3 class="cta-lesson-h3">')
			[void]$sb.Append((Escape-Html $text))
			[void]$sb.Append('</h3>')
			continue
		}
		if ($isH3) {
			if ($inList) { [void]$sb.Append("</$listTag>"); $inList = $false }
			[void]$sb.Append('<h4 class="cta-lesson-h4">')
			[void]$sb.Append((Escape-Html $text))
			[void]$sb.Append('</h4>')
			continue
		}

		if ($row.IsList) {
			if (-not $inList) {
				$listTag = if ($style -match 'Number') { 'ol' } else { 'ul' }
				[void]$sb.Append("<$listTag class=`"cta-lesson-list`">")
				$inList = $true
			}
			[void]$sb.Append('<li>')
			[void]$sb.Append((Escape-Html $text))
			[void]$sb.Append('</li>')
			continue
		}

		if ($inList) { [void]$sb.Append("</$listTag>"); $inList = $false }

		# Banner / callout labels
		if ($text -match '^(BIG IDEA|CHAPTER BIG IDEA|CTA STUDY CYCLE|EDUCATIONAL|NO CONTINUING|STUDENT-FACING|MATERIAL NOTICE|IMPORTANT)\b') {
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

function Convert-FlashcardDocxToJson([string]$DocxPath, [string]$Title, [hashtable]$DomainMap) {
	$paras = Get-DocxParagraphs $DocxPath
	$lines = @()
	foreach ($row in $paras) {
		if ($row.Kind -eq 'p' -and $row.Text) { $lines += [string]$row.Text }
	}

	$cards = New-Object System.Collections.Generic.List[object]
	$domains = New-Object System.Collections.Generic.List[object]
	$domainSeen = @{}
	$currentDomain = 'general'
	$currentDomainLabel = 'General'
	$i = 0
	while ($i -lt $lines.Count) {
		$line = $lines[$i].Trim()

		# Domain header patterns: "LPCC  ·  CORE", "LMFT · WB3", "CORE", "Workbook 1"
		if ($line -match '(?i)\b(CORE)\b' -and $line -notmatch '(?i)^QUESTION$|^ANSWER$' -and $line.Length -lt 40) {
			$currentDomain = 'core'
			$currentDomainLabel = 'Core Reasoning'
			if ($DomainMap.ContainsKey('core')) { $currentDomainLabel = $DomainMap['core'] }
			if (-not $domainSeen.ContainsKey($currentDomain)) {
				$domainSeen[$currentDomain] = $true
				$domains.Add([ordered]@{ key = $currentDomain; label = $currentDomainLabel; order = $domainSeen.Count })
			}
			$i++; continue
		}
		if ($line -match '(?i)\bWB\s*(\d{1,2})\b' -and $line -notmatch '(?i)^QUESTION$|^ANSWER$' -and $line.Length -lt 60) {
			$wb = [int]$Matches[1]
			$currentDomain = 'workbook-' + $wb
			$currentDomainLabel = "Workbook $wb"
			if ($DomainMap.ContainsKey($currentDomain)) { $currentDomainLabel = $DomainMap[$currentDomain] }
			if (-not $domainSeen.ContainsKey($currentDomain)) {
				$domainSeen[$currentDomain] = $true
				$domains.Add([ordered]@{ key = $currentDomain; label = $currentDomainLabel; order = $domainSeen.Count })
			}
			$i++; continue
		}

		if ($line -match '(?i)^QUESTION$') {
			$frontParts = New-Object System.Collections.Generic.List[string]
			$backParts = New-Object System.Collections.Generic.List[string]
			$mode = 'front'
			$i++
			while ($i -lt $lines.Count) {
				$t = $lines[$i].Trim()
				if ($t -match '(?i)^QUESTION$') { break }
				if ($t -match '(?i)^ANSWER$') { $mode = 'back'; $i++; continue }
				if ($t -match '(?i)COVER OR FOLD HERE') { $i++; continue }
				if ($t -match '^\d{3}$') { $i++; continue }
				if ($t -match '(?i)\b(CORE|WB\s*\d{1,2})\b' -and $t.Length -lt 40 -and $mode -eq 'back' -and $backParts.Count -gt 0) {
					break
				}
				if ($mode -eq 'front') { $frontParts.Add($t) } else { $backParts.Add($t) }
				$i++
			}
			$front = ($frontParts -join "`n").Trim()
			$back = ($backParts -join "`n").Trim()
			if ($front -and $back) {
				$cards.Add([ordered]@{
					id     = [string]($cards.Count + 1)
					domain = $currentDomain
					front  = $front
					back   = $back
				})
			}
			continue
		}
		$i++
	}

	if ($cards.Count -eq 0) {
		return $null
	}

	if ($domains.Count -eq 0) {
		$domains.Add([ordered]@{ key = 'general'; label = 'General'; order = 1 })
	}

	return [ordered]@{
		title           = $Title
		expected_total  = $cards.Count
		version         = '1.0'
		source          = [IO.Path]::GetFileName($DocxPath)
		domains         = @($domains)
		cards           = @($cards)
	}
}

function Save-Json($Obj, [string]$Path) {
	$dir = Split-Path $Path -Parent
	if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
	$json = $Obj | ConvertTo-Json -Depth 8
	# ConvertTo-Json may escape unicode oddly; write UTF8
	[IO.File]::WriteAllText($Path, $json, [Text.UTF8Encoding]::new($false))
}

$programs = @(
	@{
		Key = 'lpcc-ncmhce'
		WorkbookGlob = 'CTA_LPCC_WB{0}_*.docx'
		FlashcardRel = 'study-tools\CTA_LPCC_Clinical_Exam_Preparation_Flashcard_Collection_v1.0.docx'
		FlashTitle = 'LPCC NCMHCE — Flashcard Study Center'
		DomainMap = @{
			'core' = 'Core Clinical Reasoning'
			'workbook-1' = 'Case-Study Strategy'
			'workbook-2' = 'Professional Identity & Scope'
			'workbook-3' = 'Intake & Assessment'
			'workbook-4' = 'Crisis & Level of Care'
			'workbook-5' = 'Diagnosis I'
			'workbook-6' = 'Diagnosis II'
			'workbook-7' = 'Treatment Planning'
			'workbook-8' = 'Theories & Alliance'
			'workbook-9' = 'Evidence-Based Interventions'
			'workbook-10' = 'Multicultural & Context'
			'workbook-11' = 'Modalities & Collaboration'
			'workbook-12' = 'Law, Ethics & Documentation'
		}
	}
	@{
		Key = 'lmft-clinical'
		WorkbookGlob = 'CTA_LMFT_WB{0}_*.docx'
		FlashcardRel = 'study-tools\CTA_LMFT_Clinical_Exam_Preparation_Flashcard_Collection_v1.0.docx'
		FlashTitle = 'LMFT California Clinical — Flashcard Study Center'
		DomainMap = @{}
	}
	@{
		Key = 'lcsw-aswb'
		WorkbookGlob = 'CTA_LCSW_WB{0}_*.docx'
		FlashcardRel = 'study-tools\CTA_LCSW_Clinical_Exam_Preparation_Flashcard_Collection_v1.0.docx'
		FlashTitle = 'LCSW ASWB Clinical — Flashcard Study Center'
		DomainMap = @{}
	}
	@{
		Key = 'lmft-amftrb'
		WorkbookGlob = 'CTA_LMFT_AMFTRB_WB{0}_*.docx'
		FlashcardRel = 'study-tools\CTA_LMFT_AMFTRB_WB1-12_120_Card_Flashcard_Study_Collection_v1.0.docx'
		FlashTitle = 'LMFT AMFTRB National — Flashcard Study Center'
		DomainMap = @{}
	}
<<<<<<< HEAD
=======
	@{
		Key = 'lmft-law-ethics'
		WorkbookGlob = 'CTA_LMFT_Law_and_Ethics_EP_WB{0}_*_Candidate_Edition_*.docx'
		FlashcardRel = ''
		FlashTitle = 'LMFT California Law & Ethics — Flashcard Study Center'
		DomainMap = @{}
	}
	@{
		Key = 'lpcc-law-ethics'
		WorkbookGlob = 'CTA_LPCC_Law_and_Ethics_EP_WB{0:D2}_*_Candidate_Edition_*.docx'
		FlashcardRel = ''
		FlashTitle = 'LPCC California Law & Ethics — Flashcard Study Center'
		DomainMap = @{}
	}
>>>>>>> 1dcdd55b430ec7b912f0b502b3878173ec976d47
)

if ($ProgramKeys -and $ProgramKeys.Count -gt 0) {
	$programs = @($programs | Where-Object { $ProgramKeys -contains $_.Key })
}

$report = @()

foreach ($prog in $programs) {
	$progDir = Join-Path $Materials $prog.Key
	$wbDir = Join-Path $progDir 'workbooks'
	$lessonDir = Join-Path $progDir 'lessons'
	if (-not (Test-Path $lessonDir)) { New-Item -ItemType Directory -Path $lessonDir -Force | Out-Null }

	$wbOk = 0
	foreach ($n in $WorkbookNums) {
		$pattern = $prog.WorkbookGlob -f $n
		$matches = @(Get-ChildItem -Path $wbDir -Filter $pattern -ErrorAction SilentlyContinue)
		if ($matches.Count -eq 0) {
			Write-Host "MISSING workbook $pattern"
			continue
		}
		$src = $matches[0].FullName
		$html = Convert-WorkbookDocxToHtml $src $prog.Key $n
		$out = Join-Path $lessonDir ('wb{0:D2}.html' -f $n)
		[IO.File]::WriteAllText($out, $html, [Text.UTF8Encoding]::new($false))
		$plainLen = ([regex]::Replace($html, '<[^>]+>', ' ')).Trim().Length
		Write-Host ("OK {0} wb{1:D2} chars={2}" -f $prog.Key, $n, $plainLen)
		$wbOk++
	}

	$fcCount = 0
<<<<<<< HEAD
	if (-not $SkipFlashcards) {
=======
	if (-not $SkipFlashcards -and -not [string]::IsNullOrWhiteSpace([string]$prog.FlashcardRel)) {
>>>>>>> 1dcdd55b430ec7b912f0b502b3878173ec976d47
		$fcPath = Join-Path $progDir $prog.FlashcardRel
		if (Test-Path $fcPath) {
			$deck = Convert-FlashcardDocxToJson $fcPath $prog.FlashTitle $prog.DomainMap
			if ($null -ne $deck) {
				$fcOut = Join-Path $progDir 'study-tools\flashcard-study-center.json'
				Save-Json $deck $fcOut
				$fcCount = $deck.cards.Count
				Write-Host ("OK {0} flashcards={1}" -f $prog.Key, $fcCount)
			} else {
				Write-Host ("FAIL flashcards parse {0}" -f $prog.Key)
			}
		} else {
			Write-Host ("MISSING flashcard docx {0}" -f $fcPath)
		}
	}

	$report += [pscustomobject]@{ Program = $prog.Key; Workbooks = $wbOk; Cards = $fcCount }
}

$report | Format-Table -AutoSize
Write-Host 'Done.'
