# Restore Workbook 2 + Workbook 3 lesson HTML only for the four clinical Exam Prep programs.
# Does not touch Workbook 1, Forms A/B, flashcards, or catalog artwork.

$ErrorActionPreference = 'Stop'
& (Join-Path $PSScriptRoot 'restore-exam-prep-content.ps1') `
	-WorkbookNums @(2, 3) `
	-SkipFlashcards `
	-ProgramKeys @('lmft-clinical', 'lcsw-aswb', 'lmft-amftrb', 'lpcc-ncmhce')
