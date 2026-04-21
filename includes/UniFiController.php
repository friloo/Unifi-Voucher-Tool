<?php
class UniFiController {
    private $controllerUrl;
    private $username;
    private $password;
    private $siteId;
    private $cookieFile;
    private $csrfToken = null;

    public function __construct($controllerUrl, $username, $password, $siteId) {
        $this->controllerUrl = rtrim($controllerUrl, '/');
        $this->username = $username;
        $this->password = $password;
        $this->siteId = $siteId;
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'UNIFI_');
    }
    
    public function __destruct() {
        if (file_exists($this->cookieFile)) {
            unlink($this->cookieFile);
        }
    }
    
    // Login zum Controller
    private function login() {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->controllerUrl . "/api/auth/login",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'username' => $this->username,
                'password' => $this->password
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_HEADERFUNCTION => function($ch, $header) {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $name  = trim($parts[0]);
                    $value = trim($parts[1]);
                    if (strtolower($name) === 'x-csrf-token') {
                        $this->csrfToken = $value;
                    }
                }
                return strlen($header);
            }
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Login fehlgeschlagen: HTTP $httpCode");
        }

        $data = json_decode($response, true);

        // Expliziter Fehler vom Controller abfangen (meta.rc = error)
        if (isset($data['meta']['rc']) && $data['meta']['rc'] !== 'ok') {
            $msg = $data['meta']['msg'] ?? 'Zugangsdaten abgelehnt';
            throw new Exception("Login fehlgeschlagen: $msg");
        }

        // HTTP 200 ohne expliziten Fehler = Erfolg
        // UniFi OS liefert X-CSRF-Token im Response-Header (bereits via CURLOPT_HEADERFUNCTION extrahiert)
        // Fallback: TOKEN-Cookie aus der Cookie-Datei lesen
        if ($this->csrfToken === null) {
            $this->csrfToken = $this->readCsrfFromCookieFile();
        }

        return true;
    }
    
    // API-Request ausführen
    private function apiRequest($endpoint, $data = null, $method = 'POST') {
        $this->login();
        
        $ch = curl_init();
        $url = $this->controllerUrl . $endpoint;
        
        $headers = ['Content-Type: application/json'];
        if ($method !== 'GET' && $this->csrfToken !== null) {
            $headers[] = 'X-CSRF-Token: ' . $this->csrfToken;
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headers
        ];

        if ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } elseif ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($data ?? (object)[]);
        }
        
        curl_setopt_array($ch, $options);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("cURL Fehler: $error");
        }
        
        if ($httpCode !== 200) {
            throw new Exception("API Request fehlgeschlagen: HTTP $httpCode");
        }
        
        return json_decode($response, true);
    }
    
    // Voucher erstellen
    public function createVoucher($voucherName, $maxUses, $expireMinutes = 480) {
        $data = [
            'cmd' => 'create-voucher',
            'expire' => (int)$expireMinutes,
            'n' => 1,
            'note' => $voucherName,
            'quota' => (int)$maxUses
        ];
        
        $response = $this->apiRequest("/proxy/network/api/s/{$this->siteId}/cmd/hotspot", $data);

        if (!isset($response['data'][0]['create_time'])) {
            throw new Exception("Voucher konnte nicht erstellt werden");
        }
        
        // Voucher-Code abrufen
        $vouchers = $this->getVouchers();
        
        if (empty($vouchers)) {
            throw new Exception("Voucher-Code konnte nicht abgerufen werden");
        }
        
        // Neuesten Voucher zurückgeben
        $latestVoucher = reset($vouchers);
        
        return [
            'code' => $latestVoucher['code'],
            'formatted_code' => $this->formatVoucherCode($latestVoucher['code']),
            'unifi_id' => $latestVoucher['_id'] ?? null,
            'create_time' => $latestVoucher['create_time'] ?? null
        ];
    }
    
    // Alle Voucher abrufen
    public function getVouchers() {
        $response = $this->apiRequest("/proxy/network/api/s/{$this->siteId}/stat/voucher", null, 'GET');
        return $response['data'] ?? [];
    }

    // Voucher mit formatierten Details abrufen
    public function getVouchersWithDetails() {
        $vouchers = $this->getVouchers();
        $result = [];

        foreach ($vouchers as $voucher) {
            $createTime = isset($voucher['create_time']) ? $voucher['create_time'] : 0;
            $duration = isset($voucher['duration']) ? $voucher['duration'] : 0; // in Minuten
            $expireTime = $createTime + ($duration * 60);
            $now = time();

            // Status bestimmen
            $status = 'valid';
            $usedCount = isset($voucher['used']) ? $voucher['used'] : 0;
            $quota = isset($voucher['quota']) ? $voucher['quota'] : 0;

            if ($now > $expireTime) {
                $status = 'expired';
            } elseif ($quota > 0 && $usedCount >= $quota) {
                $status = 'used';
            }

            $result[] = [
                '_id' => $voucher['_id'] ?? '',
                'code' => $voucher['code'] ?? '',
                'formatted_code' => $this->formatVoucherCode($voucher['code'] ?? ''),
                'note' => $voucher['note'] ?? '',
                'quota' => $quota,
                'used' => $usedCount,
                'duration' => $duration,
                'create_time' => $createTime,
                'expire_time' => $expireTime,
                'status' => $status,
                'status_expires' => isset($voucher['status_expires']) ? $voucher['status_expires'] : null,
                'for_hotspot' => isset($voucher['for_hotspot']) ? $voucher['for_hotspot'] : false,
                'qos_overwrite' => isset($voucher['qos_overwrite']) ? $voucher['qos_overwrite'] : false,
                'qos_usage_quota' => isset($voucher['qos_usage_quota']) ? $voucher['qos_usage_quota'] : null,
                'qos_rate_max_up' => isset($voucher['qos_rate_max_up']) ? $voucher['qos_rate_max_up'] : null,
                'qos_rate_max_down' => isset($voucher['qos_rate_max_down']) ? $voucher['qos_rate_max_down'] : null
            ];
        }

        // Nach Erstellungsdatum sortieren (neueste zuerst)
        usort($result, function($a, $b) {
            return $b['create_time'] - $a['create_time'];
        });

        return $result;
    }
    
    // CSRF-Token aus Netscape-Cookie-Datei lesen (Fallback wenn Header nicht gesetzt)
    private function readCsrfFromCookieFile() {
        if (!file_exists($this->cookieFile)) {
            return null;
        }
        foreach (file($this->cookieFile) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $parts = explode("\t", $line);
            if (count($parts) >= 7 && strtoupper($parts[5]) === 'TOKEN') {
                return $parts[6];
            }
        }
        return null;
    }

    // Voucher-Code formatieren (xxxxx-xxxxx-xxxxx)
    private function formatVoucherCode($code) {
        $chunks = str_split($code, 5);
        return implode('-', $chunks);
    }
    
    // Voucher löschen
    public function deleteVoucher($voucherId) {
        $data = [
            'cmd' => 'delete-voucher',
            '_id' => $voucherId
        ];
        
        $response = $this->apiRequest("/proxy/network/api/s/{$this->siteId}/cmd/hotspot", $data);

        return isset($response['meta']['rc']) && $response['meta']['rc'] === 'ok';
    }
    
    // Verbindung testen
    public static function testConnection($controllerUrl, $username, $password, $siteId) {
        try {
            $controller = new self($controllerUrl, $username, $password, $siteId);
            $controller->login();
            return true;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * Synchronisiert alle Voucher vom UniFi Controller in die Datenbank
     * @param Database $db Datenbank-Instanz
     * @param int $dbSiteId Die Site-ID in der lokalen Datenbank
     * @return array Statistiken über die Synchronisation
     */
    public function syncVouchersToDatabase($db, $dbSiteId) {
        $vouchers = $this->getVouchersWithDetails();
        $stats = [
            'total' => count($vouchers),
            'new' => 0,
            'updated' => 0,
            'deleted' => 0,
            'valid' => 0,
            'used' => 0,
            'expired' => 0
        ];

        // Alle aktuellen UniFi-IDs sammeln
        $unifiIds = [];

        foreach ($vouchers as $voucher) {
            $unifiIds[] = $voucher['_id'];

            // Status zählen
            $stats[$voucher['status']]++;

            // Prüfen ob Voucher bereits existiert
            $existing = $db->fetchOne(
                "SELECT id, status, used_count FROM vouchers WHERE unifi_voucher_id = ? AND site_id = ?",
                [$voucher['_id'], $dbSiteId]
            );

            $expiresAt = date('Y-m-d H:i:s', $voucher['expire_time']);
            $createdAt = date('Y-m-d H:i:s', $voucher['create_time']);

            if ($existing) {
                // Voucher aktualisieren
                $db->execute(
                    "UPDATE vouchers SET
                        status = ?,
                        used_count = ?,
                        expires_at = ?,
                        last_sync = NOW()
                     WHERE id = ?",
                    [$voucher['status'], $voucher['used'], $expiresAt, $existing['id']]
                );
                $stats['updated']++;
            } else {
                // Neuen Voucher einfügen
                $db->execute(
                    "INSERT INTO vouchers
                        (site_id, voucher_code, voucher_name, max_uses, expire_minutes,
                         unifi_voucher_id, status, used_count, expires_at, created_at,
                         synced_from_unifi, last_sync)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())",
                    [
                        $dbSiteId,
                        $voucher['formatted_code'],
                        $voucher['note'] ?: 'Importiert aus UniFi',
                        $voucher['quota'],
                        $voucher['duration'],
                        $voucher['_id'],
                        $voucher['status'],
                        $voucher['used'],
                        $expiresAt,
                        $createdAt
                    ]
                );
                $stats['new']++;
            }
        }

        // Voucher die nicht mehr im Controller existieren als gelöscht markieren
        if (!empty($unifiIds)) {
            $placeholders = implode(',', array_fill(0, count($unifiIds), '?'));
            $params = array_merge($unifiIds, [$dbSiteId]);

            // Alle Voucher mit UniFi-ID die nicht mehr existieren auf "expired" setzen
            $deleted = $db->execute(
                "UPDATE vouchers SET status = 'expired', last_sync = NOW()
                 WHERE unifi_voucher_id IS NOT NULL
                 AND unifi_voucher_id NOT IN ($placeholders)
                 AND site_id = ?
                 AND status != 'expired'",
                $params
            );
            $stats['deleted'] = $deleted;
        }

        return $stats;
    }

    /**
     * Holt Live-Statistiken für das Dashboard
     * @return array
     */
    public function getLiveStats() {
        $vouchers = $this->getVouchersWithDetails();

        $stats = [
            'total' => count($vouchers),
            'valid' => 0,
            'used' => 0,
            'expired' => 0,
            'vouchers' => $vouchers
        ];

        foreach ($vouchers as $voucher) {
            $stats[$voucher['status']]++;
        }

        return $stats;
    }
}