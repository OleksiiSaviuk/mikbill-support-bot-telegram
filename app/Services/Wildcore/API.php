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

        $candidates = $this->searchCandidatesByClientMac($normalizedMac);

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, function (array $a, array $b) {
            $priorityCompare = ($b['_priority'] ?? 0) <=> ($a['_priority'] ?? 0);
            if ($priorityCompare !== 0) {
                return $priorityCompare;
            }

            return 0;
        });

        $selected = $candidates[0];
        $selected['matches_count'] = count($candidates);
        unset($selected['_priority']);

        return $selected;
    }

    public function get_connection_by_interface_and_client_mac($interfaceId, $clientMac, string $diagSource = 'cache'): ?array
    {
        $normalizedMac = $this->normalize_mac((string)$clientMac);
        $interfaceId = trim((string)$interfaceId);

        if ($normalizedMac === null || $interfaceId === '') {
            return null;
        }

        $candidates = $this->searchCandidatesByClientMac($normalizedMac);

        if (empty($candidates)) {
            return null;
        }

        $selected = null;
        foreach ($candidates as $candidate) {
            if ((string)($candidate['interface_id'] ?? '') === $interfaceId) {
                $selected = $candidate;
                break;
            }
        }

        if ($selected === null) {
            return null;
        }

        $selected['matches_count'] = count($candidates);
        unset($selected['_priority']);

        if (($selected['connection_type'] ?? 'unknown') !== 'onu') {
            return [
                'connection_type' => (string)$selected['connection_type'],
                'matches_count' => (int)($selected['matches_count'] ?? 1),
                'interface_id' => $selected['interface_id'] ?? null,
                'interface_name' => $selected['name'] ?? null,
                'interface_type' => $selected['interface_type'] ?? null,
                'status' => $selected['status'] ?? null,
                'description' => $selected['description'] ?? null,
                'client_mac' => $normalizedMac,
                'device_name' => $selected['device_name'] ?? null,
                'device_ip' => $selected['device_ip'] ?? null,
                'device_description' => $selected['device_description'] ?? null,
                'device_vendor' => $selected['device_vendor'] ?? null,
                'device_model' => $selected['device_model'] ?? null,
                'device_model_type' => $selected['device_model_type'] ?? null,
                'parent_bind_key' => $selected['parent_bind_key'] ?? null,
                'bind_key' => $selected['bind_key'] ?? null,
            ];
        }

        $diag = $this->get_onu_diagnostic($interfaceId, $diagSource === 'device' ? 'device' : 'cache');

        if (!is_array($diag)) {
            return null;
        }

        return $this->parse_onu_info($selected, $diag, $normalizedMac);
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
        $rowDiag = $this->pickFirstRow($diag);
        $diagRoot = is_array($rowDiag) ? $rowDiag : $diag;

        $status = strtolower((string)$this->firstNotEmpty([
            $this->stringOrNull($this->getByPath($diagRoot, ['status', 'online'])),
            $this->stringOrNull($this->findByKeyRecursive($diagRoot, ['onu_status', 'oper_status'])),
            $this->stringOrNull($search['status'] ?? null),
        ]));

        $adminStatus = $this->stringOrNull($this->firstNotEmpty([
            $this->getByPath($diagRoot, ['status', 'admin']),
            $this->findByKeyRecursive($diagRoot, ['admin_status', 'admin_state']),
            $search['admin_status'] ?? null,
        ]));

        $onuIdent = $this->stringOrNull($this->firstNotEmpty([
            $this->getByPath($diagRoot, ['ident', 'value']),
            $this->findByKeyRecursive($diagRoot, ['onu_ident', 'serial', 'serial_number', 'sn']),
            $search['onu_ident'] ?? null,
        ]));

        $description = $this->stringOrNull($this->firstNotEmpty([
            $search['interface_description'] ?? null,
            $search['interface_comment'] ?? null,
            $this->getByPath($diag, ['data', 'iface', 'description']),
            $search['device_description'] ?? null,
        ]));

        $vendor = $this->stringOrNull($this->firstNotEmpty([
            $this->findByKeyRecursive($diagRoot, ['vendor', 'onu_vendor']),
            $search['device_vendor'] ?? null,
        ]));

        $model = $this->stringOrNull($this->firstNotEmpty([
            $this->findByKeyRecursive($diagRoot, ['model', 'onu_model']),
            $search['device_model'] ?? null,
        ]));

        $uniPorts = $this->normalizeUniPorts($this->firstNotEmpty([
            $this->findByKeyRecursive($diagRoot, ['uni_ports', 'uni', 'uni_status']),
            $search['uni_ports'] ?? null,
        ]));

        $fdbMacs = $this->extractMacList($this->findByKeyRecursive($diagRoot, ['fdb_macs', 'fdb', 'mac_table', 'client_macs']));
        $clientMacFoundInFdb = null;

        if (!empty($normalizedClientMac)) {
            $clientMacFoundInFdb = in_array($normalizedClientMac, $fdbMacs, true);
        }

        $onu = [
            'interface_id' => (string)$search['interface_id'],
            'interface_name' => $search['name'] ?? ($search['interface_name'] ?? null),
            'olt_name' => $search['device_name'] ?? null,
            'olt_ip' => $search['device_ip'] ?? null,
            'interface_type' => $search['interface_type'] ?? null,
            'connection_type' => 'onu',
            'matches_count' => (int)($search['matches_count'] ?? 1),

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
                $search['vlan'] ?? null,
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

        return $this->get_connection_by_interface_and_client_mac($search['interface_id'], $normalizedMac, 'cache');
    }

    private function searchCandidatesByClientMac(string $normalizedMac): array
    {
        $activeResponse = $this->wildcore_request('GET', '/api/v1/device-interface/search', [
            'mac_address' => $normalizedMac,
            'only_active_mac' => 1,
        ]);

        $rows = [];

        if (is_array($activeResponse) && $this->hasAnyRows($activeResponse)) {
            $rows = $this->extractRows($activeResponse);
        } else {
            $fallbackResponse = $this->wildcore_request('GET', '/api/v1/device-interface/search', [
                'mac_address' => $normalizedMac,
            ]);

            if (is_array($fallbackResponse) && $this->hasAnyRows($fallbackResponse)) {
                $rows = $this->extractRows($fallbackResponse);
            }
        }

        if (empty($rows)) {
            return [];
        }

        $candidates = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $candidate = $this->normalizeCandidate($row, $normalizedMac);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    private function normalizeCandidate(array $row, string $normalizedMac): ?array
    {
        $interfaceId = $this->stringOrNull($this->firstNotEmpty([
            $row['interface_id'] ?? null,
            $row['id'] ?? null,
            $row['bind_key'] ?? null,
        ]));

        if ($interfaceId === null) {
            return null;
        }

        $interfaceType = $this->stringOrNull($row['type'] ?? null);
        $interfaceName = $this->stringOrNull($this->firstNotEmpty([
            $row['name'] ?? null,
            $row['interface_name'] ?? null,
        ]));

        $deviceName = $this->stringOrNull($this->getByPath($row, ['device', 'name']));
        $deviceIp = $this->stringOrNull($this->getByPath($row, ['device', 'ip']));
        $deviceDescription = $this->stringOrNull($this->getByPath($row, ['device', 'description']));
        $deviceVendor = $this->stringOrNull($this->getByPath($row, ['device', 'model', 'vendor']));
        $deviceModel = $this->stringOrNull($this->getByPath($row, ['device', 'model', 'model']));
        $deviceModelType = $this->stringOrNull($this->getByPath($row, ['device', 'model', 'type']));

        $connectionType = $this->detectConnectionType($interfaceType, $interfaceName, $deviceModelType);

        return [
            'interface_id' => $interfaceId,
            'connection_type' => $connectionType,
            '_priority' => $this->connectionPriority($connectionType),
            'interface_type' => $interfaceType,
            'name' => $interfaceName,
            'status' => $this->stringOrNull($row['status'] ?? null),
            'description' => $this->stringOrNull($this->firstNotEmpty([
                $row['description'] ?? null,
                $row['comment'] ?? null,
                $deviceDescription,
            ])),
            'interface_description' => $this->stringOrNull($row['description'] ?? null),
            'interface_comment' => $this->stringOrNull($row['comment'] ?? null),
            'device_name' => $deviceName,
            'device_ip' => $deviceIp,
            'device_description' => $deviceDescription,
            'device_vendor' => $deviceVendor,
            'device_model' => $deviceModel,
            'device_model_type' => $deviceModelType,
            'parent_bind_key' => $this->stringOrNull($row['parent_bind_key'] ?? null),
            'bind_key' => $this->stringOrNull($row['bind_key'] ?? null),
            'client_mac' => $normalizedMac,
            '_raw' => $row,
        ];
    }

    private function detectConnectionType(?string $type, ?string $name, ?string $deviceModelType = null): string
    {
        $typeLower = strtolower((string)$type);
        $nameLower = strtolower((string)$name);

        if (in_array(strtoupper((string)$type), ['ONU', 'ONT', 'PON_ONU'], true)
            || preg_match('/\b(onu|ont|gpon|epon)\b/i', $typeLower)
            || preg_match('/\b(onu|ont|gpon|epon)\b/i', $nameLower)) {
            return 'onu';
        }

        $knownSwitchTypes = ['FE', 'GE', 'GI', 'FA', 'TE', 'XGE', 'SFP', 'ETH', 'PORT', 'ACCESS'];
        if (in_array(strtoupper((string)$type), $knownSwitchTypes, true)
            || strpos($typeLower, 'ethernet') !== false
            || strpos($typeLower, 'switch') !== false
            || strpos($typeLower, 'access') !== false
            || preg_match('/\b(gi|ge|eth|fa|te|fe)\S*/i', $nameLower)
            || strtoupper((string)$deviceModelType) === 'SWITCH') {
            return 'switch_port';
        }

        return 'unknown';
    }

    private function connectionPriority(string $type): int
    {
        if ($type === 'onu') {
            return 3;
        }

        if ($type === 'switch_port') {
            return 2;
        }

        return 1;
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

    private function getByPath(array $payload, array $path)
    {
        $cursor = $payload;

        foreach ($path as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    private function extractRows(array $payload): array
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            if ($this->isAssoc($payload['data'])) {
                return [$payload['data']];
            }

            return $payload['data'];
        }

        if ($this->isAssoc($payload)) {
            return [$payload];
        }

        return $payload;
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
