<?php
/**
 * TONPICK Professional Bot v7.7.2 (pCaptcha retry + prompt)
 * Auto hourly claim + Gemini captcha + auto withdraw + Telegram channel logger
 * Wallet: config wallet field
 */

error_reporting(0);

// --- .ENV / Render ENV: TG_*, GEMINI_API_KEY, TZ ---
if (file_exists(__DIR__ . '/.env')) {
    $envLines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (strlen($value) >= 2) {
            $q = $value[0];
            if (($q === '"' || $q === "'") && substr($value, -1) === $q) {
                $value = substr($value, 1, -1);
            }
        }
        if ($name !== '' && !array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
            putenv("$name=$value");
        }
    }
}

function env($key, $default = null) {
    if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') return $_ENV[$key];
    $val = getenv($key);
    if ($val !== false && $val !== '') return $val;
    if (!empty($_SERVER[$key])) return $_SERVER[$key];
    return $default;
}

date_default_timezone_set(env("TZ", "Asia/Tashkent"));

function nowStr() {
    return date("Y-m-d H:i:s") . " UZT";
}

if (PHP_OS_FAMILY == "Linux") {
    define("n", "\n"); define("d", "\033[0m"); define("m", "\033[1;31m");
    define("h", "\033[1;32m"); define("k", "\033[1;33m"); define("c", "\033[1;36m");
    define("p", "\033[1;37m"); define("bg", "\033[44m");
} else {
    define("n", "\n"); define("d", ""); define("m", ""); define("h", "");
    define("k", ""); define("c", ""); define("p", ""); define("bg", "");
}

const TITLE         = "TONPICK PRO";
const VERSION       = "7.7.2";
const HOST          = "https://tonpick.game/";
const REFF          = "https://tonpick.game/?ref=iewilmaestro";
const HCAPTCHA_KEY  = "6ef421cc-2fbc-493a-96ec-550c43f8ecd2";
const RECAPTCHA_KEY = "6LcqLB8rAAAAAGxzcVp9W7i4uSZHr-DmHvWQb-KO";
const IC_ENDPOINT   = "https://tonpick.game/iconcaptcha.php";
const MIN_WITHDRAW  = 0.1;

class Display {
    static function clear() { system(PHP_OS_FAMILY == "Linux" ? "clear" : "cls"); }
    static function banner() {
        self::clear();
        echo n . bg . p . str_pad(" " . TITLE . " BOT  v" . VERSION . " ", 46, " ", STR_PAD_BOTH) . d . n;
        echo c . "┌" . str_repeat("─", 44) . "┐" . n;
        echo c . "│" . p . "  Fresh Hash + Accurate Captcha       " . str_repeat(" ", 1) . c . "│" . n;
        echo c . "│" . p . "  Site: " . k . "tonpick.game" . str_repeat(" ", 19) . c . "│" . n;
        echo c . "└" . str_repeat("─", 44) . "┘" . n;
        echo c . str_repeat("─", 46) . n;
    }
    static function line() { echo c . str_repeat("─", 46) . n; }
    static function info($l, $v) { echo h . " [" . p . str_pad($l, 10) . h . "] → " . p . $v . n; }
    static function ok($m) { echo h . " [✓] " . p . $m . n; }
    static function err($m) { echo m . " [!] " . p . $m . n; }
    static function warn($m) { echo k . " [!] " . p . $m . n; }
    static function input($m) { return m . " ╭[" . p . "Input " . $m . m . "]" . n . m . " ╰> " . h; }
    static function title($t) { echo bg . p . str_pad(strtoupper($t), 46, " ", STR_PAD_BOTH) . d . n; }
}

class F {
    public $cfgFile = "config_tonpick.json";

    public function curl($url, $headers = [], $post = null) {
        while (true) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_TIMEOUT => 90,
                CURLOPT_HEADER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_ENCODING => "",
                CURLOPT_COOKIEFILE => "cookie_ton.txt",
                CURLOPT_COOKIEJAR => "cookie_ton.txt",
            ]);
            if ($post !== null) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
            }
            $res = curl_exec($ch);
            $err = curl_error($ch);
            $info = curl_getinfo($ch);
            curl_close($ch);
            if ($err) { Display::err("Curl: $err"); sleep(3); continue; }
            $hs = $info["header_size"];
            $body = substr($res, $hs);
            if ($body === "" || $body === false) { Display::warn("Empty response"); sleep(2); continue; }
            return [substr($res, 0, $hs), $body, (int)$info["http_code"]];
        }
    }

    public function setCfg($key, $default = null) {
        $c = file_exists($this->cfgFile) ? json_decode(file_get_contents($this->cfgFile), true) : [];
        if (!is_array($c)) $c = [];
        if (isset($c[$key]) && $c[$key] !== "" && $c[$key] !== null) return $c[$key];
        if ($default !== null) {
            $c[$key] = $default;
            file_put_contents($this->cfgFile, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $default;
        }
        $v = trim(readline(Display::input($key))); echo n;
        $c[$key] = $v;
        file_put_contents($this->cfgFile, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $v;
    }

    public function getCfg($key) {
        if (!file_exists($this->cfgFile)) return null;
        $c = json_decode(file_get_contents($this->cfgFile), true);
        return is_array($c) ? ($c[$key] ?? null) : null;
    }

    public function timer($sec) {
        $end = time() + max(1, (int)$sec);
        $s = ["─", "/", "│", "\\"]; $i = 0;
        while (true) {
            $l = $end - time();
            if ($l < 1) break;
            $hh = str_pad((string)floor($l / 3600), 2, "0", STR_PAD_LEFT);
            $mm = str_pad((string)floor(($l % 3600) / 60), 2, "0", STR_PAD_LEFT);
            $ss = str_pad((string)($l % 60), 2, "0", STR_PAD_LEFT);
            echo "\r  " . c . $s[$i % 4] . p . " Next: " . h . "$hh:$mm:$ss" . p . "    ";
            usleep(250000); $i++;
        }
        echo "\r" . str_repeat(" ", 42) . "\r";
    }

    public function sendTelegram($msg) {
        $token = env("TG_BOT_TOKEN");
        $chatId = env("TG_CHAT_ID");
        if (empty($token) || empty($chatId)) return false;
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $data = [
            "chat_id" => $chatId,
            "text" => $msg,
            "parse_mode" => "HTML",
            "disable_web_page_preview" => true
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 15
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        $j = @json_decode($res, true);
        return !empty($j["ok"]);
    }

    public function cookiesFromHeader($header) {
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $m);
        $out = [];
        foreach ($m[1] as $item) { parse_str($item, $x); $out = array_merge($out, $x); }
        return $out;
    }

    public function makeHash($key) {
        if (!$key) return "";
        $rx = rand(50, 110);
        $ry = rand(15, 40);
        $ts = time();
        $data = $rx . ":" . $ry . ":" . $ts;
        $enc = "";
        $kl = strlen($key);
        for ($i = 0; $i < strlen($data); $i++) {
            $enc .= chr(ord($data[$i]) ^ ord($key[$i % $kl]));
        }
        return base64_encode($enc);
    }

    public function uuid() {
        return sprintf("%04x%04x-%04x-%04x-%04x-%04x%04x%04x",
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }

    public function parseJsonMes($raw) {
        $j = json_decode($raw, true);
        if (is_array($j) && isset($j["mes"])) return $j;
        if (preg_match_all('/\{[^{}]*"ret"\s*:\s*(\d+)[^{}]*"mes"\s*:\s*"([^"]*)"[^{}]*\}/', $raw, $mm, PREG_SET_ORDER)) {
            $last = end($mm);
            return ["ret" => (int)$last[1], "mes" => $last[2]];
        }
        if (preg_match_all('/"mes"\s*:\s*"([^"]+)"/', $raw, $mm)) {
            $ret = 0;
            if (preg_match_all('/"ret"\s*:\s*(\d+)/', $raw, $rr)) $ret = (int)end($rr[1]);
            return ["ret" => $ret, "mes" => end($mm[1])];
        }
        return null;
    }

    public function parseWaitSeconds($msg) {
        $sec = 0;
        if (preg_match('/wait\s+for\s+(\d+)\s+minute/i', $msg, $m)) $sec += ((int)$m[1]) * 60;
        if (preg_match('/try\s+again\s+in\s+(\d+)\s+minute/i', $msg, $m)) $sec += ((int)$m[1]) * 60;
        if (preg_match('/(\d+)\s+second/i', $msg, $m)) $sec += (int)$m[1];
        if ($sec < 1 && preg_match('/(\d+)\s*min/i', $msg, $m)) $sec = ((int)$m[1]) * 60;
        return $sec;
    }

    public function statsFile() {
        return __DIR__ . "/stats_tonpick.json";
    }

    public function loadStats() {
        $f = $this->statsFile();
        $today = date("Y-m-d");
        $def = [
            "day" => $today,
            "claims_today" => 0,
            "fails_today" => 0,
            "total_claims" => 0,
            "last_claim" => null,
            "last_balance" => "0",
            "last_user" => "",
            "last_captcha" => "",
        ];
        if (!file_exists($f)) return $def;
        $j = json_decode(@file_get_contents($f), true);
        if (!is_array($j)) return $def;
        if (($j["day"] ?? "") !== $today) {
            $j["day"] = $today;
            $j["claims_today"] = 0;
            $j["fails_today"] = 0;
        }
        return array_merge($def, $j);
    }

    public function saveStats($s) {
        @file_put_contents($this->statsFile(), json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function recordClaim($user, $balance, $captchaName) {
        $s = $this->loadStats();
        $s["claims_today"] = (int)$s["claims_today"] + 1;
        $s["total_claims"] = (int)$s["total_claims"] + 1;
        $s["last_claim"] = nowStr();
        $s["last_balance"] = $balance;
        $s["last_user"] = $user;
        $s["last_captcha"] = $captchaName;
        $this->saveStats($s);
        return $s;
    }

    public function recordFail() {
        $s = $this->loadStats();
        $s["fails_today"] = (int)$s["fails_today"] + 1;
        $this->saveStats($s);
        return $s;
    }

    public function tgClaimMessage($user, $balance, $msg, $capName, $stats) {
        $goal = 24;
        $done = (int)$stats["claims_today"];
        $left = max(0, $goal - $done);
        $barLen = 12;
        $filled = (int)round(min($goal, $done) / max(1, $goal) * $barLen);
        $bar = str_repeat("█", $filled) . str_repeat("░", $barLen - $filled);
        $pct = (int)round(min($goal, $done) / max(1, $goal) * 100);

        return
            "🚀 <b>TONPICK — Claim Success</b>\n" .
            "━━━━━━━━━━━━━━━━━━━━\n" .
            "👤 <b>User:</b> <code>" . htmlspecialchars($user) . "</code>\n" .
            "💰 <b>Balance:</b> <code>{$balance} TON</code>\n" .
            "🧩 <b>Captcha:</b> {$capName}\n" .
            "✅ <b>Result:</b> " . htmlspecialchars($msg) . "\n" .
            "━━━━━━━━━━━━━━━━━━━━\n" .
            "📊 <b>Bugun:</b> <code>{$done}/{$goal}</code> claim ({$pct}%)\n" .
            "📈 <code>[{$bar}]</code>\n" .
            "⏳ <b>Qoldi:</b> ~{$left} (24s maqsad)\n" .
            "📦 <b>Jami:</b> <code>" . (int)$stats["total_claims"] . "</code>\n" .
            "❌ <b>Fail bugun:</b> <code>" . (int)$stats["fails_today"] . "</code>\n" .
            "⏰ <b>Vaqt:</b> <code>" . nowStr() . "</code>";
    }

    public function tgWithdrawMessage($user, $amount, $wallet, $msg) {
        return
            "🔔 <b>TONPICK — Auto Withdraw</b>\n" .
            "━━━━━━━━━━━━━━━━━━━━\n" .
            "👤 <b>User:</b> <code>" . htmlspecialchars($user) . "</code>\n" .
            "💰 <b>Amount:</b> <code>{$amount} TON</code>\n" .
            "📫 <b>Wallet:</b> <code>" . htmlspecialchars($wallet) . "</code>\n" .
            "✅ <b>Result:</b> " . htmlspecialchars($msg) . "\n" .
            "⏰ <b>Vaqt:</b> <code>" . nowStr() . "</code>";
    }
}

class Gemini {
    private $key;
    public function __construct($k) { $this->key = $k; }

    private function ask($parts, $max = 48) {
        $models = ["gemini-flash-latest", "gemini-3.1-flash-lite", "gemini-3-flash-preview"];
        $body = [
            "contents" => [["parts" => $parts]],
            "generationConfig" => ["temperature" => 0.1, "maxOutputTokens" => $max]
        ];
        foreach ($models as $model) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($this->key);
            $ctx = stream_context_create([
                "http" => [
                    "method" => "POST",
                    "header" => "Content-Type: application/json\r\n",
                    "content" => json_encode($body),
                    "timeout" => 60,
                    "ignore_errors" => true
                ]
            ]);
            $res = @file_get_contents($url, false, $ctx);
            if (!$res) continue;
            $j = json_decode($res, true);
            if (!empty($j["error"])) continue;
            $t = trim($j["candidates"][0]["content"]["parts"][0]["text"] ?? "");
            if ($t !== "") return [$t, $model];
        }
        return [null, null];
    }

    public function pCaptcha($question, $imgBin) {
        $b64 = base64_encode($imgBin);
        $mime = "image/jpeg";
        if (strlen($imgBin) >= 8 && ord($imgBin[0]) === 0x89 && substr($imgBin, 1, 3) === "PNG") {
            $mime = "image/png";
        } elseif (strlen($imgBin) >= 3 && substr($imgBin, 0, 3) === "GIF") {
            $mime = "image/gif";
        }
        $q = trim($question);
        $prompt =
            "You solve a 3x3 image-grid captcha.\n" .
            "Cell indices (0-based):\n" .
            "0 1 2\n3 4 5\n6 7 8\n" .
            "Question: {$q}\n" .
            "Select EVERY cell that matches the question.\n" .
            "Reply with ONLY digits and commas, e.g. 0,2,5\n" .
            "If none match reply: none";
        list($text, $model) = $this->ask([
            ["text" => $prompt],
            ["inline_data" => ["mime_type" => $mime, "data" => $b64]]
        ], 48);
        if (!$text) return false;
        $text = strtolower(trim($text));
        if (strpos($text, "none") !== false && !preg_match('/\d/', $text)) {
            Display::ok("Gemini($model) pCaptcha: none");
            return [];
        }
        preg_match_all('/\b([0-8])\b/', $text, $m);
        $idx = array_values(array_unique(array_map("intval", $m[1])));
        if (!$idx) {
            Display::warn("Gemini pCaptcha parse fail: " . substr($text, 0, 80));
            return false;
        }
        Display::ok("Gemini($model) pCaptcha cells: " . implode(",", $idx));
        return $idx;
    }

    public function iconCaptcha($imgB64) {
        $imgB64 = preg_replace('#^data:image/\w+;base64,#', '', $imgB64);
        $prompt = "IconCaptcha row of icons. Find LEAST common icon. Index from LEFT starts at 0. Reply ONLY one number.";
        list($text, $model) = $this->ask([
            ["text" => $prompt],
            ["inline_data" => ["mime_type" => "image/png", "data" => $imgB64]]
        ], 12);
        if (!$text) return false;
        if (preg_match('/(\d+)/', $text, $m)) {
            Display::ok("Gemini($model) Icon index: " . $m[1]);
            return (int)$m[1];
        }
        return false;
    }
}

class Solver {
    private $url, $key;
    public $gemini;

    public function __construct(F $f) {
        if ($f->getCfg("type") === null || $f->getCfg("type") === "") {
            echo n; Display::title("CAPTCHA API");
            echo h . "  [1] Multibot" . n . h . "  [2] Xevil" . n . n;
            $f->setCfg("type");
            Display::line();
        }
        if ($f->getCfg("type") == 1) {
            $this->url = "http://api.multibot.in/";
            $this->key = $f->setCfg("multibot_apikey");
        } else {
            $this->url = "https://sctg.xyz/";
            $this->key = $f->setCfg("xevil_apikey") . "|SOFTID1204538927";
        }
        // Gemini: Render/ENV → config → input
        $gk = env("GEMINI_API_KEY", null);
        if (empty($gk)) $gk = env("GEMINI_APIKEY", null);
        if (empty($gk)) $gk = $f->getCfg("gemini_apikey");
        $fromEnv = !empty(env("GEMINI_API_KEY", null)) || !empty(env("GEMINI_APIKEY", null));
        if (empty($gk)) {
            if (function_exists("stream_isatty") && defined("STDIN") && @stream_isatty(STDIN)) {
                Display::warn("Gemini key yo'q — ENV yoki input");
                $gk = $f->setCfg("gemini_apikey");
                $fromEnv = false;
            } else {
                Display::err("GEMINI_API_KEY topilmadi (Render Environment)");
                exit(1);
            }
        }
        $this->gemini = new Gemini(trim($gk));
        Display::info("Gemini", $fromEnv ? "ENV ✓" : "config/input");
    }

    private function httpGet($url) {
        $ctx = stream_context_create([
            "http" => [
                "method" => "GET",
                "timeout" => 45,
                "ignore_errors" => true,
                "header" => "User-Agent: Mozilla/5.0\r\n"
            ],
            "ssl" => [
                "verify_peer" => false,
                "verify_peer_name" => false,
            ]
        ]);
        $res = @file_get_contents($url, false, $ctx);
        return $res === false ? null : $res;
    }

    private function in($data) {
        $url = $this->url . "in.php?key=" . urlencode($this->key) . "&json=1&" . $data;
        $raw = $this->httpGet($url);
        if ($raw === null) {
            Display::warn("Captcha API: network error (in)");
            return null;
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            Display::warn("Captcha API: bad JSON: " . substr(trim($raw), 0, 120));
            return null;
        }
        return $j;
    }

    private function res($id) {
        $url = $this->url . "res.php?key=" . urlencode($this->key) . "&action=get&id=" . urlencode($id) . "&json=1";
        $raw = $this->httpGet($url);
        if ($raw === null) return null;
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }

    private function waitToken($data, $label, $maxWait = 180) {
        $in = $this->in($data);
        if (!$in) return false;

        // API error: status=0, request = error text
        if (empty($in["status"])) {
            $err = $in["request"] ?? $in["error_text"] ?? $in["error"] ?? json_encode($in);
            Display::warn("$label API: " . $err);
            return false;
        }

        $id = $in["request"];
        Display::info("$label", "task id: $id");
        $start = time();
        while (true) {
            if (time() - $start > $maxWait) {
                Display::warn("$label timeout ({$maxWait}s)");
                return false;
            }
            sleep(5);
            $r = $this->res($id);
            if (!$r) {
                Display::warn("$label: res network error");
                sleep(3);
                continue;
            }
            $req = $r["request"] ?? "";
            if ($req === "CAPCHA_NOT_READY" || $req === "CAPTCHA_NOT_READY") {
                continue;
            }
            if (!empty($r["status"]) && $req !== "" && $req !== "ERROR") {
                Display::ok("$label solved");
                return $req;
            }
            // status 0 = error
            $err = $req ?: ($r["error_text"] ?? "unknown");
            Display::warn("$label fail: " . $err);
            return false;
        }
    }

    public function balance() {
        $raw = $this->httpGet($this->url . "res.php?action=userinfo&key=" . urlencode($this->key));
        $r = $raw ? json_decode($raw, true) : null;
        return $r["balance"] ?? "0";
    }

    public function recaptcha($page) {
        return $this->waitToken(http_build_query([
            "method" => "userrecaptcha",
            "sitekey" => RECAPTCHA_KEY,
            "pageurl" => $page,
        ]), "reCAPTCHA");
    }

    public function hcaptcha($page) {
        // 1) standard hcaptcha
        $token = $this->waitToken(http_build_query([
            "method" => "hcaptcha",
            "sitekey" => HCAPTCHA_KEY,
            "pageurl" => $page,
        ]), "hCaptcha");
        if ($token) return $token;

        // 2) ba'zi panel: userrecaptcha o'rniga hcaptcha + softid
        Display::warn("hCaptcha qayta urinish (alt method)...");
        $token = $this->waitToken(http_build_query([
            "method" => "hcaptcha",
            "sitekey" => HCAPTCHA_KEY,
            "pageurl" => $page,
            "json" => "1",
        ]), "hCaptcha-alt", 120);
        return $token;
    }
}

class Bot extends F {
    public $cookie, $ua, $solver;

    public function __construct() {
        Display::banner();

        if (empty($this->getCfg("cookie"))) {
            Display::info("Register", REFF);
            Display::line();
        }

        $this->cookie = $this->setCfg("cookie");
        $this->ua = $this->setCfg("user_agent");

        $this->setCfg("wallet", "UQCqttk9IC-vceUkwNjERyf417ml83Ia88gWWKm6_4hfUPvi");
        $this->setCfg("min_withdraw", "0.1");
        $this->setCfg("auto_withdraw", "1");

        $this->solver = new Solver($this);

        Display::banner();
        $d = $this->dashboard();
        if (!$d["username"]) {
            Display::err("Login failed — cookie / user_agent ni tekshiring");
            exit;
        }
        Display::info("Username", $d["username"]);
        Display::info("Balance", $d["balance"] . " TON");
        Display::info("API Bal", $this->solver->balance());
        Display::info("Wallet", $this->getCfg("wallet"));
        Display::info("TZ", env("TZ", "Asia/Tashkent"));
        $st = $this->loadStats();
        Display::info("Bugun", $st["claims_today"] . "/24 | jami " . $st["total_claims"]);
        Display::line();
        Display::title("AUTO HOURLY + SMART CAPTCHA + TG LOG");
        $this->loop();
    }

    private function hdr($ajax = false) {
        $h = [
            "Host: " . parse_url(HOST)["host"],
            "Cookie: " . $this->cookie,
            "User-Agent: " . $this->ua,
            "Accept-Language: en-US,en;q=0.9",
            "Origin: " . HOST,
            "Referer: " . HOST . "faucet.php",
        ];
        if ($ajax) {
            $h[] = "X-Requested-With: XMLHttpRequest";
            $h[] = "Content-Type: application/x-www-form-urlencoded; charset=UTF-8";
            $h[] = "Accept: application/json, text/javascript, */*; q=0.01";
        } else {
            $h[] = "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8";
        }
        return $h;
    }

    public function dashboard() {
        $body = $this->curl(HOST, $this->hdr())[1];
        $user = $bal = "";
        if (preg_match('/class="profile-name">\s*([^<]+)/i', $body, $m)) $user = trim($m[1]);
        if (preg_match('/class="[^"]*user_balance[^"]*"[^>]*>\s*([0-9.]+)/i', $body, $m)) $bal = trim($m[1]);
        elseif (preg_match('/user_balance">\s*([0-9.]+)/i', $body, $m)) $bal = trim($m[1]);
        return ["username" => $user, "balance" => $bal ?: "0"];
    }

    private function detect($body) {
        $available = [];
        $defaultType = null;
        if (preg_match_all('/<option[^>]*value="(\d+)"([^>]*)>([^<]*)</i', $body, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $o) {
                $v = (int)$o[1];
                if (!in_array($v, [0, 1, 2, 4], true)) continue;
                $available[] = $v;
                if (stripos($o[2] . $o[0], "selected") !== false) $defaultType = $v;
            }
        }
        $available = array_values(array_unique($available));
        if ($defaultType === null) {
            $defaultType = $available[0] ?? 1;
        }

        $disabled = (bool)preg_match('/id=["\']select_captcha["\'][^>]*disabled/i', $body)
            || (bool)preg_match('/select_captcha[^>]*disabled/i', $body);

        $force = $this->getCfg("force_captcha");
        if ($force !== null && $force !== "") {
            return ["default" => (int)$force, "available" => [(int)$force], "disabled" => true];
        }

        return ["default" => (int)$defaultType, "available" => $available ?: [(int)$defaultType], "disabled" => $disabled];
    }

    private function extractHashKey($body) {
        if (preg_match("/get_hash\s*\(\s*event\s*,\s*'([a-f0-9]+)'/i", $body, $m)) return $m[1];
        return "";
    }

    private function solveCaptchaOnPage($body, $pageUrl) {
        $info = $this->detect($body);
        $defaultType = $info["default"];
        $available = $info["available"];
        $disabled = $info["disabled"];
        $names = [0 => "IconCaptcha", 1 => "reCAPTCHA", 2 => "hCaptcha", 4 => "pCaptcha"];

        // Select disabled yoki faqat 1 tur ochiq → faqat shu(lar)
        if ($disabled || count($available) === 1) {
            $priorityList = $available;
        } else {
            // default birinchi, keyin faqat available ichidagilar
            $priorityList = [$defaultType];
            foreach ([4, 0, 2, 1] as $t) {
                if (in_array($t, $available, true) && !in_array($t, $priorityList, true)) {
                    $priorityList[] = $t;
                }
            }
        }
        $priorityList = array_values(array_unique($priorityList));

        Display::info("Captcha", ($names[$defaultType] ?? $defaultType) . ($disabled ? " (locked)" : ""));
        Display::info("Options", implode(", ", array_map(function ($t) use ($names) {
            return $names[$t] ?? $t;
        }, $priorityList)));

        foreach ($priorityList as $type) {
            Display::info("Captcha Try", $names[$type] ?? "type$type");
            $g = $hcap = $pc = "";
            $ic = ["_iconcaptcha-token" => "", "ic-rq" => "", "ic-wid" => "", "ic-cid" => "", "ic-hp" => ""];
            $success = false;

            if ($type === 4) {
                $pc = $this->solvePCaptcha();
                if ($pc) $success = true;
            } elseif ($type === 0) {
                $tmp = $this->solveIcon($body);
                if ($tmp) { $ic = $tmp; $success = true; }
            } elseif ($type === 2) {
                $hcap = $this->solver->hcaptcha($pageUrl);
                if ($hcap) $success = true;
            } else {
                $type = 1;
                $g = $this->solver->recaptcha($pageUrl);
                if ($g) $success = true;
            }

            if ($success) {
                Display::ok($names[$type] . " muvaffaqiyatli yechildi!");
                return [
                    "type" => $type,
                    "g" => $g,
                    "hcap" => $hcap,
                    "pc" => $pc,
                    "ic" => $ic,
                ];
            } else {
                Display::warn($names[$type] . " yechilmadi");
                // locked select — boshqa turga o'tilmaydi
                if ($disabled || count($priorityList) === 1) break;
            }
        }
        return false;
    }

    private function solvePCaptcha() {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            Display::info("pCaptcha", "urinish $attempt/3");
            $r = $this->curl(HOST . "generate_pcaptcha.php", $this->hdr(true));
            $data = json_decode($r[1], true);
            if (!$data || empty($data["image"])) {
                Display::warn("pCaptcha generate fail");
                sleep(2);
                continue;
            }
            $question = $data["question"] ?? "";
            Display::info("Savol", $question);

            $imgUrl = $data["image"];
            if (strpos($imgUrl, "http") !== 0) {
                $imgUrl = HOST . ltrim($imgUrl, "/");
            }
            $img = $this->curl($imgUrl, $this->hdr())[1];
            if (strlen($img) < 400) {
                Display::warn("pCaptcha image kichik");
                sleep(1);
                continue;
            }

            $boxes = $this->solver->gemini->pCaptcha($question, $img);
            if ($boxes === false) {
                sleep(1);
                continue;
            }
            // sayt 0-8 indekslari, JSON array
            $boxes = array_values(array_map("intval", $boxes));
            sort($boxes);

            $post = http_build_query([
                "action" => "verify_pcaptcha",
                "selected_boxes" => json_encode($boxes),
            ]);
            $raw = $this->curl(HOST . "process.php", $this->hdr(true), $post)[1];
            $j = json_decode($raw, true);
            if (!is_array($j)) {
                Display::warn("pCaptcha verify: not JSON " . substr(trim($raw), 0, 100));
                sleep(1);
                continue;
            }
            if (!empty($j["success"]) && !empty($j["token"])) {
                Display::ok("pCaptcha verified");
                return $j["token"];
            }
            $mes = $j["message"] ?? $j["mes"] ?? $j["error"] ?? "incorrect";
            Display::warn("pCaptcha verify: " . $mes . " (cells=" . implode(",", $boxes) . ")");
            sleep(2);
        }
        return false;
    }

    private function solveIcon($body) {
        $token = "";
        if (preg_match("/_iconcaptcha-token' value='([^']+)'/", $body, $m)) $token = $m[1];
        if (!$token && preg_match('/name=["\']_iconcaptcha-token["\'][^>]*value=["\']([^"\']+)/', $body, $m)) $token = $m[1];
        if (!$token) return false;

        $wid = $this->uuid();
        $ts = (int)(microtime(true) * 1000);
        $payload = base64_encode(json_encode([
            "widgetId" => $wid, "action" => "LOAD", "theme" => "light",
            "token" => $token, "timestamp" => $ts, "initTimestamp" => $ts
        ], JSON_UNESCAPED_SLASHES));
        $h = array_merge($this->hdr(true), ["X-IconCaptcha-Token: $token"]);
        $raw = trim($this->curl(IC_ENDPOINT, $h, "payload=" . urlencode($payload))[1]);
        $data = @json_decode(base64_decode($raw), true);
        if (!$data) $data = @json_decode($raw, true);
        if (!$data || empty($data["challenge"] ?? $data["base64"] ?? null)) return false;
        
        $img = $data["challenge"] ?? $data["base64"];
        $cid = $data["identifier"] ?? $data["challengeId"] ?? "";
        usleep(rand(1800000, 2800000));
        $idx = $this->solver->gemini->iconCaptcha($img);
        if ($idx === false) return false;
        if ($idx < 0 || $idx > 7) return false;
        usleep(rand(800000, 1500000));

        $iconCount = 5;
        foreach ([300, 320, 280, 340, 360, 250, 400] as $w) {
            $x = (int)round(($idx + 0.5) * ($w / $iconCount));
            $y = rand(28, 55);
            $ts = (int)(microtime(true) * 1000);
            $payload = base64_encode(json_encode([
                "widgetId" => $wid, "challengeId" => $cid, "action" => "SELECTION",
                "x" => $x, "y" => $y, "width" => $w, "token" => $token,
                "timestamp" => $ts, "initTimestamp" => $ts
            ], JSON_UNESCAPED_SLASHES));
            $raw = trim($this->curl(IC_ENDPOINT, $h, "payload=" . urlencode($payload))[1]);
            $d = @json_decode(base64_decode($raw), true);
            if (!$d) $d = @json_decode($raw, true);
            if (!empty($d["completed"])) {
                Display::ok("IconCaptcha click ok (i=$idx w=$w x=$x)");
                return [
                    "_iconcaptcha-token" => $token,
                    "ic-rq" => "1", "ic-wid" => $wid, "ic-cid" => $cid, "ic-hp" => ""
                ];
            }
            usleep(300000);
        }
        return false;
    }

    private function tryWithdraw() {
        if ($this->getCfg("auto_withdraw") != "1" && $this->getCfg("auto_withdraw") != 1) return;
        $wallet = trim((string)$this->getCfg("wallet"));
        $min = (float)($this->getCfg("min_withdraw") ?? MIN_WITHDRAW);
        if ($wallet === "" || strlen($wallet) < 20) return;

        $d = $this->dashboard();
        $bal = (float)$d["balance"];
        if ($bal < $min) return;

        Display::title("AUTO WITHDRAW");
        $r = $this->curl(HOST . "withdraw.php", $this->hdr());
        $body = $r[1]; $header = $r[0];
        $ck = $this->cookiesFromHeader($header);
        $csrf = $ck["csrf_cookie_name"] ?? "";
        if (!$csrf && preg_match('/csrf_cookie_name=([^;]+)/', $this->cookie, $m)) $csrf = trim($m[1]);

        $cap = $this->solveCaptchaOnPage($body, HOST . "withdraw.php");
        if (!$cap) return;

        $amount = number_format($bal * 0.999, 9, ".", "");
        if ((float)$amount < $min) $amount = number_format($min, 9, ".", "");

        $postArr = array_merge([
            "action" => "withdraw_toncoin",
            "withdrawal_address" => $wallet,
            "withdrawal_tag" => "",
            "withdrawal_amount" => $amount,
            "withdrawal_2fa_code" => "",
            "captcha_type" => $cap["type"],
            "g-recaptcha-response" => $cap["g"],
            "h-captcha-response" => $cap["hcap"],
            "c_captcha_response" => "",
            "pcaptcha_token" => $cap["pc"],
            "csrf_test_name" => $csrf,
        ], $cap["ic"]);

        $raw = $this->curl(HOST . "process.php", $this->hdr(true), http_build_query($postArr))[1];
        $res = $this->parseJsonMes($raw);
        $msg = is_array($res) ? ($res["mes"] ?? "Withdraw OK") : "Withdraw bajarildi";
        
        Display::ok($msg);
        $this->sendTelegram($this->tgWithdrawMessage($d["username"], $amount, $wallet, $msg));
    }

    private function loop() {
        $fail = 0;
        while (true) {
            $r = $this->curl(HOST . "faucet.php", $this->hdr());
            $body = $r[1]; $header = $r[0];

            if (stripos($body, "Just a moment") !== false) {
                Display::err("Cloudflare blokladi — cookie ni yangilang");
                exit;
            }

            $ck = $this->cookiesFromHeader($header);
            $csrf = $ck["csrf_cookie_name"] ?? "";
            if (!$csrf && preg_match('/csrf_cookie_name=([^;]+)/', $this->cookie, $m)) $csrf = trim($m[1]);

            $ft = "";
            if (preg_match('/_ft=([^;]+)/', $this->cookie, $m)) $ft = $m[1];

            $hashKey = $this->extractHashKey($body);
            if (!$hashKey) {
                Display::err("Hash key topilmadi");
                sleep(5); continue;
            }

            $cap = $this->solveCaptchaOnPage($body, HOST . "faucet.php");
            if (!$cap) {
                $fail++;
                $this->recordFail();
                sleep(8);
                if ($fail >= 5) { 
                    $randomDelay = rand(120, 360);
                    $this->timer(3600 + $randomDelay); 
                    $fail = 0; 
                }
                continue;
            }

            // Captcha uzoq yechilgan bo'lishi mumkin — hash/csrf yangilanadi
            usleep(rand(400000, 900000));
            $r2 = $this->curl(HOST . "faucet.php", $this->hdr());
            $body2 = $r2[1];
            $header2 = $r2[0];
            $freshKey = $this->extractHashKey($body2);
            if ($freshKey) $hashKey = $freshKey;
            $ck2 = $this->cookiesFromHeader($header2);
            if (!empty($ck2["csrf_cookie_name"])) $csrf = $ck2["csrf_cookie_name"];
            $hash = $this->makeHash($hashKey);
            Display::info("Hash", substr($hashKey, 0, 16) . "... (fresh)");
            $postArr = array_merge([
                "action" => "claim_hourly_faucet",
                "hash" => $hash,
                "ft" => $ft,
                "captcha_type" => $cap["type"],
                "g-recaptcha-response" => $cap["g"],
                "h-captcha-response" => $cap["hcap"],
                "c_captcha_response" => "",
                "pcaptcha_token" => $cap["pc"],
                "csrf_test_name" => $csrf,
            ], $cap["ic"]);

            $raw = $this->curl(HOST . "process.php", $this->hdr(true), http_build_query($postArr))[1];
            $claim = $this->parseJsonMes($raw);

            if (is_array($claim) && !empty($claim["ret"])) {
                $fail = 0;
                $msg = $claim["mes"] ?? "Success";
                Display::ok($msg);
                
                $d = $this->dashboard();
                Display::info("Balance", $d["balance"] . " TON");
                Display::info("API Bal", $this->solver->balance());
                Display::line();

                $capNames = [0 => "IconCaptcha", 1 => "reCAPTCHA", 2 => "hCaptcha", 4 => "pCaptcha"];
                $capName = $capNames[$cap["type"]] ?? ("Type #" . $cap["type"]);
                $stats = $this->recordClaim($d["username"], $d["balance"], $capName);
                Display::info("Bugun", $stats["claims_today"] . "/24 claim");
                $this->sendTelegram($this->tgClaimMessage($d["username"], $d["balance"], $msg, $capName, $stats));

                $this->tryWithdraw();

                $randomDelay = rand(120, 360);
                $totalWait = 3600 + $randomDelay;
                Display::info("Anti-Ban", "Kechikish qo'shildi: " . round($randomDelay / 60, 1) . " minut");
                $this->timer($totalWait);
                continue;
            }

            $msg = is_array($claim) ? ($claim["mes"] ?? "") : "";
            if ($msg === "") $msg = substr(preg_replace('/\s+/', " ", strip_tags($raw)), 0, 180);
            Display::err($msg !== "" ? $msg : "Noma'lum javob");
            Display::line();

            $waitSec = $this->parseWaitSeconds($msg);
            if ($waitSec > 0) {
                // cooldown — captcha fail emas
                Display::info("Kutish", $waitSec . "s (captcha qayta yechilmaydi)");
                $this->timer($waitSec + rand(15, 45));
                continue;
            }

            // faqat haqiqiy captcha/claim xatolari
            if (stripos($msg, "captcha") !== false || stripos($msg, "invalid") !== false
                || stripos($msg, "wrong") !== false || stripos($msg, "failed") !== false) {
                $this->recordFail();
            }

            $fail++;
            sleep(10);
            if ($fail >= 5) { 
                $this->timer(3600 + rand(120, 360)); 
                $fail = 0; 
            }
        }
    }
}

new Bot();