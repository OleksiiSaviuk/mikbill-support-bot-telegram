<?php


namespace App\Services\MikBill\Admin;


use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Kagatan\MikBillAdminAPI\AdminAPI;

class API extends AdminAPI
{
    private $host = 'https://admin2x.loc';
    private $login = 'admin';
    private $pass = 'admin';

    public function __construct()
    {
        $this->host  = config('services.mikbill.host');
        $this->login = config('services.mikbill.login');
        $this->pass  = config('services.mikbill.pass');

        // Bypass parent constructor — we control auth and client ourselves.
        $this->client = new Client([
            'base_uri'        => $this->host,
            'cookies'         => true,
            'verify'          => false,
            'connect_timeout' => 5,
            'timeout'         => 15,
        ]);

        $cachedToken = Cache::get('mikbill_jwt_token');

        if ($cachedToken) {
            $ref = new \ReflectionProperty(\Kagatan\MikBillAdminAPI\AdminAPI::class, '_token');
            $ref->setAccessible(true);
            $ref->setValue($this, $cachedToken);
        } else {
            $this->_authenticate();
        }
    }

    private function _authenticate(): void
    {
        $params = [
            'login'    => $this->login,
            'password' => md5($this->pass),
        ];

        $response = $this->authBilling($params);

        if (isset($response['data']['jwt'])) {
            Cache::put('mikbill_jwt_token', $response['data']['jwt'], now()->addMinutes(55));
        }
    }

    /**
     * Поиск абонента в админке биллинга
     *
     * @param $value
     * @param string $type
     * @return array|bool
     */
    public function searchUsersMB($value, $type = 'login')
    {
        switch ($type) {
            case 'uid':
                return $this->searchByField('uid', 'uid', $value);
                break;
            case 'login':
                return $this->searchByField('user', 'user', $value);
                break;
            case 'numdogovor':
                return $this->searchByField('uid', 'numdogovor', $value);
                break;
            case 'phone':
                return $this->searchByPhone($value);
                break;
            case 'all':
                $results = array_merge(
                    $this->searchByField('uid', 'uid', $value),
                    $this->searchByField('user', 'user', $value),
                    $this->searchByField('uid', 'numdogovor', $value),
                    $this->searchByPhone($value)
                );

                $unique = [];
                foreach ($results as $user) {
                    if (isset($user['useruid'])) {
                        $unique[$user['useruid']] = $user;
                    }
                }

                return array_values($unique);
        }

        return false;
    }

    private function searchByPhone($phone)
    {
        $params = [
            "phone"                  => $phone,
            "search_normal_state"    => 0,
            "search_otkluchen_state" => 0,
            "search_frozen_state"    => 0,
            "search_deleted_state"   => 0,
            "search_all_states"      => 1,
            "op"                     => 1,
            "search_display_all"     => 0,
            "search_internet"        => 0,
            "ext_legal_person"       => 0,
            "ext_regular_person"     => 0
        ];
        $res = $this->getUsers($params);

        if (isset($res['success'], $res['data']) and $res['success'] == true and is_array($res['data'])) {
            return $res['data'];
        }

        return [];
    }

    private function searchByField($field, $key, $value)
    {
        $params = [
            $field                   => $value,
            "search_normal_state"    => 0,
            "search_otkluchen_state" => 0,
            "search_frozen_state"    => 0,
            "search_deleted_state"   => 0,
            "search_all_states"      => 1,
            "op"                     => 1,
            "search_display_all"     => 0,
            "search_internet"        => 0,
            "ext_legal_person"       => 0,
            "ext_regular_person"     => 0
        ];

        $res = $this->getUsers($params);

        if (isset($res['success'], $res['data']) and $res['success'] == true and is_array($res['data'])) {
            $users = [];
            foreach ($res['data'] as $user) {
                if ($user[$key] == $value) {
                    $users[] = $user;
                }
            }

            return $users;
        }

        return [];
    }

    public function getUserMB($uid)
    {
        $params = [
            'uid' => $uid
        ];
        $res = $this->getUser($params);

        if (isset($res['success'], $res['data']) and $res['success'] == true) {
            return $res['data'];
        }

        return false;
    }

}
