# AI Asistan - GitHub iki repo akisi ("versiyon gonder" adim 2-7)
#
# Ajan yalniz bu betigi calistirabilir (C:\ProgramData\TicketAI\github.cmd sarmalayicisi; izin kurali
# Bash(C:/ProgramData/TicketAI/github.cmd:*)). Serbest git erisimi verilmez.
#
#   github.cmd durum          <proje_kok>              # .github-repo, origin kontrolu, git status, uzak erisim testi
#   github.cmd private        <proje_kok> "<mesaj>"    # add -A, commit ("vX.Y.Z - ozet"), push origin (force yok)
#   github.cmd public-hazirla <proje_kok>              # public-hazirla.php + gitleaks + maske kontrolu + rapor
#   github.cmd public         <proje_kok> <vX.Y.Z>     # yalniz son hazirlik TEMIZ ve icerik degismediyse commit + push
#
# Kurallar: proje AI_PROJE_DIZIN ile ayni olmali (yazma modu); origin'ler .github-repo ile uyusmazsa durulur;
# commit kimligi .github-repo'dan repo bazli ayarlanir; Co-Authored-By vb. imza eklenmez; force push yok.
# Dosya ASCII tutulur (PS 5.1 BOM'suz dosyayi ANSI okur).

param(
    [Parameter(Position = 0)][string]$Komut,
    [Parameter(Position = 1)][string]$Proje,
    [Parameter(Position = 2)][string]$Deger
)

# git/gitleaks stderr'e ilerleme yazar; PS 5.1 'Stop' modunda bunu hata sayar. Hatalar cikis koduyla denetlenir.
$ErrorActionPreference = 'Continue'
$Php        ='C:\Program Files (x86)\Plesk\Additional\PleskPHP83\php.exe'
$HazirlaPhp = 'C:\Users\Administrator\.claude\scripts\public-hazirla.php'
$GitleaksCfg = 'C:\Users\Administrator\.claude\gitleaks.toml'
$DurumDizin = 'C:\ProgramData\TicketAI\github'

try { [Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false) } catch {}

function Dur([string]$Mesaj, [int]$Kod = 1) { Write-Output "HATA: $Mesaj"; exit $Kod }

function GitCalistir([string]$Dizin, [string[]]$Arg) {
    $cikti = & git -C $Dizin @Arg 2>&1 | ForEach-Object { "$_" }
    if ($LASTEXITCODE -ne 0) { Dur ("git " + ($Arg -join ' ') + " basarisiz:`n" + ($cikti -join "`n")) }
    return ($cikti -join "`n")
}

function RepoAyar([string]$Kok) {
    $dosya = Join-Path $Kok '.github-repo'
    if (-not (Test-Path $dosya)) { Dur '.github-repo yok: proje GitHub akisina alinmamis ("GitHub a bagla" VS Code dan yapilir).' }
    $a = @{}
    foreach ($s in Get-Content $dosya -Encoding UTF8) {
        if ($s -match '^\s*([a-z_]+)\s*=\s*(.+?)\s*$') { $a[$Matches[1]] = $Matches[2] }
    }
    foreach ($k in 'private', 'public', 'commit_ad', 'commit_eposta') {
        if (-not $a[$k]) { Dur ".github-repo icinde '$k' yok." }
    }
    return $a
}

function OriginKontrol([string]$Dizin, [string]$Beklenen, [string]$Ad) {
    $origin = (& git -C $Dizin remote get-url origin 2>$null)
    if ($LASTEXITCODE -ne 0 -or -not $origin) { Dur "$Ad origin tanimsiz ($Dizin)." }
    if ($origin.Trim().TrimEnd('/') -ne $Beklenen.Trim().TrimEnd('/')) {
        Dur "$Ad origin uyusmuyor. Beklenen: $Beklenen  Bulunan: $origin"
    }
}

function KimlikAyarla([string]$Dizin, $Ayar) {
    if ((& git -C $Dizin config user.name) -ne $Ayar.commit_ad)      { GitCalistir $Dizin @('config', 'user.name', $Ayar.commit_ad) | Out-Null }
    if ((& git -C $Dizin config user.email) -ne $Ayar.commit_eposta) { GitCalistir $Dizin @('config', 'user.email', $Ayar.commit_eposta) | Out-Null }
}

# .public calisma agacinin icerik ozeti (izlenen + izlenmeyen, .gitignore'a uyarak)
function PublicOzet([string]$Pub) {
    $liste = & git -C $Pub ls-files -co --exclude-standard 2>$null | Sort-Object
    $sha = [System.Security.Cryptography.SHA256]::Create()
    $parca = foreach ($d in $liste) {
        $yol = Join-Path $Pub $d
        if (Test-Path -LiteralPath $yol -PathType Leaf) {
            $d + ':' + [BitConverter]::ToString($sha.ComputeHash([IO.File]::ReadAllBytes($yol))).Replace('-', '')
        }
    }
    return [BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes(($parca -join "`n")))).Replace('-', '')
}

# ===================================================================
#  GIRIS
# ===================================================================

if (-not $Komut -or -not $Proje) { Dur 'Kullanim: github.cmd <durum|private|public-hazirla|public> <proje_kok> [deger]' }

$kok = [IO.Path]::GetFullPath($Proje).TrimEnd('\')
if (-not $env:AI_PROJE_DIZIN) { Dur 'GitHub islemleri yalniz dosya duzenleme izinli calistirmada yapilir (AI_PROJE_DIZIN yok).' }
if ($kok -ne [IO.Path]::GetFullPath($env:AI_PROJE_DIZIN).TrimEnd('\')) { Dur "Yalniz bu ticket'in projesinde calisilir: $env:AI_PROJE_DIZIN" }
if (-not (Test-Path (Join-Path $kok '.git'))) { Dur "Proje git deposu degil: $kok" }

$ayar = RepoAyar $kok
$pub  = Join-Path $kok '.public'
$durumDosya = Join-Path $DurumDizin (($ayar.proje, (Split-Path $kok -Leaf) | Where-Object { $_ } | Select-Object -First 1) + '.json')
New-Item -ItemType Directory -Force -Path $DurumDizin | Out-Null

switch ($Komut) {

    'durum' {
        OriginKontrol $kok $ayar.private 'Private'
        Write-Output "Proje       : $($ayar.proje)"
        Write-Output "Private     : $($ayar.private)"
        Write-Output "Public      : $($ayar.public)"
        Write-Output "Son commit  : $(GitCalistir $kok @('log', '-1', '--format=%h %s (%ci)'))"
        $ls = & git -C $kok ls-remote --heads origin 2>&1
        Write-Output ("Uzak erisim : " + $(if ($LASTEXITCODE -eq 0) { 'OK' } else { "BASARISIZ - $($ls -join ' ')" }))
        Write-Output "--- Degisen dosyalar (private)"
        $st = GitCalistir $kok @('status', '--short')
        Write-Output $(if ($st) { $st } else { '(degisiklik yok)' })
        if (Test-Path (Join-Path $pub '.git')) {
            OriginKontrol $pub $ayar.public 'Public'
            Write-Output "Public son  : $(GitCalistir $pub @('log', '-1', '--format=%h %s (%ci)'))"
        } else {
            Write-Output 'Public      : .public/.git yok (ilk yayin VS Code dan yapilir)'
        }
    }

    'private' {
        if ($Deger -notmatch '^v\d+\.\d+\.\d+ - \S') { Dur 'Commit mesaji "vX.Y.Z - ozet" bicimde olmali.' }
        if ($Deger -match '(?i)co-authored-by|generated with|claude') { Dur 'Commit mesajina imza / ortak yazar satiri eklenmez.' }
        OriginKontrol $kok $ayar.private 'Private'
        KimlikAyarla $kok $ayar

        GitCalistir $kok @('add', '-A') | Out-Null
        $st = GitCalistir $kok @('status', '--short')
        if (-not $st) { Dur 'Commit edilecek degisiklik yok.' }
        Write-Output "--- Commit edilecek dosyalar`n$st"
        GitCalistir $kok @('commit', '-q', '-m', $Deger) | Out-Null
        $sube = GitCalistir $kok @('rev-parse', '--abbrev-ref', 'HEAD')
        GitCalistir $kok @('push', '-q', 'origin', "HEAD:$sube") | Out-Null
        Write-Output "OK: private push ($sube) - $(GitCalistir $kok @('log', '-1', '--format=%h %s'))"
    }

    'public-hazirla' {
        if (-not (Test-Path (Join-Path $pub '.git'))) { Dur '.public/.git yok (ilk yayin VS Code dan yapilir).' }
        OriginKontrol $kok $ayar.private 'Private'
        OriginKontrol $pub $ayar.public 'Public'

        Write-Output '--- Hazirlik (public-hazirla.php)'
        $h = & $Php $HazirlaPhp $kok 2>&1 | ForEach-Object { "$_" }
        $hazirlaKod = $LASTEXITCODE
        Write-Output ($h -join "`n")

        Write-Output '--- gitleaks'
        # .gitleaksignore parmak izleri ".public/..." goreli yoldur: tarama proje kokunden goreli yolla yapilir.
        $leaksArg = @('dir', '.public', '--config', $GitleaksCfg, '--no-banner', '--redact')
        if (Test-Path -LiteralPath (Join-Path $kok '.gitleaksignore')) { $leaksArg += @('--gitleaks-ignore-path', '.gitleaksignore') }
        Push-Location -LiteralPath $kok
        try {
            $g = & gitleaks @leaksArg 2>&1 | ForEach-Object { "$_" }
            $leaksKod = $LASTEXITCODE
        } finally { Pop-Location }
        Write-Output ($g -join "`n")

        Write-Output '--- Public degisen dosyalar'
        $st = GitCalistir $pub @('status', '--short')
        Write-Output $(if ($st) { $st } else { '(degisiklik yok)' })

        $temiz = ($hazirlaKod -eq 0 -and $leaksKod -eq 0)
        @{ tarih = (Get-Date -Format 's'); temiz = $temiz; ozet = (PublicOzet $pub); hazirla = $hazirlaKod; gitleaks = $leaksKod } |
            ConvertTo-Json | Set-Content -LiteralPath $durumDosya -Encoding UTF8

        if ($temiz) {
            Write-Output 'SONUC: TEMIZ. Kullanici raporu gorup onaylarsa: github.cmd public <proje_kok> vX.Y.Z'
        } else {
            Dur "SONUC: BULGU VAR (hazirla=$hazirlaKod, gitleaks=$leaksKod). Public push YAPILMAZ." 2
        }
    }

    'public' {
        if ($Deger -notmatch '^v\d+\.\d+\.\d+$') { Dur 'Public commit mesaji yalniz "vX.Y.Z" olmali.' }
        if (-not (Test-Path $durumDosya)) { Dur 'Once github.cmd public-hazirla calistirilmali.' }
        $son = Get-Content $durumDosya -Raw -Encoding UTF8 | ConvertFrom-Json
        if (-not $son.temiz) { Dur 'Son hazirlik temiz degil; public push yapilmaz.' }
        if (((Get-Date) - [datetime]$son.tarih).TotalMinutes -gt 120) { Dur 'Son hazirlik 2 saatten eski; public-hazirla yeniden calistirilmali.' }
        if ((PublicOzet $pub) -ne $son.ozet) { Dur '.public hazirliktan sonra degismis; public-hazirla yeniden calistirilmali.' }
        OriginKontrol $pub $ayar.public 'Public'
        KimlikAyarla $pub $ayar

        GitCalistir $pub @('add', '-A') | Out-Null
        if (-not (GitCalistir $pub @('status', '--short'))) { Dur 'Public tarafta degisiklik yok.' }
        GitCalistir $pub @('commit', '-q', '-m', $Deger) | Out-Null
        $sube = GitCalistir $pub @('rev-parse', '--abbrev-ref', 'HEAD')
        GitCalistir $pub @('push', '-q', 'origin', "HEAD:$sube") | Out-Null
        Remove-Item -LiteralPath $durumDosya -Force
        Write-Output "OK: public push ($sube) - $(GitCalistir $pub @('log', '-1', '--format=%h %s'))"
    }

    default { Dur "Gecersiz komut: $Komut" }
}
exit 0
