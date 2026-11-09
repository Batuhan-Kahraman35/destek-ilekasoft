# AI Asistan - Calistirici (surekli calisan servis, Windows PowerShell 5.1)
#
# Ticket_AI_Oturumlari / Ticket_AI_Mesajlar kuyrugunu okur, Claude Code'u headless modda
# (claude -p, stream-json) ilgili proje dizininde calistirir ve ciktiyi satir satir DB'ye yazar.
# ai-asistan sayfasi bu tablolari okuyarak sohbeti canli gosterir.
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File admin\cron\ai-calistirici.ps1        # servis
#   powershell -NoProfile -ExecutionPolicy Bypass -File admin\cron\ai-calistirici.ps1 -Tek   # bir kez isle, cik
#
# Neden PowerShell: Plesk PHP CLI'da da .user.ini okunur; open_basedir PHP betigini kendi domain
# agacina kilitler (diger projeler ve claude.exe gorunmez). Kilit bilincli; dosya sistemi isleri ajan tarafinda.
#
# Asama 1: salt okuma. Izinli araclar Ticket_AI_Ayarlari.izinli_araclar ayarindan gelir;
# listede olmayan her arac Claude tarafindan otomatik reddedilir (-p modunda onay sorulmaz).
# Dosya ASCII tutulur (PS 5.1 BOM'suz dosyayi ANSI okur).

param(
    [switch]$Tek,
    [int]$Sure = 0
)

$ErrorActionPreference = 'Stop'

$CronDizin       = $PSScriptRoot
$ProjeKok        = (Resolve-Path (Join-Path $CronDizin '..\..')).Path
$VhostKok        = (Resolve-Path (Join-Path $CronDizin '..\..\..\..')).Path -replace '\\', '/'
$Php             = 'C:\Program Files (x86)\Plesk\Additional\PleskPHP83\php.exe'
$LogDosya        = Join-Path $CronDizin 'logs\ai-calistirici.log'
$TalimatDosya    = Join-Path $CronDizin 'ai-talimat.md'
$SistemKullanici = 16
$Utf8            = New-Object System.Text.UTF8Encoding($false)

$script:Baglanti = $null
$script:DurumCache = @{}

# ===================================================================
#  YARDIMCILAR
# ===================================================================

function Log([string]$Mesaj) {
    $satir = '[{0}] {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Mesaj
    try { [IO.File]::AppendAllText($LogDosya, $satir + "`r`n", $Utf8) } catch {}
    Write-Host $satir
}

# Windows komut satiri kurallarina gore tek argumani tirnaklar (CommandLineToArgvW)
function ArgTirnak([string]$Arg) {
    if ($Arg -ne '' -and $Arg -notmatch '[\s"]') { return $Arg }
    $s = $Arg -replace '(\\*)"', '$1$1\"'
    $s = $s -replace '(\\+)$', '$1$1'
    return '"' + $s + '"'
}

function SurecBaslat([string]$Exe, [string[]]$Argumanlar, [string]$Dizin = $null, [bool]$Girdi = $false, [hashtable]$Ortam = @{}) {
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName               = $Exe
    $psi.Arguments              = ($Argumanlar | ForEach-Object { ArgTirnak $_ }) -join ' '
    $psi.UseShellExecute        = $false
    $psi.CreateNoWindow         = $true
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardInput  = $Girdi
    $psi.StandardOutputEncoding = $Utf8
    if ($Dizin) { $psi.WorkingDirectory = $Dizin }
    foreach ($k in $Ortam.Keys) { $psi.EnvironmentVariables[$k] = [string]$Ortam[$k] }
    return [System.Diagnostics.Process]::Start($psi)
}

# Kisa suren bir komutu calistirip stdout'unu (UTF-8) dondurur
function SurecCikti([string]$Exe, [string[]]$Argumanlar) {
    $p = SurecBaslat $Exe $Argumanlar
    $cikti = $p.StandardOutput.ReadToEnd()
    $p.WaitForExit()
    return $cikti
}

# ===================================================================
#  VERITABANI
# ===================================================================

function DbAc {
    $cfgYol = ($ProjeKok -replace '\\', '/') + '/config/database.php'
    $json = SurecCikti $Php @('-r', "`$c = require '$cfgYol'; echo json_encode(`$c['connections']['sqlsrv']);")
    $cfg = $json | ConvertFrom-Json

    $b = New-Object System.Data.SqlClient.SqlConnectionStringBuilder
    $b['Data Source']            = $cfg.host
    $b['Initial Catalog']        = $cfg.database
    $b['User ID']                = $cfg.username
    $b['Password']               = $cfg.password
    $b['Encrypt']                = $false
    $b['TrustServerCertificate'] = $true
    $b['Application Name']       = 'Destek AI Asistan'

    $script:Baglanti = New-Object System.Data.SqlClient.SqlConnection $b.ConnectionString
    $script:Baglanti.Open()
}

function DbKomut([string]$Sql, [hashtable]$Param = @{}) {
    if ($null -eq $script:Baglanti -or $script:Baglanti.State -ne 'Open') { DbAc }
    $cmd = $script:Baglanti.CreateCommand()
    $cmd.CommandText    = $Sql
    $cmd.CommandTimeout = 60
    foreach ($k in $Param.Keys) {
        $v = $Param[$k]
        if ($null -eq $v) { $v = [DBNull]::Value }
        [void]$cmd.Parameters.AddWithValue("@$k", $v)
    }
    return $cmd
}

function DbTek([string]$Sql, [hashtable]$Param = @{}) {
    $cmd = DbKomut $Sql $Param
    $dt = New-Object System.Data.DataTable
    [void](New-Object System.Data.SqlClient.SqlDataAdapter $cmd).Fill($dt)
    if ($dt.Rows.Count -gt 0) { return $dt.Rows[0] }
    return $null
}

function DbCalistir([string]$Sql, [hashtable]$Param = @{}) {
    $cmd = DbKomut $Sql $Param
    [void]$cmd.ExecuteNonQuery()
}

function Deger($Satir, [string]$Kolon) {
    if ($null -eq $Satir) { return $null }
    $v = $Satir[$Kolon]
    if ($v -is [DBNull]) { return $null }
    return $v
}

# Ticket_AI_Ayarlari'ndan ayar okur; bos degerde varsayilan doner
function Ayar([string]$Anahtar, [string]$Varsayilan = $null) {
    $r = DbTek "SELECT Ticket_AI_Ayarlari_deger AS d FROM dbo.Ticket_AI_Ayarlari WHERE Ticket_AI_Ayarlari_anahtar = @a AND Durum = 1" @{ a = $Anahtar }
    $d = Deger $r 'd'
    if ($null -eq $d -or "$d" -eq '') { return $Varsayilan }
    return [string]$d
}

function DurumId([string]$Kod) {
    if (-not $script:DurumCache.ContainsKey($Kod)) {
        $r = DbTek "SELECT Tanim_AI_Durumlari_id AS id FROM dbo.Tanim_AI_Durumlari WHERE Tanim_AI_Durumlari_kod = @k" @{ k = $Kod }
        if ($null -eq $r) { throw "Tanim_AI_Durumlari'nda kod yok: $Kod" }
        $script:DurumCache[$Kod] = [int]$r['id']
    }
    return $script:DurumCache[$Kod]
}

function MesajEkle([int]$OturumId, [string]$Rol, [string]$Icerik, [string]$AracAdi = $null, $DurumId = $null) {
    DbCalistir "INSERT INTO dbo.Ticket_AI_Mesajlar
        (Ticket_AI_Mesajlar_oturum_id, Ticket_AI_Mesajlar_rol, Ticket_AI_Mesajlar_icerik, Ticket_AI_Mesajlar_arac_adi,
         Ticket_AI_Mesajlar_durum_id, OlusturanKullanici, OlusturmaTarihi, Durum)
        VALUES (@o, @r, @i, @a, @d, @k, GETDATE(), 1)" @{
        o = $OturumId; r = $Rol; i = $Icerik; a = $(if ($AracAdi) { $AracAdi } else { $null }); d = $DurumId; k = $SistemKullanici
    }
}

# Alan adlari yalniz bu betikten gelir (kullanici girdisi degil)
function OturumGuncelle([int]$OturumId, [hashtable]$Alanlar) {
    $set = @(); $param = @{ id = $OturumId; k = $SistemKullanici }
    $i = 0
    foreach ($kolon in $Alanlar.Keys) {
        $set += "$kolon = @p$i"; $param["p$i"] = $Alanlar[$kolon]; $i++
    }
    $set += 'Ticket_AI_Oturumlari_son_islem = GETDATE()', 'GuncelleyenKullanici = @k', 'GuncellemeTarihi = GETDATE()'
    DbCalistir ("UPDATE dbo.Ticket_AI_Oturumlari SET " + ($set -join ', ') + " WHERE Ticket_AI_Oturumlari_id = @id") $param
}

# ===================================================================
#  PROJE / TICKET
# ===================================================================

# claude_yolu npm sarmalayicisini (.cmd/.ps1) gosteriyorsa yanindaki claude.exe kullanilir
function ClaudeYolu {
    $yol = Ayar 'claude_yolu' ''
    if ($yol -match '\.(cmd|ps1)$') {
        $exe = Join-Path (Split-Path $yol) 'node_modules\@anthropic-ai\claude-code\bin\claude.exe'
        if (Test-Path $exe) { $yol = $exe }
    }
    if (-not $yol -or -not (Test-Path $yol)) { throw "claude_yolu ayari gecersiz: $yol" }
    return $yol
}

# ticket-ajan.php projeDiziniBul() ile ayni sira: <host>/httpdocs, <host>, <ust-domain>/<host>, <ust-domain>/httpdocs
function ProjeDizini([string]$ApiUrl) {
    if (-not $ApiUrl) { return $null }
    try { $hostAd = ([Uri]$ApiUrl).Host.ToLower() } catch { return $null }
    if (-not $hostAd) { return $null }

    $adaylar = @("$VhostKok/$hostAd/httpdocs", "$VhostKok/$hostAd")
    $parca = $hostAd.Split('.')
    for ($i = 1; $i -lt $parca.Count - 1; $i++) {
        $ust = ($parca[$i..($parca.Count - 1)]) -join '.'
        $adaylar += "$VhostKok/$ust/$hostAd", "$VhostKok/$ust/httpdocs"
    }
    foreach ($a in $adaylar) {
        if (Test-Path $a -PathType Container) { return ((Resolve-Path $a).Path -replace '\\', '/') }
    }
    return $null
}

# ticket-ajan.php detay ciktisi (ayni sorgular, tek kaynak). Ham JSON + cozulmus nesne doner.
function TicketDetay([int]$TicketId) {
    $ham = SurecCikti $Php @((Join-Path $CronDizin 'ticket-ajan.php'), 'detay', "$TicketId")
    try { $json = $ham | ConvertFrom-Json } catch { throw "Ticket detayi cozulemedi: $ham" }
    if (-not $json.success) { throw "Ticket detayi alinamadi: $ham" }
    return @{ ham = $ham; veri = $json.data }
}

function EnBuyukMesajId($Veri) {
    $idler = @($Veri.mesajlar | Where-Object { $_ } | ForEach-Object { [int]$_.mesaj_id })
    if ($idler.Count -eq 0) { return $null }
    return [int]($idler | Measure-Object -Maximum).Maximum
}

# Asistanin son gordugu mesajdan sonra ticket'a eklenenleri (ic not ve ekler dahil) istem metnine cevirir,
# oturumun son_mesaj_id'sini ilerletir. Yeni kayit yoksa $null.
function YeniTicketMesajlari($Satir) {
    $oturumId = [int]$Satir['Ticket_AI_Oturumlari_id']
    $sonId    = Deger $Satir 'Ticket_AI_Oturumlari_son_mesaj_id'
    try {
        $detay = TicketDetay ([int]$Satir['Ticket_AI_Oturumlari_ticket_id'])
    } catch {
        MesajEkle $oturumId 'sistem' ('Yeni ticket mesajlari okunamadi: ' + $_.Exception.Message)
        return $null
    }

    $mesajlar = @($detay.veri.mesajlar | Where-Object { $_ })
    if ($null -ne $sonId) {
        $yeniler = @($mesajlar | Where-Object { [int]$_.mesaj_id -gt [int]$sonId })
    } else {
        # son_mesaj_id'den once acilmis oturum: oturum acilis dakikasindan sonraki kayitlar
        # (tarih dakika hassasiyetinde; ayni dakikadaki ilk mesaj analizde zaten vardi)
        $esik = ([datetime]$Satir['oturum_tarih']).ToString('yyyy-MM-dd HH:mm')
        $yeniler = @($mesajlar | Where-Object { [string]$_.tarih -gt $esik })
    }
    $enBuyuk = EnBuyukMesajId $detay.veri
    if ($null -ne $enBuyuk) { OturumGuncelle $oturumId @{ Ticket_AI_Oturumlari_son_mesaj_id = $enBuyuk } }
    if ($yeniler.Count -eq 0) { return $null }

    $ekler = @($detay.veri.ekler | Where-Object { $_ })
    $bloklar = foreach ($m in $yeniler) {
        $tur   = $(if ([int]$m.ic_not -eq 1) { 'Ic not' } else { 'Mesaj' })
        $yazan = "$($m.yazan_ad) $($m.yazan_soyad)".Trim()
        $metin = ([string]$m.mesaj).Trim()
        if ($metin.Length -gt 4000) { $metin = $metin.Substring(0, 4000) + ' ... (kisaltildi)' }
        $b = "- [$($m.tarih)] $tur #$($m.mesaj_id) - $yazan ($($m.mesaj_kaynak)):`n$metin"
        foreach ($e in @($ekler | Where-Object { [int]$_.mesaj_id -eq [int]$m.mesaj_id })) {
            $b += "`n  Ek: $($e.dosya_adi) -> $($e.yerel_yol)"
        }
        $b
    }
    MesajEkle $oturumId 'sistem' "Ticket'a eklenen $($yeniler.Count) yeni kayit asistana iletildi."
    return "[Sistem: Son calistirmadan sonra ticket'a eklenen kayitlar (eskiden yeniye)]`n" + ($bloklar -join "`n`n")
}

# ===================================================================
#  CLAUDE CALISTIRMA
# ===================================================================

function OlayIsle([int]$OturumId, [string]$Satir, [hashtable]$Sonuc) {
    try { $olay = $Satir | ConvertFrom-Json } catch { return }   # buyuk tool_result satirlari atlanir

    switch ($olay.type) {
        'system' {
            if ($olay.subtype -eq 'init' -and $olay.session_id) {
                OturumGuncelle $OturumId @{ Ticket_AI_Oturumlari_claude_oturum = [string]$olay.session_id }
            }
        }
        'assistant' {
            foreach ($blok in @($olay.message.content)) {
                if ($blok.type -eq 'text' -and "$($blok.text)".Trim() -ne '') {
                    MesajEkle $OturumId 'asistan' $blok.text
                    $Sonuc.son_metin = [string]$blok.text
                } elseif ($blok.type -eq 'tool_use') {
                    $girdi = $blok.input | ConvertTo-Json -Compress -Depth 6
                    if ($girdi.Length -gt 4000) { $girdi = $girdi.Substring(0, 4000) }
                    MesajEkle $OturumId 'arac' $girdi ([string]$blok.name)
                }
            }
        }
        'result' {
            $u = $olay.usage
            $token = [int]$u.input_tokens + [int]$u.output_tokens + [int]$u.cache_creation_input_tokens + [int]$u.cache_read_input_tokens
            DbCalistir "UPDATE dbo.Ticket_AI_Oturumlari
                SET Ticket_AI_Oturumlari_toplam_token   = Ticket_AI_Oturumlari_toplam_token + @t,
                    Ticket_AI_Oturumlari_toplam_maliyet = Ticket_AI_Oturumlari_toplam_maliyet + @m,
                    Ticket_AI_Oturumlari_claude_oturum  = COALESCE(@s, Ticket_AI_Oturumlari_claude_oturum)
                WHERE Ticket_AI_Oturumlari_id = @id" @{
                t = $token; m = [decimal]$olay.total_cost_usd; s = $(if ($olay.session_id) { [string]$olay.session_id } else { $null }); id = $OturumId
            }

            $hataVar = [bool]$olay.is_error -or $olay.subtype -ne 'success'
            $Sonuc.basarili = -not $hataVar
            $Sonuc.hata = $(if ($hataVar) { "Claude: $($olay.subtype) $($olay.result)" } else { $null })

            $redler = @($olay.permission_denials | Where-Object { $_ })
            if ($redler.Count -gt 0) {
                MesajEkle $OturumId 'sistem' ('Izin verilmeyen arac denemeleri reddedildi: ' + (($redler | ForEach-Object { $_.tool_name }) -join ', '))
            }
        }
    }
}

function VirgulListe([string]$Deger) {
    return @($Deger.Split(',') | ForEach-Object { $_.Trim() } | Where-Object { $_ })
}

# $YedekDizin verilirse (yazma modu) proje dizininde Edit/Write izni acilir; yasak yollar deny kurali olur,
# PreToolUse kancasi (ai-yedekle.ps1) her dosyanin ilk halini yedekler ve proje disina yazmayi engeller.
function ClaudeCalistir([int]$OturumId, [string]$ClaudeOturum, [string]$Istem, [string]$Dizin, [string]$YedekDizin = $null) {
    $sorguDizini = Ayar 'sorgu_dizini' 'C:/ProgramData/TicketAI/sorgu'
    $zamanAsimi  = [int](Ayar 'zaman_asimi_sn' '900')
    $araclar     = VirgulListe (Ayar 'izinli_araclar' 'Read,Grep,Glob')
    $yasaklar    = @()
    $ortam       = @{}

    $arg = @('-p', '--output-format', 'stream-json', '--verbose', '--strict-mcp-config',
             '--append-system-prompt-file', $TalimatDosya,
             '--system-prompt-snapshot', 'off',      # --resume'da da guncel ai-talimat.md kullanilsin
             '--add-dir', $VhostKok, '--add-dir', $sorguDizini,
             '--max-turns', (Ayar 'azami_tur' '40'))
    $model = Ayar 'model'
    if ($model) { $arg += '--model', $model }
    if ($ClaudeOturum) { $arg += '--resume', $ClaudeOturum }

    if ($YedekDizin) {
        $proje = $Dizin.TrimEnd('/')
        $araclar += "Edit($proje/**)"
        # "Versiyon gonder" GitHub adimlari: yalniz ai-github.ps1 sarmalayicisi (serbest git yok)
        $araclar += VirgulListe (Ayar 'github_araclari' 'Bash(C:/ProgramData/TicketAI/github.cmd:*)')
        $yasaklar = @(VirgulListe (Ayar 'yasak_yollar' 'config/*.php,config/*.key,config/*.json,config/*.ini,.env*,.git/**,.github-repo') |
            ForEach-Object { "Edit($proje/$_)" })
        $kanca = 'powershell -NoProfile -ExecutionPolicy Bypass -File "' + ((Join-Path $CronDizin 'ai-yedekle.ps1') -replace '\\', '/') + '"'
        $ayar = @{ hooks = @{ PreToolUse = @(@{ matcher = 'Edit|Write|MultiEdit|NotebookEdit'; hooks = @(@{ type = 'command'; command = $kanca }) }) } }
        $arg += '--settings', ($ayar | ConvertTo-Json -Depth 8 -Compress)
        $ortam = @{ AI_YEDEK_DIZIN = $YedekDizin; AI_PROJE_DIZIN = $proje; AI_SORGU_DIZIN = $sorguDizini }
    }
    # Degisken sayida deger alirlar; en sona konur (istem stdin'den gider)
    if ($yasaklar.Count -gt 0) { $arg += '--disallowedTools'; $arg += $yasaklar }
    $arg += '--allowedTools'
    $arg += $araclar

    $sonuc = @{ basarili = $false; hata = 'Claude sonuc donmeden kapandi.'; son_metin = $null }

    $p = SurecBaslat (ClaudeYolu) $arg $Dizin $true $ortam
    $bayt = $Utf8.GetBytes($Istem)
    $p.StandardInput.BaseStream.Write($bayt, 0, $bayt.Length)
    $p.StandardInput.Close()

    $bas = Get-Date
    $okuma = $null
    while ($true) {
        if ($null -eq $okuma) { $okuma = $p.StandardOutput.ReadLineAsync() }
        if ($okuma.Wait(1000)) {
            $satir = $okuma.Result
            $okuma = $null
            if ($null -eq $satir) { break }
            if ($satir.Trim() -ne '') { OlayIsle $OturumId $satir $sonuc }
            continue
        }
        if (((Get-Date) - $bas).TotalSeconds -gt $zamanAsimi) {
            try { $p.Kill() } catch {}
            $sonuc.basarili = $false
            $sonuc.hata = "Zaman asimi ($zamanAsimi sn)."
            break
        }
    }
    [void]$p.WaitForExit(10000)
    return $sonuc
}

# ===================================================================
#  IS TIPLERI
# ===================================================================

function IsBitir([int]$OturumId, [hashtable]$Sonuc, [bool]$IlkAnaliz) {
    if ($Sonuc.basarili) {
        $alanlar = @{ Ticket_AI_Oturumlari_durum_id = (DurumId 'yanit_bekliyor'); Ticket_AI_Oturumlari_son_hata = $null }
        if ($IlkAnaliz -and $Sonuc.son_metin) {
            $ozet = $Sonuc.son_metin
            if ($ozet.Length -gt 2000) { $ozet = $ozet.Substring(0, 2000) }
            $alanlar['Ticket_AI_Oturumlari_ozet'] = $ozet
        }
        OturumGuncelle $OturumId $alanlar
        Log "Tamamlandi: oturum=$OturumId"
        return
    }
    MesajEkle $OturumId 'sistem' ('Hata: ' + $Sonuc.hata)
    OturumGuncelle $OturumId @{ Ticket_AI_Oturumlari_durum_id = (DurumId 'hata'); Ticket_AI_Oturumlari_son_hata = $Sonuc.hata }
    Log "Hata: oturum=$OturumId $($Sonuc.hata)"
}

# Yeni oturum: ticket'i ilk kez analiz eder
function AnalizYap($Oturum) {
    $oturumId = [int]$Oturum['Ticket_AI_Oturumlari_id']
    $ticketId = [int]$Oturum['Ticket_AI_Oturumlari_ticket_id']
    Log "Analiz basladi: oturum=$oturumId ticket=$ticketId"
    OturumGuncelle $oturumId @{ Ticket_AI_Oturumlari_durum_id = (DurumId 'calisiyor') }

    try {
        $detay = TicketDetay $ticketId
    } catch {
        $h = $_.Exception.Message
        MesajEkle $oturumId 'sistem' $h
        OturumGuncelle $oturumId @{ Ticket_AI_Oturumlari_durum_id = (DurumId 'hata'); Ticket_AI_Oturumlari_son_hata = $h }
        return
    }

    $dizin = ProjeDizini ([string]$detay.veri.ticket.kaynak_api_url)
    if ($dizin) {
        $dizinNotu = "Proje dizini (calisma dizinin): $dizin"
    } else {
        $dizinNotu = "Proje dizini otomatik bulunamadi (kaynak: $($detay.veri.ticket.kaynak_ad)); $VhostKok altinda ara."
        MesajEkle $oturumId 'sistem' $dizinNotu
        $dizin = $VhostKok
    }
    OturumGuncelle $oturumId @{ Ticket_AI_Oturumlari_proje_dizin = $dizin; Ticket_AI_Oturumlari_son_mesaj_id = (EnBuyukMesajId $detay.veri) }

    $istem = "Ticket #$ticketId icin ilk analizi yap.`n$dizinNotu`n`n" + '```json' + "`n" + $detay.ham.Trim() + "`n" + '```'
    $sonuc = ClaudeCalistir $oturumId $null $istem $dizin
    IsBitir $oturumId $sonuc $true
}

# Kullanicinin sayfadan yazdigi mesaji ayni oturuma (--resume) iletir
function MesajYanitla($Satir) {
    $oturumId = [int]$Satir['Ticket_AI_Oturumlari_id']
    $mesajId  = [int]$Satir['Ticket_AI_Mesajlar_id']
    Log "Mesaj isleniyor: oturum=$oturumId mesaj=$mesajId"

    DbCalistir "UPDATE dbo.Ticket_AI_Mesajlar SET Ticket_AI_Mesajlar_durum_id = @d, GuncellemeTarihi = GETDATE() WHERE Ticket_AI_Mesajlar_id = @id" @{ d = (DurumId 'calisiyor'); id = $mesajId }
    OturumGuncelle $oturumId @{ Ticket_AI_Oturumlari_durum_id = (DurumId 'calisiyor') }

    $dizin = Deger $Satir 'Ticket_AI_Oturumlari_proje_dizin'
    if (-not $dizin) { $dizin = $VhostKok }

    # Yazma izni yalniz kullanicinin mesajiyla ve yalniz cozulmus proje dizininde acilir
    # (otomatik ilk analiz musteri metnini okudugu icin hep salt okuma kalir)
    $istem = [string]$Satir['Ticket_AI_Mesajlar_icerik']
    $yeni = YeniTicketMesajlari $Satir
    if ($yeni) { $istem = $yeni + "`n`n[Kullanicinin mesaji]`n" + $istem }
    $yedekDizin = $null
    if ((Ayar 'yazma_izni' '0') -eq '1' -and $dizin -ne $VhostKok) {
        $yedekDizin = (Join-Path (Ayar 'yedek_dizini' 'C:/ProgramData/TicketAI/yedek') "$oturumId\$mesajId") -replace '\\', '/'
        $istem = "[Sistem: Bu calistirmada $dizin altinda dosya duzenleme ve github.cmd izni var. Degisiklikler canli siteye " +
                 "aninda yansir; yalniz kullanicinin onayladigi degisikligi yap. Su an: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')]`n`n" + $istem
    }

    $sonuc = ClaudeCalistir $oturumId (Deger $Satir 'Ticket_AI_Oturumlari_claude_oturum') $istem $dizin $yedekDizin
    if ($yedekDizin) { DegisiklikKaydet $oturumId $yedekDizin $dizin }

    $kod = $(if ($sonuc.basarili) { 'tamamlandi' } else { 'hata' })
    DbCalistir "UPDATE dbo.Ticket_AI_Mesajlar SET Ticket_AI_Mesajlar_durum_id = @d, GuncellemeTarihi = GETDATE() WHERE Ticket_AI_Mesajlar_id = @id" @{ d = (DurumId $kod); id = $mesajId }
    IsBitir $oturumId $sonuc $false
}

# ===================================================================
#  DEGISIKLIK KAYDI / GERI ALMA
# ===================================================================

function GitYolu {
    $g = Get-Command git -ErrorAction SilentlyContinue
    if ($g) { return $g.Source }
    return 'C:\Program Files\Git\cmd\git.exe'
}

function ManifestOku([string]$YedekDizin) {
    $manifest = Join-Path $YedekDizin 'manifest.jsonl'
    if (-not (Test-Path $manifest)) { return @() }
    return @(Get-Content -LiteralPath $manifest -Encoding UTF8 | Where-Object { $_ } | ForEach-Object { $_ | ConvertFrom-Json })
}

# Kancanin yedekledigi dosyalardan birlesik diff uretir, 'degisiklik' mesaji olarak yazar
function DegisiklikKaydet([int]$OturumId, [string]$YedekDizin, [string]$ProjeDizin) {
    $kayitlar = ManifestOku $YedekDizin
    if ($kayitlar.Count -eq 0) { return }

    $bos = Join-Path $YedekDizin 'bos.txt'
    if (-not (Test-Path $bos)) { [IO.File]::WriteAllText($bos, '') }
    $projeTam = ([IO.Path]::GetFullPath($ProjeDizin)).TrimEnd('\') + '\'
    $azami = [int](Ayar 'diff_azami_karakter' '60000')

    $dosyalar = @(); $diffMetin = ''
    foreach ($k in $kayitlar) {
        $goreli = ([string]$k.yol).Substring($projeTam.Length) -replace '\\', '/'
        $once   = $(if ($k.yeni) { $bos } else { [string]$k.yedek })
        $sonra  = $(if (Test-Path -LiteralPath $k.yol) { [string]$k.yol } else { $bos })
        $ham = SurecCikti (GitYolu) @('diff', '--no-index', '--no-color', '-U3', '--', $once, $sonra)
        # git basliklari yedek yollarini gosterir; goreli yolla degistir, hunk'lardan itibaren al
        $satirlar = @($ham -split "`n")
        $ilk = 0
        while ($ilk -lt $satirlar.Count -and $satirlar[$ilk] -notmatch '^@@') { $ilk++ }
        $govde = ($satirlar | Select-Object -Skip $ilk) -join "`n"
        $diffMetin += "--- a/$goreli`n+++ b/$goreli`n$govde`n"
        $dosyalar += [ordered]@{ yol = $goreli; yeni = [bool]$k.yeni; silindi = (-not (Test-Path -LiteralPath $k.yol)) }
    }
    if ($diffMetin.Length -gt $azami) { $diffMetin = $diffMetin.Substring(0, $azami) + "`n... (kisaltildi)" }

    $icerik = [ordered]@{ yedek = $YedekDizin; dosyalar = $dosyalar; diff = $diffMetin } | ConvertTo-Json -Depth 5 -Compress
    MesajEkle $OturumId 'degisiklik' $icerik
    Log "Degisiklik kaydedildi: oturum=$OturumId dosya=$($dosyalar.Count)"
}

# 'geri_al' istegi: icerik = degisiklik mesajinin id'si. Yedekler geri yuklenir, yeni dosyalar silinir.
function GeriAl($Satir) {
    $oturumId = [int]$Satir['Ticket_AI_Oturumlari_id']
    $istekId  = [int]$Satir['Ticket_AI_Mesajlar_id']
    $hedefId  = [int]$Satir['Ticket_AI_Mesajlar_icerik']
    Log "Geri alma: oturum=$oturumId degisiklik=$hedefId"

    $kod = 'tamamlandi'
    try {
        $hedef = DbTek "SELECT Ticket_AI_Mesajlar_icerik AS icerik FROM dbo.Ticket_AI_Mesajlar
            WHERE Ticket_AI_Mesajlar_id = @id AND Ticket_AI_Mesajlar_oturum_id = @o AND Ticket_AI_Mesajlar_rol = 'degisiklik'" @{ id = $hedefId; o = $oturumId }
        if (-not $hedef) { throw 'Degisiklik kaydi bulunamadi.' }

        $veri = [string]$hedef['icerik'] | ConvertFrom-Json
        $kayitlar = ManifestOku ([string]$veri.yedek)
        if ($kayitlar.Count -eq 0) { throw 'Yedek bulunamadi: ' + $veri.yedek }

        foreach ($k in $kayitlar) {
            if ($k.yeni) {
                if (Test-Path -LiteralPath $k.yol) { Remove-Item -LiteralPath $k.yol -Force }
            } else {
                Copy-Item -LiteralPath $k.yedek -Destination $k.yol -Force
            }
        }
        DbCalistir "UPDATE dbo.Ticket_AI_Mesajlar SET Ticket_AI_Mesajlar_durum_id = @d, GuncellemeTarihi = GETDATE() WHERE Ticket_AI_Mesajlar_id = @id" @{ d = (DurumId 'iptal'); id = $hedefId }
        MesajEkle $oturumId 'sistem' "Degisiklik geri alindi ($($kayitlar.Count) dosya ilk haline dondu). Asistan bunu bilmiyor; gerekirse mesajla belirtin."
    } catch {
        $kod = 'hata'
        MesajEkle $oturumId 'sistem' ('Geri alma hatasi: ' + $_.Exception.Message)
    }
    DbCalistir "UPDATE dbo.Ticket_AI_Mesajlar SET Ticket_AI_Mesajlar_durum_id = @d, GuncellemeTarihi = GETDATE() WHERE Ticket_AI_Mesajlar_id = @id" @{ d = (DurumId $kod); id = $istekId }
}

# Siradaki isi isler: once kullanici istekleri (mesaj / geri al), sonra yeni analizler. Is yapildiysa $true.
function SiradakiIs {
    $kuyrukta = DurumId 'kuyrukta'

    $mesaj = DbTek "SELECT TOP 1 m.Ticket_AI_Mesajlar_id, m.Ticket_AI_Mesajlar_icerik, m.Ticket_AI_Mesajlar_rol,
               o.Ticket_AI_Oturumlari_id, o.Ticket_AI_Oturumlari_claude_oturum, o.Ticket_AI_Oturumlari_proje_dizin,
               o.Ticket_AI_Oturumlari_ticket_id, o.Ticket_AI_Oturumlari_son_mesaj_id, o.OlusturmaTarihi AS oturum_tarih
        FROM dbo.Ticket_AI_Mesajlar m
        INNER JOIN dbo.Ticket_AI_Oturumlari o ON o.Ticket_AI_Oturumlari_id = m.Ticket_AI_Mesajlar_oturum_id
        WHERE m.Ticket_AI_Mesajlar_rol IN ('kullanici', 'geri_al') AND m.Ticket_AI_Mesajlar_durum_id = @k
          AND m.Durum = 1 AND o.Durum = 1
        ORDER BY m.Ticket_AI_Mesajlar_id" @{ k = $kuyrukta }
    if ($mesaj) {
        if ($mesaj['Ticket_AI_Mesajlar_rol'] -eq 'geri_al') { GeriAl $mesaj } else { MesajYanitla $mesaj }
        return $true
    }

    $oturum = DbTek "SELECT TOP 1 Ticket_AI_Oturumlari_id, Ticket_AI_Oturumlari_ticket_id
        FROM dbo.Ticket_AI_Oturumlari
        WHERE Ticket_AI_Oturumlari_durum_id = @k AND Durum = 1
        ORDER BY Ticket_AI_Oturumlari_id" @{ k = $kuyrukta }
    if (-not $oturum) { return $false }

    $limit = [int](Ayar 'gunluk_limit' '20')
    $bugun = DbTek "SELECT COUNT(*) AS adet FROM dbo.Ticket_AI_Oturumlari
        WHERE Ticket_AI_Oturumlari_claude_oturum IS NOT NULL
          AND OlusturmaTarihi >= CAST(CAST(GETDATE() AS DATE) AS DATETIME)"
    if ([int]$bugun['adet'] -ge $limit) { return $false }   # kuyrukta kalir

    AnalizYap $oturum
    return $true
}

# ===================================================================
#  GIRIS NOKTASI
# ===================================================================

$mutex = New-Object System.Threading.Mutex($false, 'Global\DestekAIAsistan')
try {
    $alindi = $mutex.WaitOne(0)
} catch [System.Threading.AbandonedMutexException] {
    $alindi = $true
}
if (-not $alindi) { Write-Host 'Calistirici zaten calisiyor.'; exit 0 }

try {
    # Onceki surec yarida kaldiysa "calisiyor"da asili kalan kayitlari hataya cek
    $calisiyor = DurumId 'calisiyor'
    $hataId    = DurumId 'hata'
    DbCalistir "UPDATE dbo.Ticket_AI_Oturumlari SET Ticket_AI_Oturumlari_durum_id = @h,
            Ticket_AI_Oturumlari_son_hata = N'Calistirici yeniden basladi, islem yarida kaldi.', GuncellemeTarihi = GETDATE()
        WHERE Ticket_AI_Oturumlari_durum_id = @c" @{ h = $hataId; c = $calisiyor }
    DbCalistir "UPDATE dbo.Ticket_AI_Mesajlar SET Ticket_AI_Mesajlar_durum_id = @h, GuncellemeTarihi = GETDATE()
        WHERE Ticket_AI_Mesajlar_durum_id = @c" @{ h = $hataId; c = $calisiyor }

    Log ('Calistirici basladi' + $(if ($Tek) { ' (tek)' } else { '' }))
    $bas = Get-Date

    while ($true) {
        $isVardi = $false
        try {
            $isVardi = SiradakiIs
        } catch {
            Log "HATA: $($_.Exception.Message)"
            if ($Tek) { break }
            if ($script:Baglanti) { try { $script:Baglanti.Close() } catch {} }   # sonraki turda yeniden baglanir
            Start-Sleep -Seconds 30
        }

        if (-not $isVardi) {
            if ($Tek) { break }
            Start-Sleep -Seconds ([int](Ayar 'bekleme_sn' '3'))
        }
        if ($Sure -gt 0 -and ((Get-Date) - $bas).TotalSeconds -ge $Sure) { break }
    }
    Log 'Calistirici durdu'
} catch {
    Log "KRITIK: $($_.Exception.Message)"
    exit 1
} finally {
    if ($script:Baglanti) { $script:Baglanti.Close() }
    $mutex.ReleaseMutex()
}
