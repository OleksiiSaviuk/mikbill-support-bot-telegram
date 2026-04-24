<?php

namespace App\Services\Wildcore;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class API
{
    /**
     * Wildcore is enabled only when switch is true and credentials are present.
     */
    public static function wildcore_enabled(): bool
    {
        $enabledValue = config('services.wildcore.enabled', false);
        $enabled = filter_var($enabledValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $url = trim((string)config('services.wildcore.url', ''));
        $apiKey = trim((string)config('services.wildcore.api_key', ''));

        return $enabled === true && $url !== '' && $apiKey !== '';
    }

    public function normalize_mac($mac): ?string
    {
        if (!is_string($mac) || trim($mac) === '') {
            return null;
        }

        $hex = preg_replace('/[^0-9a-f]/i', '', $mac);

        if (!is_string($hex) || strlen($hex) !== 12) {
            return null;
        }

        $hex = strtoupper($hex);

        return implode(':', str_split($hex, 2));
    }

    public function wildcore_request(string $method, string $path, ?array $params = null): ?array
    {
        if (!self::wildcore_enabled()) {
            return null;
        }

        $baseUrl = rtrim((string)config('services.wildcore.url', ''), '/');
        $apiKey = (string)config('services.wildcore.api_key', '');

        if ($baseUrl === '' || $apiKey === '') {
            return null;
        }

        $client = new Client([
            'base_uri' => $baseUrl,
            'timeout' => 10,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => false,
        ]);

        $attempts = 3;
        $options = [
            'headers' => [
                'X-Auth-Key' => $apiKey,
                'Accept' => 'application/json',
            ],
        ];

        if (!empty($params)) {
            $options['query'] = $params;
        }

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $response = $client->request(strtoupper($method), $path, $options);
                $statusCode = (int)$response->getStatusCode();

                if ($statusCode < 200 || $statusCode >= 300) {
                    if ($i === $attempts) {
                        Log::warning('Wildcore request failed with non-2xx status', [
                            'status' => $statusCode,
                            'path' => $path,
                            'attempt' => $i,
                        ]);
                    }

                    continue;
                }

                $body = (string)$response->getBody();
                $decoded = json_decode($body, true);

                return is_array($decoded) ? $decoded : null;
            } catch (\Throwable $e) {
                if ($i === $attempts) {
                    Log::error('Wildcore request exception', [
                        'path' => $path,
                        'attempt' => $i,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }

        return null;
    }

    public function search_onu_by_client_mac($mac): ?array
    {
        $normalizedMac = $this->normalize_mac((string)$mac);

        if (empty($normalizedMac)) {
            return null;
        }

        $response = $this->wildcore_request('GET', '/api/v1/device-interface/search', [
            'mac_address' => $normalizedMac,
            'only_active_mac' => 1,
        ]);

        // Fallback for offline ONU: search again without active-MAC restriction.
        if (!is_array($response) || !$this->hasAnyRows($response)) {
            $response = $this->wildcore_request('GET', '/api/v1/device-interface/search', [
                'mac_address' => $normalizedMac,
            ]);
        }

        if (!is_array($response)) {
            return null;
        }

        $row = $this->pickFirstRow($response);

        if (!is_array($row)) {
            return null;
        }

        $interfaceId = $this->firstNotEmpty([
            $row['interface_id'] ?? null,
            $row['id'] ?? null,
            $this->findByKeyRecursive($row, ['interface_id', 'id']),
        ]);

        if (empty($interfaceId)) {
            return null;
        }

        return [
            'interface_id' => (string)$interfaceId,
            'interface_name' => $this->stringOrNull($this->firstNotEmpty([
                $row['interface_name'] ?? null,
                $row['name'] ?? null,
                $this->findByKeyRecursive($row, ['interface_name', 'name', 'port_name']),
            ])),
            'olt_name' => $this->stringOrNull($this->firstNotEmpty([
                $row['olt_name'] ?? null,
                $this->findByKeyRecursive($row, ['olt_name', 'device_name', 'host_name']),
            ])),
            'olt_ip' => $this->stringOrNull($this->firstNotEmpty([
                $row['olt_ip'] ?? null,
                $this->findByKeyRecursive($row, ['olt_ip', 'ip', 'host_ip']),
            ])),
            '_raw' => $row,
        ];
    }

    public function get_onu_diagnostic($interface_id, string $source = 'cache'): ?array
    {
        if (empty($interface_id)) {
            return null;
        }

        $source = $source === 'device' ? 'device' : 'cache';

        return $this->wildcore_request(
            'GET',
            '/api/v1/component/diagnostic/interface/' . rawurlencode((string)$interface_id) . '/diag',
            ['from' => $source]
        );
    }

    public function parse_onu_info(array $search, array $diag, string $client_mac): array
    {
        $normalizedClientMac = $this->normalize_mac($client_mac);
        $rawSearch = isset($search['_raw']) && is_array($search['_raw']) ? $search['_raw'] : $search;
        $rowDiag = $this->pickFirstRow($diag);
        $diagRoot = is_array($rowDiag) ? $rowDiag : $diag;

        $status = strtolower((string)$this->firstNotEmpty([
            $this->findByKeyRecursive($diagRoot, ['status', 'onu_status', 'oper_status', 'state']),
            $this->findByKeyRecursive($rawSearch, ['status', 'state']),
        ]));

        $adminStatus = $this->stringOrNull($this->firstNotEmpty([
            $this->findByKeyRecursive($diagRoot, ['admin_status', 'admin_state']),
            $this->findByKeyRecursive($rawSearch, ['admin_status', 'admin_state']),
        ]));

        $onuIdent = $this->stringOrNull($this->firstNotEmpty([
            $this->findByKeyRecursive($diagRoot, ['onu_ident', 'serial', 'serial_number', 'sn']),
            $this->findByKeyRecursive($rawSearch, ['onu_ident', 'serial', 'serial_number', 'sn']),
        ]));

        $description = $this->stringOrNull($this->firstNotEmpty([
            $this->findByKeyRecursive($diagRoot, ['onu_description', 'description', 'desc', 'comment']),
            $this->findByKeyRecursive($rawSearch, ['onu_description', 'description', 'desc', 'comment']),
        ]));

        $vendor = $this->stringOrNull($this->firstNotEmpty([
            $this->findByKeyRecursive($diagRoot, ['vendor', 'onu_vendor']),
            $this->findByKeyRecursive($rawSearch, ['vendor', 'onu_vendor']),
        ]));

        $model = $this->stringOrNull($this->firstNotEmpty([
            $this->findByKeyRecursive($diagRoot, ['model', 'onu_model']),
            $this->findByKeyRecursive($rawSearch, ['model', 'onu_model']),
        ]));

        $uniPorts = $this->normalizeUniPorts($this->firstNotEmpty([
            $this->findByKeyRecursive($diagRoot, ['uni_ports', 'uni', 'uni_status']),
            $this->findByKeyRecursive($rawSearch, ['uni_ports', 'uni', 'uni_status']),
        ]));

        $fdbMacs = $this->extractMacList($this->findByKeyRecursive($diagRoot, ['fdb_macs', 'fdb', 'mac_table', 'client_macs']));
        $clientMacFoundInFdb = null;

        if (!empty($normalizedClientMac)) {
            $clientMacFoundInFdb = in_array($normalizedClientMac, $fdbMacs, true);
        }

        $onu = [
            'interface_id' => (string)$search['interface_id'],
            'interface_name' => $search['interface_name'] ?? null,
            'olt_name' => $search['olt_name'] ?? null,
            'olt_ip' => $search['olt_ip'] ?? null,

            'status' => $status !== '' ? $status : null,
            'admin_status' => $adminStatus,

            'onu_ident' => $onuIdent,
            'description' => $description,
            'vendor' => $vendor,
            'model' => $model,

            'rx' => $this->toFloatOrNull($this->findByKeyRecursive($diagRoot, ['rx', 'onu_rx', 'rx_power'])),
            'tx' => $this->toFloatOrNull($this->findByKeyRecursive($diagRoot, ['tx', 'onu_tx', 'tx_power'])),
            'olt_rx' => $this->toFloatOrNull($this->findByKeyRecursive($diagRoot, ['olt_rx', 'rx_olt', 'olt_rx_power'])),
            'temperature' => $this->toFloatOrNull($this->findByKeyRecursive($diagRoot, ['temperature', 'temp'])),
            'voltage' => $this->toFloatOrNull($this->findByKeyRecursive($diagRoot, ['voltage', 'volt'])),

            'last_reg' => $this->stringOrNull($this->findByKeyRecursive($diagRoot, ['last_reg', 'last_register', 'last_registration'])),
            'last_dereg' => $this->stringOrNull($this->findByKeyRecursive($diagRoot, ['last_dereg', 'last_deregister', 'last_down', 'last_down_time', 'last_down_at', 'down_time', 'deregister_date'])),
            'last_down_reason' => $this->stringOrNull($this->findByKeyRecursive($diagRoot, ['last_down_reason', 'down_reason', 'dereg_reason'])),

            'uni_ports' => $uniPorts,
            'client_mac_found_in_fdb' => $clientMacFoundInFdb,
            'vlan' => $this->stringOrNull($this->firstNotEmpty([
                $this->findByKeyRecursive($diagRoot, ['vlan', 'client_vlan']),
                $this->findByKeyRecursive($rawSearch, ['vlan', 'client_vlan']),
            ])),
            'client_mac' => $normalizedClientMac,
        ];

        $onu['summary'] = $this->build_onu_diagnostic_summary($onu);

        return $onu;
    }

    public function get_onu_by_client_mac($mac): ?array
    {
        if (!self::wildcore_enabled()) {
            return null;
        }

        $normalizedMac = $this->normalize_mac((string)$mac);

        if (empty($normalizedMac)) {
            return null;
        }

        $search = $this->search_onu_by_client_mac($normalizedMac);

        if (empty($search) || empty($search['interface_id'])) {
            return null;
        }

        $diag = $this->get_onu_diagnostic($search['interface_id'], 'cache');

        if (!is_array($diag)) {
            return null;
        }

        return $this->parse_onu_info($search, $diag, $normalizedMac);
    }

    public function build_onu_diagnostic_summary(array $onu): string
    {
        $parts = [];
        $status = strtolower((string)($onu['status'] ?? ''));
        $rx = isset($onu['rx']) ? (float)$onu['rx'] : null;

        if ($status !== 'online') {
            $parts[] = trans('onu_summary_offline');
        } else {
            if ($rx !== null) {
                if ($rx <= -27) {
                    $parts[] = trans('onu_summary_signal_very_weak');
                } elseif ($rx <= -25) {
                    $parts[] = trans('onu_summary_signal_weak');
                } elseif ($rx < -8) {
                    $parts[] = trans('onu_summary_signal_ok');
                } else {
                    $parts[] = trans('onu_summary_signal_too_strong');
                }
            } else {
                $parts[] = trans('onu_summary_no_rx_data');
            }
        }

        $downReason = strtolower((string)($onu['last_down_reason'] ?? ''));
        if (strpos($downReason, 'dying-gasp') !== false || strpos($downReason, 'dying gasp') !== false) {
            $parts[] = trans('onu_summary_dying_gasp');
        }

        $uniText = strtolower((string)($onu['uni_ports'] ?? ''));
        if ($uniText !== '' && (strpos($uniText, 'down') !== false || strpos($uniText, 'inactive') !== false)) {
            $parts[] = trans('onu_summary_lan_inactive');
        }

        if (array_key_exists('client_mac_found_in_fdb', $onu) && $onu['client_mac_found_in_fdb'] === false) {
            $parts[] = trans('onu_summary_client_not_connected');
        }

        return implode('; ', $parts);
    }

    private function pickFirstRow(array $payload): ?array
    {
        if (isset($payload['data'][0]) && is_array($payload['data'][0])) {
            return $payload['data'][0];
        }

        if (isset($payload['data']) && is_array($payload['data']) && $this->isAssoc($payload['data'])) {
            return $payload['data'];
        }

        if (isset($payload[0]) && is_array($payload[0])) {
            return $payload[0];
        }

        if ($this->isAssoc($payload)) {
            return $payload;
        }

        return null;
    }

    private function hasAnyRows(array $payload): bool
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            return !empty($payload['data']);
        }

        return !empty($payload);
    }

    private function normalizeUniPorts($value): ?string
    {
        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $row) {
                if (is_scalar($row)) {
                    $normalized[] = (string)$key . ':' . (string)$row;
                    continue;
                }

                if (is_array($row)) {
                    $port = $row['port'] ?? $row['name'] ?? $key;
                    $status = $row['status'] ?? $row['state'] ?? null;
                    if ($status !== null) {
                        $normalized[] = (string)$port . ':' . (string)$status;
                    }
                }
            }

            return empty($normalized) ? null : implode(', ', $normalized);
        }

        if (is_scalar($value)) {
            $text = trim((string)$value);
            return $text === '' ? null : $text;
        }

        return null;
    }

    private function extractMacList($value): array
    {
        $macs = [];

        if (is_array($value)) {
            array_walk_recursive($value, function ($item) use (&$macs) {
                if (is_string($item)) {
                    $normalized = $this->normalize_mac($item);
                    if (!empty($normalized)) {
                        $macs[$normalized] = true;
                    }
                }
            });
        } elseif (is_string($value)) {
            $normalized = $this->normalize_mac($value);
            if (!empty($normalized)) {
                $macs[$normalized] = true;
            }
        }

        return array_keys($macs);
    }

    private function findByKeyRecursive($payload, array $keys)
    {
        if (!is_array($payload)) {
            return null;
        }

        $keysLookup = [];
        foreach ($keys as $key) {
            $keysLookup[strtolower($key)] = true;
        }

        foreach ($payload as $key => $value) {
            if (isset($keysLookup[strtolower((string)$key)])) {
                return $value;
            }

            if (is_array($value)) {
                $found = $this->findByKeyRecursive($value, $keys);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function firstNotEmpty(array $values)
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            return $value;
        }

        return null;
    }

    private function stringOrNull($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            $text = trim((string)$value);
            return $text === '' ? null : $text;
        }

        return null;
    }

    private function toFloatOrNull($value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (float)$value;
        }

        if (is_string($value)) {
            $clean = str_replace(',', '.', trim($value));
            if (is_numeric($clean)) {
                return (float)$clean;
            }
        }

        return null;
    }

    private function isAssoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
