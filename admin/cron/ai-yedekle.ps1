# AI Asistan - PreToolUse kancasi (Edit / Write / MultiEdit / NotebookEdit)
#
# Claude dosyayi degistirmeden HEMEN ONCE calisir (ai-calistirici.ps1 --settings ile baglar):
#   - Hedef dosya AI_PROJE_DIZIN disindaysa islemi engeller (exit 2). AI_SORGU_DIZIN (SELECT dosyalari) serbesttir.
#   - Dosyanin ilk halini AI_YEDEK_DIZIN altina yedekler, manifest.jsonl'e yazar (dosya basina bir kez).
#   - Yedek alinamazsa degisiklige izin verilmez (exit 2).
# Calistirici is bitince manifest'ten diff uretir; "Geri Al" yedekleri geri yukler.
# Dosya ASCII tutulur (PS 5.1 BOM'suz dosyayi ANSI okur).

$ErrorActionPreference = 'Stop'

try {
    try { [Console]::InputEncoding = New-Object System.Text.UTF8Encoding($false) } catch {}
    $girdi = [Console]::In.ReadToEnd().TrimStart([char]0xFEFF, [char]0xFFFE).Trim() | ConvertFrom-Json

    $yol = [string]$girdi.tool_input.file_path
    if (-not $yol) { $yol = [string]$girdi.tool_input.notebook_path }

    $yedekDizin = $env:AI_YEDEK_DIZIN
    $proje      = $env:AI_PROJE_DIZIN
    if (-not $yedekDizin -or -not $proje) {
        [Console]::Error.WriteLine('AI_YEDEK_DIZIN / AI_PROJE_DIZIN tanimsiz; yazma engellendi.')
        exit 2
    }
    if (-not $yol) { exit 0 }

    $tam      = [IO.Path]::GetFullPath($yol)
    $projeTam = [IO.Path]::GetFullPath($proje).TrimEnd('\') + '\'

    # SELECT sorgu dosyalari (sql.cmd icin) proje disindadir; yedek gerekmez, izin verilir
    if ($env:AI_SORGU_DIZIN) {
        $sorguTam = [IO.Path]::GetFullPath($env:AI_SORGU_DIZIN).TrimEnd('\') + '\'
        if ($tam.StartsWith($sorguTam, [StringComparison]::OrdinalIgnoreCase)) { exit 0 }
    }
    if (-not $tam.StartsWith($projeTam, [StringComparison]::OrdinalIgnoreCase)) {
        [Console]::Error.WriteLine("Proje disina yazma engellendi: $tam")
        exit 2
    }

    New-Item -ItemType Directory -Force -Path $yedekDizin | Out-Null
    $manifest = Join-Path $yedekDizin 'manifest.jsonl'

    # Ayni calistirmada ayni dosya ikinci kez degisirse ilk yedek korunur
    if (Test-Path $manifest) {
        foreach ($satir in (Get-Content -LiteralPath $manifest -Encoding UTF8)) {
            if ($satir -and (($satir | ConvertFrom-Json).yol -eq $tam)) { exit 0 }
        }
    }

    $yeni  = -not (Test-Path -LiteralPath $tam -PathType Leaf)
    $yedek = $null
    if (-not $yeni) {
        $sira  = @(Get-ChildItem -LiteralPath $yedekDizin -Filter '*.bak').Count + 1
        $yedek = Join-Path $yedekDizin "$sira.bak"
        Copy-Item -LiteralPath $tam -Destination $yedek
    }

    $kayit = [ordered]@{ yol = $tam; yeni = $yeni; yedek = $yedek }
    Add-Content -LiteralPath $manifest -Value ($kayit | ConvertTo-Json -Compress) -Encoding UTF8
    exit 0
} catch {
    [Console]::Error.WriteLine("Yedekleme hatasi, degisiklik engellendi: $($_.Exception.Message)")
    exit 2
}
