<?php
// Klasemen Galatama: hitung poin dari embed ikan di discord_messages, per Display Name
require __DIR__ . '/config_discord.php';
header('Content-Type: application/json; charset=utf-8');
$T0 = microtime(true);

const TIER   = ['forgotten' => 10, 'secret' => 5, 'myth' => 2, 'legend' => 1]; // cocok sebagian kata (nilai bawaan, bisa diubah dari web)
const VARIAN = ['shiny' => 1, 'big' => 1, 'mutation' => 1];

// Pada aturan Khusus: Big tidak dihitung untuk ikan ini bila beratnya >= NO_BIG_MIN kg
const NO_BIG_FISH = 'frostbon';
const NO_BIG_MIN  = 100;

// Ikan yang rarity-nya dipaksa berbeda dari yang dikirim game (nama dicocokkan sebagian kata, huruf kecil)
// Contoh: Panther Eel dikirim game sebagai Mythic, tapi dihitung sebagai Secret
const TIER_OVERRIDE = ['panther eel' => 'secret', 'yellowfin' => 'legend'];

// ===== Pengaturan perawatan (backup, pembersihan, disk) =====
const KEEP_MSG_DAYS   = 2;     // pesan Discord non-tangkapan dihapus setelah N hari
const KEEP_WH_DAYS    = 14;    // webhook_logs disimpan N hari
const LOW_GB          = 2.0;   // sisa disk di bawah ini = mode hemat (pembersihan agresif, backup berhenti)
const MAX_LOG_MB      = 50;    // log Apache dikosongkan bila lebih besar dari ini
const BACKUP_KEEP     = 30;    // jumlah file backup yang disimpan
const BACKUP_KEEP_LOW = 5;     // jumlah backup saat disk hampir penuh
const BACKUP_EVERY    = 120;   // detik, jeda cek backup (file baru hanya dibuat bila data berubah)
const CLEAN_EVERY     = 600;   // detik, jeda pembersihan normal
const CLEAN_EVERY_LOW = 60;    // detik, jeda pembersihan saat disk hampir penuh
const SYNC_EVERY      = 1;   // detik, jeda minimal antar sinkron Discord (gateway.js sudah real-time, ini hanya cadangan; 3 juga cukup)

// Webhook langsung (melewati Discord). Isi kata sandi acak untuk mengaktifkan; kosong = nonaktif.
// Alamat: klasemen_api.php?hook=KATA_SANDI  (menerima payload yang sama dengan webhook Discord)
const HOOK_KEY = 'EHqbfU1X1hosnqXseRKB';

function clean(string $s): string { return strtolower(trim(preg_replace('/[*_`~|]/u', '', $s))); }

// Poin tier bisa diubah dari web (disimpan di klasemen_meta: pt_forgotten, pt_secret, pt_myth, pt_legend). Bawaan = konstanta TIER
function tier_points(PDO $pdo): array {
    $out = TIER;
    foreach ($pdo->query("SELECT k, v FROM klasemen_meta WHERE k LIKE 'pt\\_%'")->fetchAll() as $r) {
        $t = substr($r['k'], 3);
        if (isset($out[$t])) $out[$t] = max(0, (int)$r['v']);
    }
    return $out;
}

// Rarity mana yang dihitung (klasemen_meta: on_forgotten, on_secret, on_myth, on_legend). Bawaan = semua aktif
function tier_enabled(PDO $pdo): array {
    $out = array_fill_keys(array_keys(TIER), true);
    foreach ($pdo->query("SELECT k, v FROM klasemen_meta WHERE k LIKE 'on\\_%'")->fetchAll() as $r) {
        $t = substr($r['k'], 3);
        if (isset($out[$t])) $out[$t] = ((int)$r['v'] === 1);
    }
    return $out;
}

// Sesi aktif = baris klasemen_sesi yang belum selesai. Dibuat otomatis bila belum ada.
function sesi_now(PDO $pdo): array {
    $r = $pdo->query('SELECT id, judul, mulai FROM klasemen_sesi WHERE selesai IS NULL ORDER BY id DESC LIMIT 1')->fetch();
    if ($r) return ['id' => (int)$r['id'], 'judul' => $r['judul'], 'mulai' => $r['mulai']];
    $n = (int)$pdo->query('SELECT COUNT(*) FROM klasemen_sesi')->fetchColumn() + 1;
    $now = gmdate('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO klasemen_sesi (judul, mulai) VALUES (?, ?)')->execute(["Sesi $n", $now]);
    return ['id' => (int)$pdo->lastInsertId(), 'judul' => "Sesi $n", 'mulai' => $now];
}

function http_json(string $url, ?array $body = null): ?array {
    $ch = curl_init($url);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_SSL_VERIFYPEER => false,
          CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: klasemen-xampp']];
    if ($body !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = json_encode($body); }
    curl_setopt_array($ch, $o);
    $r = curl_exec($ch); curl_close($ch);
    return $r ? json_decode($r, true) : null;
}

function roblox_user(string $usn): ?array {   // cocokkan username persis -> ['id','name','displayName']
    $r = http_json('https://users.roblox.com/v1/usernames/users', ['usernames' => [$usn], 'excludeBannedUsers' => false]);
    return $r['data'][0] ?? null;
}

// Cari akun Roblox dari DISPLAY NAME (nama di webhook) -> ['id','name'(username),'displayName'] atau null
function roblox_by_display(string $dn): ?array {
    $r = http_json('https://users.roblox.com/v1/users/search?limit=10&keyword=' . rawurlencode($dn));
    foreach (($r['data'] ?? []) as $u)
        if (strcasecmp($u['displayName'] ?? '', $dn) === 0) return $u;
    $u = roblox_user($dn);   // bila ternyata nama itu username, dan display name-nya sama
    return ($u && strcasecmp($u['displayName'] ?? '', $dn) === 0) ? $u : null;
}

// Avatar + status verifikasi: [userId|null, url|null, status, displayRoblox|null]
// status: ok (username valid & display sama), beda (display berbeda), tebak (hanya via display), tidak (username tak ada), cek
function roblox_avatar(string $usn, string $display): array {
    $uid = null; $st = 'cek'; $rd = null;
    if ($usn !== '') {
        if ($u = roblox_user($usn)) {
            $uid = $u['id']; $rd = $u['displayName'];
            $st = ($display === '' || strcasecmp($rd, $display) === 0) ? 'ok' : 'beda';
        } else $st = 'tidak';
    }
    if (!$uid && $display !== '') {
        if ($u = roblox_user($display)) $uid = $u['id'];
        else foreach ((http_json('https://users.roblox.com/v1/users/search?limit=10&keyword=' . rawurlencode($display))['data'] ?? []) as $u)
            if (strcasecmp($u['displayName'] ?? '', $display) === 0) { $uid = $u['id']; break; }
        if ($uid && $usn === '') { $st = 'tebak'; $rd = $display; }
    }
    if (!$uid) return [null, null, $st, $rd];
    $t = http_json("https://thumbnails.roblox.com/v1/users/avatar-headshot?userIds=$uid&size=150x150&format=Png");
    $img = $t['data'][0] ?? [];
    return [$uid, ($img['state'] ?? '') === 'Completed' ? ($img['imageUrl'] ?? null) : null, $st, $rd];
}

// ===== Riwayat pemain: username + avatar disimpan permanen agar mudah dicari lagi =====
// $bump = 1 -> dihitung "main lagi" (dipakai saat peserta ditambahkan); 0 -> hanya perbarui data/avatar
function riwayat_save(PDO $pdo, string $usn, string $nama, string $display, $uid, ?string $avatar, int $bump = 0): void {
    if ($usn === '') return;
    $now = gmdate('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO klasemen_riwayat (usn, nama, display_name, user_id, avatar, first_seen, last_played, plays)
        VALUES (?,?,?,?,?,?,?,1)
        ON DUPLICATE KEY UPDATE
            nama = IF(? <> \'\', ?, nama),
            display_name = IF(? <> \'\', ?, display_name),
            user_id = COALESCE(?, user_id),
            avatar = COALESCE(?, avatar),
            last_played = IF(? = 1, ?, last_played),
            plays = plays + ?')
        ->execute([$usn, $nama, $display, $uid, $avatar, $now, $now,
                   $nama, $nama, $display, $display, $uid, $avatar, $bump, $now, $bump]);
}

// Pembekuan akhir timer: saat timer berhenti (habis / dijeda), tangkapan yang SUDAH masuk database & sempat tampil di layar
// tetap dihitung, walau jam pesan (msg_time) sedikit melewati detik akhir (selisih jam PC dengan Discord). Batas atas: detik.
// Pesan yang masuk SESUDAH timer berhenti tidak ikut dihitung. Tidak ada poin yang hilang (rollback) setelah tampil.
const AKHIR_TOLERANSI = 5;
const DURASI = 4200; // durasi bawaan 1:10:00 (bisa diubah lewat kolom DURASI)

// Durasi ronde (detik) disimpan di klasemen_meta
function durasi(PDO $pdo): int {
    $v = (int)$pdo->query("SELECT v FROM klasemen_meta WHERE k = 'durasi'")->fetchColumn();
    return $v > 0 ? $v : DURASI;
}

// Kolom t_cut = batas akhir hitung (>= t_end), dibekukan saat timer berhenti
function timer_cols(PDO $pdo): void {
    static $ok = false; if ($ok) return; $ok = true;
    try { if (!$pdo->query("SHOW COLUMNS FROM klasemen_timer LIKE 't_cut'")->fetch()) $pdo->exec('ALTER TABLE klasemen_timer ADD COLUMN t_cut DATETIME NULL'); } catch (Throwable $e) {}
}
// Batas hitung saat timer berhenti di $e0: pesan terbaru yang SUDAH ada di database (maks. AKHIR_TOLERANSI detik setelah $e0) ikut dibekukan
function bekukan_cut(PDO $pdo, int $s0, int $e0): int {
    try {
        $q = $pdo->prepare("SELECT MAX(msg_time) FROM discord_messages WHERE embeds <> '[]' AND msg_time >= ? AND msg_time <= ?");
        $q->execute([gmdate('Y-m-d H:i:s', $s0), gmdate('Y-m-d H:i:s', $e0 + AKHIR_TOLERANSI)]);
        $m = $q->fetchColumn();
        return $m ? max($e0, (int)strtotime($m . ' UTC')) : $e0;
    } catch (Throwable $e) { return $e0; }
}

// Timer di server: poin hanya dihitung untuk pesan yang masuk saat timer berjalan
function timer_state(PDO $pdo): array {
    timer_cols($pdo);
    $D = durasi($pdo);
    $now = time(); $iv = []; $used = 0; $open = false;
    foreach ($pdo->query('SELECT id, t_start, t_end, t_cut FROM klasemen_timer ORDER BY id')->fetchAll() as $r) {
        $s0 = strtotime($r['t_start'] . ' UTC');
        $e0 = $r['t_end'] ? strtotime($r['t_end'] . ' UTC') : null;
        if ($e0 === null) {
            $left = $D - $used;
            if ($now - $s0 >= $left) {   // waktu habis -> berhenti otomatis
                $e0 = $s0 + max(0, $left);
                $cut0 = bekukan_cut($pdo, $s0, $e0);
                $pdo->prepare('UPDATE klasemen_timer SET t_end = ?, t_cut = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', $e0), gmdate('Y-m-d H:i:s', $cut0), $r['id']]);
                $r['t_cut'] = gmdate('Y-m-d H:i:s', $cut0);
            } else $open = true;
        }
        $used += ($e0 ?? $now) - $s0;
        $iv[] = [$s0, $e0, $e0 === null ? null : max($e0, $r['t_cut'] ? (int)strtotime($r['t_cut'] . ' UTC') : $e0)];   // [mulai, selesai, batas hitung]
    }
    return ['iv' => $iv, 'remaining' => max(0, $D - $used), 'running' => $open, 'total' => $D];
}

// ===== Sisa disk =====
function disk_info(): array {
    $f = @disk_free_space(__DIR__);
    $gb = $f === false ? null : round($f / 1073741824, 1);
    return ['free_gb' => $gb, 'low' => $gb !== null && $gb < LOW_GB];
}

// ===== Backup: peserta, poin manual, dan timer ke file JSON =====
// File baru hanya dibuat bila isi data berubah dari backup terakhir. Tabel rusak -> backup dilewati (backup lama tidak tertimpa).
function backup_db(PDO $pdo): bool {
    $dir = __DIR__ . '/backup';
    if (!is_dir($dir)) { @mkdir($dir); @file_put_contents("$dir/.htaccess", "Require all denied\n"); }
    $out = [];
    foreach (['klasemen_peserta', 'klasemen_meta', 'klasemen_timer', 'klasemen_sesi', 'klasemen_aturan'] as $t) {
        try { $out[$t] = $pdo->query("SELECT * FROM $t")->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { return false; }
    }
    $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $h = md5($json); $hf = "$dir/.last";
    if (is_file($hf) && file_get_contents($hf) === $h) return false;   // tidak ada perubahan
    file_put_contents("$dir/klasemen_" . date('Ymd_His') . '.json', $json);
    file_put_contents($hf, $h);
    return true;
}

// ===== Pembersihan agar disk tidak penuh =====
// (OPTIMIZE TABLE sengaja dihapus: mengunci tabel dan membuat sinkron Discord tertahan / delay)
function cleanup_db(PDO $pdo, bool $low = false): void {
    $start = $pdo->query("SELECT v FROM klasemen_meta WHERE k = 'start'")->fetchColumn() ?: '1970-01-01 00:00:00';
    $cut = gmdate('Y-m-d H:i:s', time() - ($low ? 0 : KEEP_MSG_DAYS * 86400));

    // pesan non-tangkapan: aman dihapus
    $s = $pdo->prepare("DELETE FROM discord_messages WHERE msg_time < ? AND embeds = '[]'");
    $s->execute([$cut]);
    // tangkapan ikan: hanya yang sebelum ronde aktif (sebelum 'start'), karena sudah tidak dihitung
    $s = $pdo->prepare('DELETE FROM discord_messages WHERE msg_time < ? AND msg_time < ?');
    $s->execute([$cut, $start]);

    // webhook_logs (diabaikan bila tabel tidak ada)
    try {
        $days = $low ? 1 : KEEP_WH_DAYS;
        $s = $pdo->prepare("DELETE FROM webhook_logs WHERE waktu < (NOW() - INTERVAL $days DAY)");
        $s->execute();
    } catch (Throwable $e) {}

    // rotasi file backup
    $files = glob(__DIR__ . '/backup/klasemen_*.json') ?: []; rsort($files);
    foreach (array_slice($files, $low ? BACKUP_KEEP_LOW : BACKUP_KEEP) as $f) @unlink($f);

    // log Apache: kosongkan bila terlalu besar (best effort)
    foreach ([dirname(PHP_BINARY) . '/../apache/logs', dirname(PHP_BINARY) . '/../logs'] as $d)
        foreach (['access.log', 'error.log'] as $l) {
            $p = "$d/$l";
            if (is_file($p) && filesize($p) > MAX_LOG_MB * 1048576) @file_put_contents($p, '');
        }
}

// Aturan poin ikan khusus (tabel klasemen_aturan): poin aturan MENGGANTIKAN poin tier, lalu poin Shiny/Big/Mutasi tetap ditambahkan
function aturan_list(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query('SELECT id, ikan, min_berat, poin FROM klasemen_aturan ORDER BY id')->fetchAll() as $r)
        $out[] = ['id' => (int)$r['id'], 'ikan' => mb_strtolower($r['ikan']),
                  'min_berat' => (float)$r['min_berat'], 'poin' => (int)$r['poin']];
    return $out;
}

// Ambil angka berat dari teks seperti "123.4 kg" / "1,234.5kg"
function parse_berat(string $s): float {
    $s = preg_replace('/[*_`~|]/u', '', $s);
    if (!preg_match('/(\d[\d.,]*)/', $s, $m)) return 0.0;
    $n = rtrim($m[1], '.,');
    // "165,87" = desimal koma; "1,234.5" = koma ribuan
    return (float)(preg_match('/^\d+,\d{1,2}$/', $n) ? str_replace(',', '.', $n) : str_replace(',', '', $n));
}

function parse_embed(array $e, array $tiers, array $aturan = [], array $on = []): ?array {
    $d = $e['description'] ?? '';
    if (!preg_match('/\|\|\s*(.+?)\s*\|\|/u', $d, $m)) return null;   // ||DisplayName||
    $tier = ''; $fish = ''; $var = [];
    foreach ($e['fields'] ?? [] as $f) {
        $n = strtolower($f['name'] ?? ''); $v = clean($f['value'] ?? '');
        if (str_contains($n, 'tier')) $tier = $v;
        elseif (str_contains($n, 'fish name')) $fish = trim(preg_replace('/[*_`~|]/u', '', $f['value']));
        elseif (preg_match('/variant|mutat|mutasi/', $n)) foreach (preg_split('/\s*[,\/+]\s*/', $v) as $x) if ($x !== '') $var[] = $x;
    }
    if ($tier === '' && preg_match('/new\s+[*_]*(\w+)[*_]*\s+fish/i', $d, $t)) $tier = strtolower($t[1]);

    // Override rarity per ikan (mis. Panther Eel dari Mythic -> Secret)
    $fn0 = mb_strtolower(preg_replace('/\s+/', ' ', $fish));
    foreach (TIER_OVERRIDE as $nm => $tr) if (str_contains($fn0, $nm)) { $tier = $tr; break; }

    // berat ikan: dari field "weight/berat", atau field apa pun yang berisi angka + kg
    $w = 0.0;
    foreach ($e['fields'] ?? [] as $f) {
        $fn = strtolower($f['name'] ?? ''); $fv = (string)($f['value'] ?? '');
        if (preg_match('/weight|berat/', $fn) || ($w <= 0 && preg_match('/\d\s*kg\b/i', $fv))) $w = parse_berat($fv);
    }
    $pts = 0;
    $matched = null;
    foreach ($tiers as $k => $v) if (str_contains($tier, $k)) { $pts = $v; $matched = $k; break; }
    if ($matched !== null && ($on[$matched] ?? true) === false) return null;   // rarity tidak dipilih -> tidak dihitung
    $tierPts = $pts;   // poin tier murni, sebelum varian ditambahkan

    $tags = [];
    $hasShiny = false;

    // Varian bisa berupa teks gabungan, mis. "Shiny frozen".
    // Shiny = +1, Big = +1, dan sisa teks = nama mutasi (+1 sendiri). Semuanya terpisah.
    foreach ($var as $x) {
        if (in_array($x, ['-', 'none', 'normal', 'n/a', 'no'], true)) continue;

        $rest = $x;

        // Shiny = +1 (bukan mutasi)
        if (preg_match('/\bshiny\b/i', $rest)) {
            $pts += VARIAN['shiny'];
            $tags[] = 'Shiny';
            $hasShiny = true;
            $rest = trim(preg_replace('/\bshiny\b/i', '', $rest));
        }

        // Big = +1 (bukan mutasi)
        if (preg_match('/\bbig\b/i', $rest)) {
            $pts += VARIAN['big'];
            $tags[] = 'Big';
            $rest = trim(preg_replace('/\bbig\b/i', '', $rest));
        }

        // Sisa teks = nama mutasi, +1 sendiri
        if ($rest !== '') {
            $pts += VARIAN['mutation'];
            $tags[] = ucfirst($rest);
        }
    }

    // Deteksi Shiny dari seluruh teks embed,
    // termasuk title, description, dan nama ikan.
    $allText = ($e['title'] ?? '') . ' ' . ($e['description'] ?? '');

    foreach ($e['fields'] ?? [] as $f) {
        $allText .= ' ' . ($f['name'] ?? '') . ' ' . ($f['value'] ?? '');
    }

    // Jika Shiny belum dihitung dari field variant,
    // tambahkan 1 poin apabila kata Shiny ditemukan.
    if (!$hasShiny && preg_match('/\bshiny\b/i', $allText)) {
        $pts += VARIAN['shiny'];
        $tags[] = 'Shiny';
        $hasShiny = true;
    }

    // Awalan di depan nama ikan, urutan bebas: "Big Shiny X", "Shiny Big X", "Big X", "Shiny X".
    // Big = +1 dan Shiny = +1, dihitung terpisah (hanya bila belum terhitung dari field variant).
    while (preg_match('/^(big|shiny)\s+(.+)/i', $fish, $bm)) {
        if (strtolower($bm[1]) === 'big') {
            if (!array_filter($tags, fn($t) => stripos($t, 'big') !== false)) { $pts += VARIAN['big']; $tags[] = 'Big'; }
        } elseif (!$hasShiny) {
            $pts += VARIAN['shiny']; $tags[] = 'Shiny'; $hasShiny = true;
        }
        $fish = $bm[2];
    }
    // Aturan ikan khusus: cocok nama ikan (sebagian kata) dan berat minimal. Bila beberapa cocok, poin terbesar dipakai.
    $fl = mb_strtolower($fish); $hit = null;
    foreach ($aturan as $r) {
        if ($r['ikan'] === '' || !str_contains($fl, $r['ikan'])) continue;
        if ($r['min_berat'] > 0 && $w < $r['min_berat']) continue;
        if ($hit === null || $r['poin'] > $hit['poin']) $hit = $r;
    }
    if ($hit !== null) {
        $vp = $pts - $tierPts;   // poin Shiny / Big / Mutasi yang sudah terhitung
        // Frostbon 100 kg+ : Big tidak dihitung
        if ($w >= NO_BIG_MIN && str_contains($fl, NO_BIG_FISH))
            $vp -= count(array_filter($tags, fn($t) => stripos($t, 'big') !== false)) * VARIAN['big'];
        $pts = $hit['poin'] + max(0, $vp);   // poin aturan (menggantikan tier) + varian
    }
    return $pts > 0 ? ['name' => trim($m[1]), 'fish' => $fish, 'tier' => ucfirst($tier), 'var' => implode(', ', $tags), 'pts' => $pts,
        'rule' => $hit !== null,
        'img' => $e['thumbnail']['url'] ?? $e['image']['url'] ?? null] : null;
}

// Embed "Lynx Webhook | Player Joined": kembalikan daftar nama (username & display name) yang sudah join server
function parse_join(array $e): array {
    $d = $e['description'] ?? '';
    $head = ($e['title'] ?? '') . ' ' . ($e['author']['name'] ?? '') . ' ' . ($e['footer']['text'] ?? '');
    if (!preg_match('/player\s+joined/i', $head) && !preg_match('/has\s+joined\s+the\s+server/i', $d)) return [];
    $names = [];
    if (preg_match('/\|\|\s*(.+?)\s*\|\|/u', $d, $m)) $names[] = trim($m[1]);
    foreach ($e['fields'] ?? [] as $f) {
        if (!str_contains(strtolower($f['name'] ?? ''), 'display name')) continue;
        $v = trim(preg_replace('/[*_`~|]/u', '', $f['value'] ?? ''));
        if ($v !== '') $names[] = $v;
    }
    return array_values(array_unique(array_filter($names, fn($n) => $n !== '')));
}

// Embed "Lynx Webhook | Player Left": kembalikan daftar nama (username & display name) yang keluar dari server
function parse_left(array $e): array {
    $d = $e['description'] ?? '';
    $head = ($e['title'] ?? '') . ' ' . ($e['author']['name'] ?? '') . ' ' . ($e['footer']['text'] ?? '');
    if (!preg_match('/player\s+left/i', $head) && !preg_match('/has\s+left\s+the\s+server/i', $d)) return [];
    $names = [];
    if (preg_match('/\|\|\s*(.+?)\s*\|\|/u', $d, $m)) $names[] = trim($m[1]);
    foreach ($e['fields'] ?? [] as $f) {
        if (!str_contains(strtolower($f['name'] ?? ''), 'display name')) continue;
        $v = trim(preg_replace('/[*_`~|]/u', '', $f['value'] ?? ''));
        if ($v !== '') $names[] = $v;
    }
    return array_values(array_unique(array_filter($names, fn($n) => $n !== '')));
}

// Sinkron Discord dengan kunci file (tidak tumpang tindih dengan jalur ?sync=1). $wait = true -> tunggu giliran
function sync_locked(bool $wait = false): ?array {
    $lf = @fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dc_sync.lock', 'c');
    if (!$lf) return null;
    if (!flock($lf, $wait ? LOCK_EX : (LOCK_EX | LOCK_NB))) { fclose($lf); return ['busy' => true]; }
    try { return sync_discord(); }
    catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
    finally { flock($lf, LOCK_UN); fclose($lf); }
}

// Foto data lama: tangkapan yang SUDAH ada di database saat timer dimulai/dilanjutkan ditandai "lama" dan tidak pernah dihitung.
// $iv = interval timer sebelumnya [[mulai, selesai], ...]. Tangkapan yang masuk SAAT timer berjalan (di dalam interval)
// sudah dihitung, jadi TIDAK ditandai lama. Dengan begitu poin tidak reset saat pause -> lanjut.
function lama_key(array $r): string { return md5($r['msg_time'] . '|' . $r['embeds']); }
function snapshot_lama(PDO $pdo, array $iv = []): void {
    $ks = [];
    foreach ($pdo->query("SELECT msg_time, embeds FROM discord_messages WHERE embeds <> '[]'") as $r) {
        $te = strtotime($r['msg_time'] . ' UTC');
        foreach ($iv as [$s0, $e0, $c0])
            if ($te >= $s0 && $te <= ($c0 ?? PHP_INT_MAX)) continue 2;   // masuk saat timer jalan (sampai batas hitung beku) -> tetap dihitung
        $ks[] = lama_key($r);
    }
    foreach (array_chunk($ks, 200) as $ch)
        $pdo->prepare('INSERT IGNORE INTO klasemen_lama (k) VALUES ' . implode(',', array_fill(0, count($ch), '(?)')))->execute($ch);
}

// Cek username Roblox (untuk kotak preview di popup Tambah Peserta)
if (isset($_GET['cek'])) {
    $usn = trim($_GET['cek']); $u = $usn !== '' ? roblox_user($usn) : null;
    $img = $u ? (http_json("https://thumbnails.roblox.com/v1/users/avatar-headshot?userIds={$u['id']}&size=150x150&format=Png")['data'][0] ?? []) : [];
    echo json_encode(['found' => (bool)$u, 'name' => $u['name'] ?? '', 'display' => $u['displayName'] ?? '',
        'avatar' => ($img['state'] ?? '') === 'Completed' ? ($img['imageUrl'] ?? null) : null]);
    exit;
}

// ===== Status gateway.js (aktif / mati) =====
// gateway.js mengirim detak ke ?gw=ping tiap 5 detik (dan ?gw=ping&off=1 saat dimatikan). Web membaca ?gw=1.
// Disimpan di file temp (tanpa database) supaya ringan. Dianggap mati bila tidak ada detak > 20 detik.
if (isset($_GET['gw'])) {
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
    $f  = $tmp . 'gateway_hb.txt';     // detak status
    $vf = $tmp . 'gateway_ver.txt';    // penanda "ada data baru" (tanpa database, jadi sangat cepat)
    header('Cache-Control: no-store');
    $mode = $_GET['gw'];
    if ($mode === 'ping') {
        @file_put_contents($f, isset($_GET['off']) ? '0' : (string)time());
        echo json_encode(['ok' => true]);
    } elseif ($mode === 'new') {       // dipanggil gateway.js segera setelah pesan tersimpan
        @file_put_contents($vf, sprintf('%.6f', microtime(true)), LOCK_EX);
        @file_put_contents($f, (string)time());
        echo json_encode(['ok' => true]);
    } elseif ($mode === 'wait') {      // long-poll: web menunggu sampai ada data baru (maks 25 dtk), balas seketika bila ada
        set_time_limit(40);
        $since = (string)($_GET['v'] ?? ''); $end = microtime(true) + 25; $cur = '0';
        do {
            clearstatcache(true, $vf);
            $c = is_file($vf) ? trim((string)@file_get_contents($vf)) : '0';
            if ($c !== '') $cur = $c;
            if ($cur !== $since) break;
            usleep(10000);
        } while (microtime(true) < $end);
        echo json_encode(['v' => $cur, 'changed' => $cur !== $since]);
    } else {
        $t = is_file($f) ? (int)@file_get_contents($f) : 0;
        $age = $t > 0 ? time() - $t : null;
        echo json_encode(['up' => $age !== null && $age <= 20, 'age' => $age]);
    }
    exit;
}

try {
    $pdo = db();
    ensure_discord_table();

    // ===== Webhook langsung (?hook=KUNCI): terima payload gaya Discord, simpan seketika tanpa lewat Discord =====
    if (isset($_GET['hook'])) {
        if (HOOK_KEY === '' || !hash_equals(HOOK_KEY, (string)$_GET['hook'])) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($in) && isset($_POST['payload_json'])) $in = json_decode((string)$_POST['payload_json'], true);
        if (!is_array($in)) { http_response_code(400); echo json_encode(['error' => 'payload tidak valid']); exit; }
        $embeds = array_values(array_filter(is_array($in['embeds'] ?? null) ? $in['embeds'] : [], 'is_array'));
        $id = (string)((int)(microtime(true) * 1000) * 1000 + random_int(0, 999));   // angka 16 digit, tidak bentrok dengan ID Discord
        $pdo->prepare('INSERT INTO discord_messages (message_id, channel_id, author_id, author_name, author_avatar, is_bot, content, embeds, attachments, msg_time)
            VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id, '0', 'hook', mb_substr((string)($in['username'] ?? 'webhook'), 0, 100),
            (string)($in['avatar_url'] ?? ''), 1, (string)($in['content'] ?? ''),
            json_encode($embeds, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), '[]', gmdate('Y-m-d H:i:s')]);
        @file_put_contents(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gateway_ver.txt', sprintf('%.6f', microtime(true)), LOCK_EX);   // bangunkan long-poll web
        if (isset($_GET['wait'])) echo json_encode(['id' => $id]); else http_response_code(204);
        exit;
    }

    // ===== Jalur sinkron Discord (?sync=1): hanya menarik pesan baru, tanpa membaca poin =====
    // Dipisah dari request data agar data poin tidak menunggu panggilan ke Discord. Kunci file mencegah sinkron tumpang tindih.
    if (isset($_GET['sync'])) {
        $lf = @fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dc_sync.lock', 'c');
        if ($lf && flock($lf, LOCK_EX | LOCK_NB)) {
            $flag = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dc_last_sync.txt';
            $out = ['sync' => null];
            if (microtime(true) - (is_file($flag) ? (float)file_get_contents($flag) : 0) >= SYNC_EVERY) {
                try { $out['sync'] = sync_discord(); } catch (Throwable $e) { $out['sync'] = ['ok' => false, 'error' => $e->getMessage()]; }
                file_put_contents($flag, (string)microtime(true));
            }
            flock($lf, LOCK_UN); fclose($lf);
            echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } else echo json_encode(['sync' => null, 'busy' => true]);
        exit;
    }

    // ===== Skema tabel: dijalankan SEKALI saja (bukan tiap request) agar tidak delay =====
    // Bila tabel diubah/dihapus, hapus file klasemen_schema_ok.txt di folder temp agar skema dibuat ulang.
    $schemaFlag = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'klasemen_schema_ok.txt';
    if (!is_file($schemaFlag)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS klasemen_peserta (
            slot TINYINT PRIMARY KEY, nama VARCHAR(100) NOT NULL DEFAULT '', display_name VARCHAR(100) NOT NULL DEFAULT '',
            pay TINYINT NOT NULL DEFAULT 0, absen TINYINT NOT NULL DEFAULT 0, bonus INT NOT NULL DEFAULT 0,
            paytxt VARCHAR(40) NOT NULL DEFAULT '', paybin INT NOT NULL DEFAULT 0, usn VARCHAR(100) NOT NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pc = $pdo->query("SHOW COLUMNS FROM klasemen_peserta LIKE 'paytxt'")->fetch();
        if (!$pc) $pdo->exec("ALTER TABLE klasemen_peserta ADD COLUMN paytxt VARCHAR(40) NOT NULL DEFAULT '', ADD COLUMN paybin INT NOT NULL DEFAULT 0");
        elseif (stripos($pc['Type'], 'varchar(3)') !== false) $pdo->exec("ALTER TABLE klasemen_peserta MODIFY paytxt VARCHAR(40) NOT NULL DEFAULT ''");
        // tabel lama: tambah kolom usn (username Roblox) bila belum ada
        if (!$pdo->query("SHOW COLUMNS FROM klasemen_peserta LIKE 'usn'")->fetch())
            $pdo->exec("ALTER TABLE klasemen_peserta ADD COLUMN usn VARCHAR(100) NOT NULL DEFAULT ''");
        $pdo->exec("CREATE TABLE IF NOT EXISTS klasemen_meta (k VARCHAR(20) PRIMARY KEY, v VARCHAR(40) NOT NULL) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS klasemen_timer (id INT AUTO_INCREMENT PRIMARY KEY, t_start DATETIME NOT NULL, t_end DATETIME NULL) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS klasemen_avatar (kunci VARCHAR(100) PRIMARY KEY, user_id BIGINT NULL, url VARCHAR(255) NULL, ts INT NOT NULL,
            st VARCHAR(10) NULL, rd VARCHAR(100) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // tabel lama: tambah kolom st & rd bila belum ada
        if (!$pdo->query("SHOW COLUMNS FROM klasemen_avatar LIKE 'st'")->fetch())
            $pdo->exec("ALTER TABLE klasemen_avatar ADD COLUMN st VARCHAR(10) NULL, ADD COLUMN rd VARCHAR(100) NULL");
        // Riwayat pemain (permanen): username, nama, display name, avatar, jumlah main
        $pdo->exec("CREATE TABLE IF NOT EXISTS klasemen_riwayat (
            usn VARCHAR(100) PRIMARY KEY, nama VARCHAR(100) NOT NULL DEFAULT '', display_name VARCHAR(100) NOT NULL DEFAULT '',
            user_id BIGINT NULL, avatar VARCHAR(255) NULL, first_seen DATETIME NOT NULL, last_played DATETIME NOT NULL, plays INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec('INSERT IGNORE INTO klasemen_peserta (slot) VALUES ' . implode(',', array_map(fn($i) => "($i)", range(1, 20))));

        // Sesi: judul per sesi + hasil akhir (disimpan saat sesi diselesaikan)
        $pdo->exec("CREATE TABLE IF NOT EXISTS klasemen_sesi (
            id INT AUTO_INCREMENT PRIMARY KEY, judul VARCHAR(100) NOT NULL DEFAULT '', mulai DATETIME NOT NULL, selesai DATETIME NULL, hasil MEDIUMTEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS klasemen_aturan (
            id INT AUTO_INCREMENT PRIMARY KEY, ikan VARCHAR(100) NOT NULL DEFAULT '', varian VARCHAR(100) NOT NULL DEFAULT '',
            min_berat DOUBLE NOT NULL DEFAULT 0, poin INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS klasemen_lama (k CHAR(32) PRIMARY KEY) ENGINE=InnoDB");
        @file_put_contents($schemaFlag, '1');
    }
    sesi_now($pdo);
// Perbaiki penanda "lama": hitung ulang dari interval timer saat ini
if (isset($_GET['repair'])) {
    $pdo->exec('DELETE FROM klasemen_lama');
    snapshot_lama($pdo, timer_state($pdo)['iv']);
    echo json_encode(['ok' => true]);
    exit;
}

    // ===== Data masuk + latensi (ms) dari gateway.js. ?latensi=1 =====
    if (isset($_GET['latensi']) || isset($_GET['tes'])) {
        $lf0 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'klasemen_lat_ok.txt';
        if (!is_file($lf0)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS gateway_latency (
                message_id VARCHAR(25) NOT NULL PRIMARY KEY, ms INT NOT NULL DEFAULT 0, ms_db INT NOT NULL DEFAULT 0,
                info VARCHAR(200) NOT NULL DEFAULT '', is_test TINYINT(1) NOT NULL DEFAULT 0,
                t DATETIME(3) NOT NULL, INDEX idx_t (t)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            try { $pdo->exec('ALTER TABLE gateway_latency ADD COLUMN IF NOT EXISTS ms_db INT NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
            @file_put_contents($lf0, '1');
        }
    }
    if (isset($_GET['latensi'])) {
        // ms valid = ms total; bila negatif (jam PC tertinggal dari Discord) pakai ms_db (waktu simpan ke database)
        $rows = $pdo->query('SELECT message_id, ms, ms_db, info, is_test, t FROM gateway_latency ORDER BY t DESC LIMIT 40')->fetchAll();
        $stt = $pdo->query('SELECT COUNT(*) n, AVG(v) a, MIN(v) lo, MAX(v) hi, SUM(sk) sk FROM
            (SELECT IF(ms < 0, ms_db, ms) v, (ms < 0) sk FROM gateway_latency WHERE is_test = 0 ORDER BY t DESC LIMIT 50) x')->fetch();
        echo json_encode(['rows' => $rows, 'stat' => ['n' => (int)$stt['n'], 'a' => (float)$stt['a'],
            'lo' => (int)$stt['lo'], 'hi' => (int)$stt['hi'], 'skew' => (int)$stt['sk']]], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    // ===== Tes: kirim 1 pesan webhook palsu, ukur sampai masuk database. ?tes=1 =====
    if (isset($_GET['tes'])) {
        set_time_limit(25);
        if (!defined('DISCORD_WEBHOOK_URL') || DISCORD_WEBHOOK_URL === '') {
            echo json_encode(['ok' => false, 'error' => 'DISCORD_WEBHOOK_URL kosong di config.php']); exit;
        }
        $nm = 'TES_GATEWAY_' . substr(md5((string)microtime(true)), 0, 5);
        $body = ['username' => 'Tes Gateway', 'embeds' => [[
            'title' => 'Lynx Webhook | Fish Caught', 'description' => "New **Secret** fish caught by ||$nm||",
            'fields' => [['name' => 'Tier', 'value' => 'Secret'], ['name' => 'Fish Name', 'value' => 'Tes Fish'], ['name' => 'Weight', 'value' => '10.5 kg']]]]];
        $t0 = microtime(true);
        $ch = curl_init(DISCORD_WEBHOOK_URL . '?wait=true');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 10]);
        $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $msg = json_decode((string)$res, true); $t1 = microtime(true);
        if ($code !== 200 || empty($msg['id'])) { echo json_encode(['ok' => false, 'error' => "Webhook gagal (HTTP $code)"]); exit; }
        $id = $msg['id']; $ms = null;
        $q = $pdo->prepare('SELECT IF(ms < 0, ms_db, ms) FROM gateway_latency WHERE message_id = ?');
        while (microtime(true) - $t0 < 15) {            // tunggu catatan latensi dari gateway.js
            $q->execute([$id]); $v = $q->fetchColumn();
            if ($v !== false) { $ms = (int)$v; break; }
            usleep(30000);
        }
        // bersihkan pesan tes (baris latensi tetap ada, ditandai TES)
        $pdo->prepare('DELETE FROM discord_messages WHERE message_id = ?')->execute([$id]);
        $ch = curl_init(DISCORD_WEBHOOK_URL . '/messages/' . $id);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 5]);
        curl_exec($ch); curl_close($ch);
        echo json_encode($ms === null
            ? ['ok' => false, 'error' => 'Tidak masuk dalam 15 detik. Cek apakah gateway.js menyala.']
            : ['ok' => true, 'ms' => $ms, 'kirim_ms' => (int)(($t1 - $t0) * 1000)]);
        exit;
    }

    // Daftar sesi yang sudah selesai (30 terakhir) beserta hasil akhirnya
    if (isset($_GET['sesi_list'])) {
        $rows = $pdo->query('SELECT id, judul, mulai, selesai, hasil FROM klasemen_sesi WHERE selesai IS NOT NULL ORDER BY id DESC LIMIT 30')->fetchAll();
        echo json_encode(['list' => array_map(fn($r) => ['id' => (int)$r['id'], 'judul' => $r['judul'], 'mulai' => $r['mulai'],
            'selesai' => $r['selesai'], 'hasil' => json_decode($r['hasil'] ?? '[]', true) ?: []], $rows)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Cari riwayat pemain (kosong = 12 pemain terakhir main)
    if (isset($_GET['riwayat'])) {
        $q = trim($_GET['riwayat']);
        if ($q === '') $s = $pdo->query('SELECT * FROM klasemen_riwayat ORDER BY last_played DESC LIMIT 12');
        else {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $s = $pdo->prepare('SELECT * FROM klasemen_riwayat WHERE usn LIKE ? OR nama LIKE ? OR display_name LIKE ? ORDER BY last_played DESC LIMIT 12');
            $s->execute([$like, $like, $like]);
        }
        echo json_encode(['list' => array_map(fn($r) => ['usn' => $r['usn'], 'nama' => $r['nama'], 'display' => $r['display_name'],
            'avatar' => $r['avatar'], 'plays' => (int)$r['plays'], 'last' => $r['last_played']], $s->fetchAll())], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $err = null; $needUsn = null; $needKand = []; $sync = null; $isGet = ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST';
    $slow = $isGet && isset($_GET['av']);   // request "lambat": avatar Roblox, backup, pembersihan (dipanggil terpisah dari polling cepat)

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $slot = (int)($in['slot'] ?? 0); $a = $in['action'] ?? ''; $f = $in['field'] ?? '';
        if ($a === 'save' && in_array($f, ['nama', 'display_name'], true)) {
            $val = mb_substr(trim($in['value'] ?? ''), 0, 100);
            $pdo->prepare("UPDATE klasemen_peserta SET $f = ? WHERE slot = ?")->execute([$val, $slot]);
        } elseif ($a === 'save' && $f === 'pay') {   // Pay: teks bebas (tampil 3 depan); "bin" = tambah poin sesuai +POINT (sekali)
            $val = mb_substr(trim($in['value'] ?? ''), 0, 40);
            $q = $pdo->prepare('SELECT paytxt, paybin FROM klasemen_peserta WHERE slot = ?'); $q->execute([$slot]);
            $cur = $q->fetch() ?: ['paytxt' => '', 'paybin' => 0];
            $was = mb_strtolower($cur['paytxt']) === 'bin'; $is = mb_strtolower($val) === 'bin';
            $add = 0; $nb = (int)$cur['paybin'];
            if ($is && !$was) { $nb = abs((int)($in['pt'] ?? 0)); $add = $nb; }
            elseif (!$is && $was) { $add = -$nb; $nb = 0; }
            $pdo->prepare('UPDATE klasemen_peserta SET paytxt = ?, paybin = ?, bonus = bonus + ?, pay = IF(? = \'\', 0, pay) WHERE slot = ?')->execute([$val, $nb, $add, $val, $slot]);
        } elseif ($a === 'toggle' && in_array($f, ['pay', 'absen'], true)) {
            $pdo->prepare("UPDATE klasemen_peserta SET $f = 1 - $f WHERE slot = ?")->execute([$slot]);
        } elseif ($a === 'bonus') {
            $pdo->prepare('UPDATE klasemen_peserta SET bonus = bonus + ? WHERE slot = ?')->execute([(int)($in['delta'] ?? 0), $slot]);
        } elseif ($a === 'add') {   // tambah peserta: nama (bebas) + username Roblox -> display name & avatar dari Roblox, disimpan ke riwayat
            $nama = mb_substr(trim($in['nama'] ?? ''), 0, 100);
            $usn  = mb_substr(trim($in['usn'] ?? ''), 0, 100);
            // Tombol Add ditekan di baris tertentu: pakai baris itu walau Nama / Pay sudah terisi (asal belum punya username & display name)
            $free = false;
            if ($slot >= 1 && $slot <= 20) {
                $c = $pdo->prepare("SELECT slot FROM klasemen_peserta WHERE slot = ? AND usn = '' AND display_name = ''");
                $c->execute([$slot]); $free = $c->fetchColumn();
            }
            if (!$free) $free = $pdo->query("SELECT slot FROM klasemen_peserta WHERE nama = '' AND display_name = '' ORDER BY slot LIMIT 1")->fetchColumn();
            if ($usn === '') $err = 'Isi username Roblox dulu.';
            elseif (!$free) $err = 'Semua slot sudah terisi.';
            else {
                $u  = roblox_user($usn);
                $rn = $u['name'] ?? $usn; $rd = $u['displayName'] ?? $usn;
                $q = $pdo->prepare("SELECT COUNT(*) FROM klasemen_peserta WHERE slot <> ? AND (LOWER(usn) = LOWER(?) OR (usn = '' AND LOWER(nama) = LOWER(?)))");
                $q->execute([$free, $rn, $rn]);
                if ((int)$q->fetchColumn() > 0) $err = "Peserta @$rn sudah ada di daftar.";
                else {
                    if (!$u) $err = 'Username tidak ditemukan di Roblox, ditambahkan apa adanya.';
                    // Nama kosong di form -> pertahankan Nama yang sudah ada di baris; Pay tidak disentuh
                    $pdo->prepare('UPDATE klasemen_peserta SET nama = IF(? <> \'\', ?, nama), usn = ?, display_name = ? WHERE slot = ?')->execute([$nama, $nama, $rn, $rd, $free]);
                    $q2 = $pdo->prepare('SELECT nama FROM klasemen_peserta WHERE slot = ?'); $q2->execute([$free]); $nama = (string)$q2->fetchColumn();
                    if ($u) {   // username valid: ambil avatar sekarang, simpan ke cache & riwayat
                        [$uid, $url, $stt, $rdd] = roblox_avatar($rn, $rd);
                        $ck = md5(mb_strtolower($nama) . '|' . mb_strtolower($rd) . '|' . mb_strtolower($rn));
                        $pdo->prepare('REPLACE INTO klasemen_avatar (kunci, user_id, url, ts, st, rd) VALUES (?,?,?,?,?,?)')
                            ->execute([$ck, $uid, $url, time() - ($uid && !$url ? 570 : 0), $stt, $rdd]);
                        riwayat_save($pdo, $rn, $nama, $rd, $uid, $url, 1);
                    }
                }
            }
        } elseif ($a === 'add_display') {   // dari "Belum terdaftar": simpan USERNAME Roblox, tampil sebagai DISPLAY NAME
            $dn = mb_substr(trim($in['display'] ?? ''), 0, 100);
            $un = mb_substr(trim($in['usn'] ?? ''), 0, 100);   // username Roblox (diisi manual bila pencarian otomatis gagal)
            $free = $pdo->query("SELECT slot FROM klasemen_peserta WHERE nama = '' AND display_name = '' AND usn = '' ORDER BY slot LIMIT 1")->fetchColumn();
            if ($dn === '') $err = 'Nama kosong.';
            elseif (!$free) $err = 'Semua slot sudah terisi.';
            else {
                $q = $pdo->prepare('SELECT COUNT(*) FROM klasemen_peserta WHERE LOWER(display_name) = LOWER(?) OR LOWER(nama) = LOWER(?) OR LOWER(usn) = LOWER(?)');
                $q->execute([$dn, $dn, $dn]);
                if ((int)$q->fetchColumn() > 0) $err = "$dn sudah ada di daftar.";
                else {
                    $kand = [];
                    if ($un === '') {
                        // 1) Otomatis dari riwayat pemain: display name yang pernah dipasangkan dengan username oleh operator
                        $rq = $pdo->prepare('SELECT usn FROM klasemen_riwayat WHERE LOWER(display_name) = LOWER(?) ORDER BY last_played DESC LIMIT 5');
                        $rq->execute([$dn]); $kand = array_map(fn($x) => ['name' => $x], array_values(array_unique($rq->fetchAll(PDO::FETCH_COLUMN))));
                        if (count($kand) === 1) { $un = $kand[0]['name']; $kand = []; }
                        elseif (!$kand) {   // 2) Tidak ada di riwayat: kumpulkan kandidat dari Roblox (display name tidak unik -> tidak dipilih otomatis)
                            $sr = http_json('https://users.roblox.com/v1/users/search?limit=10&keyword=' . rawurlencode($dn));
                            $seen = [];
                            foreach (($sr['data'] ?? []) as $x)
                                if (strcasecmp($x['displayName'] ?? '', $dn) === 0 && !isset($seen[$x['id']]) && count($kand) < 6) { $seen[$x['id']] = 1; $kand[] = ['name' => $x['name'], 'id' => $x['id']]; }
                            // foto profil kandidat (membantu memastikan akun yang benar)
                            if ($kand) {
                                $th = http_json('https://thumbnails.roblox.com/v1/users/avatar-headshot?size=48x48&format=Png&userIds=' . implode(',', array_column($kand, 'id')));
                                $im = []; foreach (($th['data'] ?? []) as $t) if (($t['state'] ?? '') === 'Completed') $im[$t['targetId']] = $t['imageUrl'];
                                foreach ($kand as &$kk) $kk['avatar'] = $im[$kk['id']] ?? null; unset($kk);
                            }
                        }
                    }
                    $u = $un !== '' ? roblox_user($un) : null;
                    if ($un !== '' && !$u) $err = "Username \"$un\" tidak ditemukan di Roblox.";
                    elseif ($u) {
                        $rn = $u['name'];   // username
                        $pdo->prepare('UPDATE klasemen_peserta SET nama = \'\', usn = ?, display_name = ? WHERE slot = ?')->execute([$rn, $dn, $free]);
                        $t = http_json("https://thumbnails.roblox.com/v1/users/avatar-headshot?userIds={$u['id']}&size=150x150&format=Png");
                        $img = $t['data'][0] ?? [];
                        $url = ($img['state'] ?? '') === 'Completed' ? ($img['imageUrl'] ?? null) : null;
                        $ck = md5('|' . mb_strtolower($dn) . '|' . mb_strtolower($rn));
                        $pdo->prepare('REPLACE INTO klasemen_avatar (kunci, user_id, url, ts, st, rd) VALUES (?,?,?,?,?,?)')
                            ->execute([$ck, $u['id'], $url, time() - ($url ? 0 : 570), 'ok', $dn]);
                        riwayat_save($pdo, $rn, '', $dn, $u['id'], $url, 1);
                    } else {
                        $needUsn = $dn; $needKand = $kand;   // belum ada username: minta dari web (tidak menebak dari display name)
                    }
                }
            }
        } elseif ($a === 'clear') {   // kosongkan slot agar bisa dipakai peserta lain (riwayat pemain tetap tersimpan)
            try { backup_db($pdo); } catch (Throwable $e) {}   // backup sebelum data dihapus
            $pdo->prepare("UPDATE klasemen_peserta SET nama = '', usn = '', display_name = '', pay = 0, paytxt = '', paybin = 0, absen = 0, bonus = 0 WHERE slot = ?")->execute([$slot]);
        } elseif ($a === 'timer') {   // play / pause
            $ts0 = timer_state($pdo);
            if (!empty($in['run']) && !$ts0['running'] && $ts0['remaining'] > 0) {
                sync_locked(true);                    // tarik pesan Discord terbaru dulu...
                snapshot_lama($pdo, $ts0['iv']);      // ...lalu tandai sebagai data lama, KECUALI yang masuk saat timer berjalan sebelumnya
                $pdo->prepare('INSERT INTO klasemen_timer (t_start) VALUES (?)')->execute([gmdate('Y-m-d H:i:s')]);
            } elseif (empty($in['run']) && $ts0['running']) {
                $n0 = time(); $o = end($ts0['iv']);
                $cut0 = bekukan_cut($pdo, (int)$o[0], $n0);   // tangkapan yang sudah tampil sebelum jeda tetap dihitung
                $pdo->prepare('UPDATE klasemen_timer SET t_end = ?, t_cut = ? WHERE t_end IS NULL')->execute([gmdate('Y-m-d H:i:s', $n0), gmdate('Y-m-d H:i:s', $cut0)]);
            }
        } elseif ($a === 'set_duration') {   // atur durasi ronde (menit), tersimpan di database
            $m = max(1, min(600, (int)($in['min'] ?? 0)));
            $pdo->prepare("REPLACE INTO klasemen_meta (k, v) VALUES ('durasi', ?)")->execute([$m * 60]);
        } elseif ($a === 'wipe_db') {   // kosongkan HANYA discord_messages (peserta, username, avatar, riwayat, timer tetap aman)
            try { $pdo->exec('TRUNCATE TABLE discord_messages'); } catch (Throwable $e) { $pdo->exec('DELETE FROM discord_messages'); }
            $pdo->exec('DELETE FROM klasemen_lama');
            $pdo->prepare("REPLACE INTO klasemen_meta (k, v) VALUES ('start', ?)")->execute([gmdate('Y-m-d H:i:s')]);   // pesan lama yang masuk lagi tidak dihitung
        } elseif ($a === 'aturan_add') {   // tambah aturan poin ikan khusus
            $ik = mb_strtolower(mb_substr(trim($in['ikan'] ?? ''), 0, 100));
            $mb = max(0, min(100000, (float)($in['min_berat'] ?? 0)));
            $pn = max(0, min(999, (int)($in['poin'] ?? 0)));
            if ($ik === '') $err = 'Isi nama ikan dulu.';
            else $pdo->prepare('INSERT INTO klasemen_aturan (ikan, min_berat, poin) VALUES (?,?,?)')->execute([$ik, $mb, $pn]);
        } elseif ($a === 'aturan_del') {
            $pdo->prepare('DELETE FROM klasemen_aturan WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
        } elseif ($a === 'set_judul') {   // ubah judul sesi yang sedang berjalan
            $sn = sesi_now($pdo);
            $v = mb_substr(trim($in['value'] ?? ''), 0, 100);
            if ($v === '') $v = 'Sesi ' . $sn['id'];
            $pdo->prepare('UPDATE klasemen_sesi SET judul = ? WHERE id = ?')->execute([$v, $sn['id']]);
        } elseif ($a === 'sesi_baru') {   // selesaikan sesi ini (hasil disimpan) lalu mulai sesi berikutnya
            try { backup_db($pdo); } catch (Throwable $e) {}
            $sn = sesi_now($pdo);
            $hs = [];
            foreach (array_slice(is_array($in['hasil'] ?? null) ? $in['hasil'] : [], 0, 20) as $h) {
                if (!is_array($h)) continue;
                $hs[] = ['nama' => mb_substr((string)($h['nama'] ?? ''), 0, 100), 'usn' => mb_substr((string)($h['usn'] ?? ''), 0, 100),
                    'display' => mb_substr((string)($h['display'] ?? ''), 0, 100), 'total' => (int)($h['total'] ?? 0),
                    'bonus' => (int)($h['bonus'] ?? 0), 'tangkapan' => (int)($h['tangkapan'] ?? 0), 'pay' => mb_substr((string)($h['pay'] ?? ''), 0, 40)];
            }
            usort($hs, fn($x, $y) => $y['total'] <=> $x['total']);
            $now = gmdate('Y-m-d H:i:s');
            $pdo->prepare('UPDATE klasemen_sesi SET selesai = ?, hasil = ? WHERE id = ?')->execute([$now, json_encode($hs, JSON_UNESCAPED_UNICODE), $sn['id']]);
            $next = mb_substr(trim($in['judul'] ?? ''), 0, 100);
            $nn = (int)$pdo->query('SELECT COUNT(*) FROM klasemen_sesi')->fetchColumn() + 1;
            $pdo->prepare('INSERT INTO klasemen_sesi (judul, mulai) VALUES (?, ?)')->execute([$next !== '' ? $next : "Sesi $nn", $now]);
            // ronde baru: timer & poin kembali ke awal, data tangkapan dihapus
            $pdo->exec('DELETE FROM klasemen_timer');
            $pdo->prepare("REPLACE INTO klasemen_meta (k, v) VALUES ('start', ?)")->execute([$now]);
            $pdo->exec("DELETE FROM discord_messages WHERE embeds <> '[]'");
            $pdo->exec('DELETE FROM klasemen_lama');
            if (!empty($in['kosong']))   // kosongkan semua peserta (riwayat pemain tetap tersimpan)
                $pdo->exec("UPDATE klasemen_peserta SET nama = '', usn = '', display_name = '', pay = 0, paytxt = '', paybin = 0, absen = 0, bonus = 0");
            else                         // peserta dipertahankan, poin/pay/status join dikosongkan
                $pdo->exec("UPDATE klasemen_peserta SET pay = 0, paytxt = '', paybin = 0, absen = 0, bonus = 0");
        } elseif ($a === 'set_tier') {   // ubah poin satu tier
            $t = $in['tier'] ?? '';
            if (isset(TIER[$t])) {
                $v = max(0, min(999, (int)($in['value'] ?? 0)));
                $pdo->prepare('REPLACE INTO klasemen_meta (k, v) VALUES (?, ?)')->execute(['pt_' . $t, $v]);
            }
        } elseif ($a === 'set_tier_on') {   // centang / hapus centang rarity yang dihitung
            $t = $in['tier'] ?? '';
            if (isset(TIER[$t]))
                $pdo->prepare('REPLACE INTO klasemen_meta (k, v) VALUES (?, ?)')->execute(['on_' . $t, !empty($in['on']) ? 1 : 0]);
        } elseif ($a === 'reset_tier') {   // kembalikan ke bawaan (10/5/2/1)
            $pdo->exec("DELETE FROM klasemen_meta WHERE k IN ('pt_forgotten','pt_secret','pt_myth','pt_legend')");
        } elseif ($a === 'reset_points') {   // reset poin saja (timer tidak diubah): poin ikan dihitung ulang dari sekarang, poin manual & bin dihapus
            try { backup_db($pdo); } catch (Throwable $e) {}   // backup sebelum reset
            $pdo->prepare("REPLACE INTO klasemen_meta (k, v) VALUES ('start', ?)")->execute([gmdate('Y-m-d H:i:s')]);
            $pdo->exec('UPDATE klasemen_peserta SET bonus = 0, paybin = 0');
            $pdo->exec("DELETE FROM discord_messages WHERE embeds <> '[]'");
            $pdo->exec('DELETE FROM klasemen_lama');   // hapus data tangkapan dari database
        } elseif ($a === 'reset') {
            try { backup_db($pdo); } catch (Throwable $e) {}   // backup sebelum reset
            $pdo->exec('DELETE FROM klasemen_timer');   // ronde baru: poin dihitung dari sekarang, bonus manual dihapus
            $pdo->prepare("REPLACE INTO klasemen_meta (k, v) VALUES ('start', ?)")->execute([gmdate('Y-m-d H:i:s')]);
            $pdo->exec('UPDATE klasemen_peserta SET bonus = 0, paybin = 0');
            $pdo->exec("DELETE FROM discord_messages WHERE embeds <> '[]'");
            $pdo->exec('DELETE FROM klasemen_lama');   // hapus data tangkapan dari database
        }
    }

    // ===== Perawatan otomatis (hanya request lambat ?av=1, agar polling cepat tidak tertahan) =====
    $disk = disk_info();
    if ($slow) {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
        $bf = $tmp . 'klasemen_backup.txt';
        if (!$disk['low'] && time() - (is_file($bf) ? (int)file_get_contents($bf) : 0) >= BACKUP_EVERY) {
            file_put_contents($bf, (string)time());
            try { backup_db($pdo); } catch (Throwable $e) {}
        }
        $cf = $tmp . 'klasemen_cleanup.txt';
        if (time() - (is_file($cf) ? (int)file_get_contents($cf) : 0) >= ($disk['low'] ? CLEAN_EVERY_LOW : CLEAN_EVERY)) {
            file_put_contents($cf, (string)time());
            try { cleanup_db($pdo, $disk['low']); } catch (Throwable $e) {}
        }
    }

    $ts = timer_state($pdo);
    $start = $pdo->query("SELECT v FROM klasemen_meta WHERE k = 'start'")->fetchColumn() ?: '1970-01-01 00:00:00';
    $tiers = tier_points($pdo); $aturan = aturan_list($pdo); $tierOn = tier_enabled($pdo);

    // ===== Cache hasil hitung =====
    // Sidik jari data dihitung langsung di MySQL (tanpa memindahkan semua embed ke PHP). Selama pesan, data lama,
    // interval timer, dan pengaturan poin tidak berubah, hasil hitung sebelumnya dipakai ulang -> respons hampir instan.
    $fq = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(CRC32(embeds)), 0), COALESCE(MAX(msg_time), '') FROM discord_messages WHERE msg_time >= ? AND embeds <> '[]'");
    $fq->execute([$start]); $fpm = $fq->fetch(PDO::FETCH_NUM);
    $fpl = $pdo->query('SELECT COUNT(*), COALESCE(SUM(CRC32(k)), 0) FROM klasemen_lama')->fetch(PDO::FETCH_NUM);
    $ckey = md5(json_encode([$fpm, $fpl, $ts['iv'], $start, $tiers, $aturan, $tierOn, 'v4']));
    $cfile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'klasemen_cache.bin';
    $cc = is_file($cfile) ? @unserialize((string)@file_get_contents($cfile), ['allowed_classes' => false]) : null;

    if (is_array($cc) && ($cc['k'] ?? '') === $ckey) {
        $byName = $cc['byName']; $log = $cc['log']; $joinSet = $cc['joinSet'];
        header('X-Cache: hit');
    } else {
        header('X-Cache: miss');
        $st = $pdo->prepare("SELECT msg_time, embeds FROM discord_messages WHERE msg_time >= ? AND embeds <> '[]' ORDER BY msg_time ASC");
        $st->execute([$start]);
        $byName = []; $log = []; $joinSet = [];
        $lama = array_flip($pdo->query('SELECT k FROM klasemen_lama')->fetchAll(PDO::FETCH_COLUMN));
        foreach ($st as $r) {
            $embeds = json_decode($r['embeds'], true) ?: [];
            // "Player Joined" / "Player Left": dicatat kapan pun (tidak terikat timer). Pesan terbaru menang (urut msg_time ASC)
            foreach ($embeds as $e) {
                foreach (parse_join($e) as $jn) $joinSet[mb_strtolower($jn)] = true;    // join
                foreach (parse_left($e) as $ln) $joinSet[mb_strtolower($ln)] = false;   // keluar
            }

            if (isset($lama[lama_key($r)])) continue;   // data lama (sudah ada sebelum timer dimulai) -> tidak dihitung

            $te = strtotime($r['msg_time'] . ' UTC'); $ok = false;
            foreach ($ts['iv'] as [$s0, $e0, $c0]) if ($te >= $s0 && $te <= ($c0 ?? PHP_INT_MAX)) { $ok = true; break; }
            if (!$ok) continue;   // diluar waktu timer berjalan -> tidak dihitung
            foreach ($embeds as $e) {
                if (!($ev = parse_embed($e, $tiers, $aturan, $tierOn))) continue;
                $ev['t'] = $r['msg_time'];
                $byName[mb_strtolower($ev['name'])][] = $ev;
                $log[] = $ev;
            }
        }
        @file_put_contents($cfile, serialize(['k' => $ckey, 'byName' => $byName, 'log' => $log, 'joinSet' => $joinSet]), LOCK_EX);
    }

    $players = []; $known = []; $budget = $slow ? 2 : 0;   // maks. 2 pencarian avatar baru, hanya di request lambat
    $av = [];
    foreach ($pdo->query('SELECT * FROM klasemen_avatar')->fetchAll() as $r) $av[$r['kunci']] = $r;
    foreach ($pdo->query('SELECT * FROM klasemen_peserta ORDER BY slot')->fetchAll() as $p) {
        $key = mb_strtolower($p['display_name']);
        $k2  = mb_strtolower($p['nama']);
        $k3  = mb_strtolower($p['usn']);
        $keys = array_values(array_unique(array_filter([$key, $k2, $k3], fn($x) => $x !== '')));
        $evs = [];
        foreach ($keys as $kk) { $evs = array_merge($evs, $byName[$kk] ?? []); $known[$kk] = true; }
        usort($evs, fn($x, $y) => strcmp($x['t'], $y['t']));
        $fp  = array_sum(array_column($evs, 'pts'));
        $isJoined = false; $isLeft = false;
        foreach ($keys as $kk) if (isset($joinSet[$kk])) { if ($joinSet[$kk]) $isJoined = true; else $isLeft = true; }
        $isLeft = $isLeft && !$isJoined;   // sudah keluar (dan tidak join lagi sesudahnya)
        $any = $keys !== [];
        $ck = md5($k2 . '|' . $key . '|' . $k3);
        $c = $any ? ($av[$ck] ?? null) : null;
        if ($any && $isGet && $budget > 0 && !($c && $c['st'] && time() - $c['ts'] < ($c['url'] ? 604800 : 600))) {
            $budget--;
            // username Roblox: kolom usn; peserta lama (usn kosong) memakai kolom nama sebagai username
            [$uid, $url, $stt, $rd] = roblox_avatar($p['usn'] !== '' ? $p['usn'] : $p['nama'], $p['display_name']);
            $pdo->prepare('REPLACE INTO klasemen_avatar (kunci, user_id, url, ts, st, rd) VALUES (?,?,?,?,?,?)')
                ->execute([$ck, $uid, $url, time() - ($uid && !$url ? 570 : 0), $stt, $rd]);
            $c = ['url' => $url, 'st' => $stt, 'rd' => $rd];
            // simpan/perbarui riwayat pemain (avatar & display name terbaru), tanpa menambah hitungan main
            $un = $p['usn'] !== '' ? $p['usn'] : ($uid ? $p['nama'] : '');
            if ($un !== '') {
                try { riwayat_save($pdo, $un, $p['usn'] !== '' ? $p['nama'] : '', $rd ?: $p['display_name'], $uid, $url, 0); } catch (Throwable $e) {}
            }
        }
        $players[] = ['slot' => (int)$p['slot'], 'nama' => $p['nama'], 'usn' => $p['usn'], 'display_name' => $p['display_name'],
            'pay' => $p['paytxt'], 'paid' => (int)$p['pay'], 'absen' => (int)$p['absen'], 'bonus' => (int)$p['bonus'],
            'joined' => $isJoined,
            'left' => $isLeft,
            'total' => $fp + (int)$p['bonus'], 'avatar' => $c['url'] ?? null,
            'rbx' => $any ? ['status' => $c['st'] ?? 'cek', 'display' => $c['rd'] ?? ''] : null,
            'ev' => array_reverse($evs)];
    }
    $unmatched = array_values(array_map(fn($ev) => $ev[0]['name'], array_diff_key($byName, $known)));

    header('X-Gen-Ms: ' . round((microtime(true) - $T0) * 1000));
    echo json_encode(['players' => $players, 'log' => array_slice(array_reverse($log), 0, 30),
        'unmatched' => $unmatched, 'sync' => $sync, 'error' => $err, 'need_usn' => $needUsn, 'kandidat' => $needKand,
        'timer' => ['running' => $ts['running'], 'remaining' => $ts['remaining'], 'total' => $ts['total']],
        'disk' => $disk, 'tiers' => $tiers, 'tier_on' => $tierOn, 'aturan' => $aturan, 'sesi' => sesi_now($pdo), 'now' => gmdate('Y-m-d H:i:s')],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}